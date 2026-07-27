<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * F4 / E2: staff & roles, scheduling, encounters (SOAP → sign → vault commit),
 * and linking an already-vaulted patient.
 *
 * Role policy (minimal, deliberate): owner/clinician get clinical surfaces;
 * staff get scheduling and the roster list — no chart, no patient creation,
 * no encounters. Signing commits Encounter + DocumentReference to the vault in
 * ONE transaction Bundle (all-or-nothing — F1's Bundle endpoint earning its
 * keep); a signed encounter is locally immutable; a vault failure leaves the
 * encounter honestly in draft. Linking is the answer to 409 already_registered:
 * the PATIENT mints a grant on their existing vault and hands the practice the
 * redemption secret — custody stays with the patient, the practice stays a
 * guest.
 */
final class ClinicalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private const VAULT = 'https://vault.test';

    /** @return array{token: string} */
    private function practice(string $email = 'owner@riverbend.test'): array
    {
        $response = $this->postJson('/api/register-practice', [
            'practice_name' => 'Riverbend Family Medicine',
            'name' => 'Dr. Owner',
            'email' => $email,
            'password' => 'correct-horse-battery',
        ])->assertCreated();

        return ['token' => $response->json('token')];
    }

    private function fakeVault(): void
    {
        Http::fake([
            self::VAULT.'/api/users' => Http::response(['token' => 'subject-token-1', 'user' => ['id' => 'user-uuid-1']], 201),
            self::VAULT.'/api/vaults' => Http::response(['id' => 'vault-uuid-1'], 201),
            self::VAULT.'/api/vaults/vault-uuid-1/grants' => Http::response(['pseudo_id' => 'pseudo-1', 'otp' => '12345678'], 201),
            self::VAULT.'/api/grants/redeem' => Http::response(['token' => 'grant-token-1'], 200),
            self::VAULT.'/api/fhir/vault-uuid-1/Patient' => Http::response(['resourceType' => 'Patient'], 201),
            self::VAULT.'/api/fhir/vault-uuid-1/Patient/$everything' => Http::response(['resourceType' => 'Bundle', 'type' => 'searchset'], 200),
            self::VAULT.'/api/fhir/vault-uuid-1' => Http::response([
                'resourceType' => 'Bundle', 'type' => 'transaction-response',
                'entry' => [
                    ['response' => ['status' => '201 Created'], 'resource' => ['resourceType' => 'Encounter', 'id' => 'enc-entry-1']],
                    ['response' => ['status' => '201 Created'], 'resource' => ['resourceType' => 'DocumentReference', 'id' => 'doc-entry-1']],
                ],
            ], 200),
        ]);
    }

    /** @return array{token: string, patient_id: string} */
    private function practiceWithPatient(): array
    {
        $this->fakeVault();
        $p = $this->practice();
        $id = $this->withToken($p['token'])->postJson('/api/patients', ['name' => 'Ana Rivera'])
            ->assertCreated()->json('id');

        return ['token' => $p['token'], 'patient_id' => $id];
    }

    // ---- Staff & roles ----------------------------------------------------

    public function test_owner_adds_staff_and_staff_is_scheduling_only(): void
    {
        $ctx = $this->practiceWithPatient();

        $staff = $this->withToken($ctx['token'])->postJson('/api/staff', [
            'name' => 'Front Desk', 'email' => 'desk@riverbend.test',
            'password' => 'correct-horse-battery', 'role' => 'staff',
        ])->assertCreated();
        $staffToken = $staff->json('token');

        // Staff CAN see the roster and book.
        $this->withToken($staffToken)->getJson('/api/patients')->assertOk();
        $this->withToken($staffToken)->postJson('/api/appointments', [
            'patient_id' => $ctx['patient_id'],
            'starts_at' => '2026-08-03T09:00:00Z', 'ends_at' => '2026-08-03T09:30:00Z',
        ])->assertCreated();

        // Staff can NOT touch clinical surfaces.
        $this->withToken($staffToken)->postJson('/api/patients', ['name' => 'X'])->assertStatus(403);
        $this->withToken($staffToken)->getJson("/api/patients/{$ctx['patient_id']}/chart")->assertStatus(403);
        $this->withToken($staffToken)->postJson("/api/patients/{$ctx['patient_id']}/encounters", [
            'subjective' => 's',
        ])->assertStatus(403);
    }

    public function test_only_the_owner_manages_staff(): void
    {
        $ctx = $this->practiceWithPatient();
        $clin = $this->withToken($ctx['token'])->postJson('/api/staff', [
            'name' => 'Dr. C', 'email' => 'c@riverbend.test',
            'password' => 'correct-horse-battery', 'role' => 'clinician',
        ])->assertCreated();

        $this->withToken($clin->json('token'))->postJson('/api/staff', [
            'name' => 'Evil', 'email' => 'e@riverbend.test',
            'password' => 'correct-horse-battery', 'role' => 'owner',
        ])->assertStatus(403);
    }

    // ---- Scheduling -------------------------------------------------------

    public function test_appointments_are_practice_scoped_and_listed_by_date(): void
    {
        $ctx = $this->practiceWithPatient();
        $this->withToken($ctx['token'])->postJson('/api/appointments', [
            'patient_id' => $ctx['patient_id'],
            'starts_at' => '2026-08-03T09:00:00Z', 'ends_at' => '2026-08-03T09:30:00Z',
            'reason' => 'follow-up',
        ])->assertCreated();

        $day = $this->withToken($ctx['token'])->getJson('/api/appointments?date=2026-08-03')->assertOk();
        $this->assertCount(1, $day->json('appointments'));
        $other = $this->withToken($ctx['token'])->getJson('/api/appointments?date=2026-08-04')->assertOk();
        $this->assertCount(0, $other->json('appointments'));
    }

    public function test_overlapping_appointments_for_one_clinician_are_refused(): void
    {
        $ctx = $this->practiceWithPatient();
        $mk = fn (string $s, string $e) => $this->withToken($ctx['token'])->postJson('/api/appointments', [
            'patient_id' => $ctx['patient_id'], 'starts_at' => $s, 'ends_at' => $e,
        ]);

        $mk('2026-08-03T09:00:00Z', '2026-08-03T09:30:00Z')->assertCreated();
        $mk('2026-08-03T09:15:00Z', '2026-08-03T09:45:00Z')->assertStatus(422); // overlaps
        $mk('2026-08-03T09:30:00Z', '2026-08-03T10:00:00Z')->assertCreated();   // abuts = fine
    }

    // ---- Encounters: SOAP → sign → vault ---------------------------------

    public function test_encounter_drafts_then_signs_into_the_vault_as_one_transaction(): void
    {
        $ctx = $this->practiceWithPatient();

        $enc = $this->withToken($ctx['token'])->postJson("/api/patients/{$ctx['patient_id']}/encounters", [
            'subjective' => 'Headache for 3 days.',
            'objective' => 'BP 120/80.',
        ])->assertCreated();
        $encId = $enc->json('id');
        $this->assertSame('draft', $enc->json('status'));

        $this->withToken($ctx['token'])->patchJson("/api/encounters/{$encId}", [
            'assessment' => 'Tension headache.',
            'plan' => 'Hydration; ibuprofen PRN.',
        ])->assertOk();

        $signed = $this->withToken($ctx['token'])->postJson("/api/encounters/{$encId}/sign")->assertOk();
        $this->assertSame('signed', $signed->json('status'));

        // ONE transaction Bundle carrying Encounter + DocumentReference (the
        // SOAP note, base64) — all-or-nothing on the vault chain.
        Http::assertSent(function ($req) {
            if (! str_ends_with(parse_url($req->url(), PHP_URL_PATH), '/api/fhir/vault-uuid-1')) {
                return false;
            }
            $body = $req->data();
            $types = array_map(fn ($e) => $e['resource']['resourceType'] ?? null, $body['entry'] ?? []);
            $note = base64_decode($body['entry'][1]['resource']['content'][0]['attachment']['data'] ?? '', true);

            return ($body['type'] ?? null) === 'transaction'
                && $types === ['Encounter', 'DocumentReference']
                && is_string($note) && str_contains($note, 'Tension headache.');
        });
    }

    public function test_signed_encounters_are_immutable_locally(): void
    {
        $ctx = $this->practiceWithPatient();
        $encId = $this->withToken($ctx['token'])->postJson("/api/patients/{$ctx['patient_id']}/encounters", [
            'subjective' => 's',
        ])->assertCreated()->json('id');
        $this->withToken($ctx['token'])->postJson("/api/encounters/{$encId}/sign")->assertOk();

        $this->withToken($ctx['token'])->patchJson("/api/encounters/{$encId}", ['plan' => 'edit after signing'])
            ->assertStatus(409);
    }

    public function test_vault_failure_on_sign_leaves_the_encounter_in_draft(): void
    {
        // All fakes declared up front (stacked Http::fake precedence would let
        // an earlier success stub win over a later failure stub).
        Http::fake([
            self::VAULT.'/api/users' => Http::response(['token' => 'subject-token-1', 'user' => ['id' => 'user-uuid-1']], 201),
            self::VAULT.'/api/vaults' => Http::response(['id' => 'vault-uuid-1'], 201),
            self::VAULT.'/api/vaults/vault-uuid-1/grants' => Http::response(['pseudo_id' => 'pseudo-1', 'otp' => '12345678'], 201),
            self::VAULT.'/api/grants/redeem' => Http::response(['token' => 'grant-token-1'], 200),
            self::VAULT.'/api/fhir/vault-uuid-1/Patient' => Http::response(['resourceType' => 'Patient'], 201),
            self::VAULT.'/api/fhir/vault-uuid-1' => Http::response(['error' => 'boom'], 500),
        ]);
        $p = $this->practice();
        $pid = $this->withToken($p['token'])->postJson('/api/patients', ['name' => 'Ana'])->assertCreated()->json('id');
        $encId = $this->withToken($p['token'])->postJson("/api/patients/{$pid}/encounters", ['subjective' => 's'])
            ->assertCreated()->json('id');

        $this->withToken($p['token'])->postJson("/api/encounters/{$encId}/sign")->assertStatus(502);
        $this->assertSame('draft', DB::table('encounters')->where('id', $encId)->value('status'));
    }

    public function test_sign_re_redeems_an_expired_token_and_retries(): void
    {
        Http::fake([
            self::VAULT.'/api/users' => Http::response(['token' => 'subject-token-1', 'user' => ['id' => 'user-uuid-1']], 201),
            self::VAULT.'/api/vaults' => Http::response(['id' => 'vault-uuid-1'], 201),
            self::VAULT.'/api/vaults/vault-uuid-1/grants' => Http::response(['pseudo_id' => 'pseudo-1', 'otp' => '12345678'], 201),
            self::VAULT.'/api/grants/redeem' => Http::sequence()
                ->push(['token' => 'grant-token-1'], 200)
                ->push(['token' => 'grant-token-2'], 200),
            self::VAULT.'/api/fhir/vault-uuid-1/Patient' => Http::response(['resourceType' => 'Patient'], 201),
            self::VAULT.'/api/fhir/vault-uuid-1' => Http::sequence()
                ->push(['error' => 'unauthenticated'], 401)
                ->push(['resourceType' => 'Bundle', 'type' => 'transaction-response', 'entry' => []], 200),
        ]);
        $p = $this->practice();
        $pid = $this->withToken($p['token'])->postJson('/api/patients', ['name' => 'Ana'])->assertCreated()->json('id');
        $encId = $this->withToken($p['token'])->postJson("/api/patients/{$pid}/encounters", ['subjective' => 's'])
            ->assertCreated()->json('id');

        $this->withToken($p['token'])->postJson("/api/encounters/{$encId}/sign")->assertOk();
        Http::assertSent(fn ($req) => str_ends_with(parse_url($req->url(), PHP_URL_PATH), '/api/fhir/vault-uuid-1')
            && $req->header('Authorization')[0] === 'Bearer grant-token-2');
    }

    // ---- Linking an existing vault ---------------------------------------

    public function test_linking_an_existing_vault_via_a_patient_minted_grant(): void
    {
        Http::fake([
            self::VAULT.'/api/grants/redeem' => Http::response(['token' => 'linked-token-1'], 200),
            self::VAULT.'/api/fhir/existing-vault-9/Patient/$everything' => Http::response(['resourceType' => 'Bundle', 'type' => 'searchset'], 200),
        ]);
        $p = $this->practice();

        $linked = $this->withToken($p['token'])->postJson('/api/patients/link', [
            'name' => 'Ana Rivera',
            'vault_id' => 'existing-vault-9',
            'pseudo_id' => 'their-pseudo',
            'otp' => '87654321',
        ])->assertCreated();

        $this->assertSame('existing-vault-9', $linked->json('vault_id'));

        // Chart works under the linked grant.
        $this->withToken($p['token'])->getJson("/api/patients/{$linked->json('id')}/chart")
            ->assertOk()->assertJsonPath('resourceType', 'Bundle');
    }

    public function test_linking_with_a_bad_secret_stores_nothing(): void
    {
        Http::fake([self::VAULT.'/api/grants/redeem' => Http::response(['error' => 'forbidden'], 403)]);
        $p = $this->practice();

        $this->withToken($p['token'])->postJson('/api/patients/link', [
            'name' => 'Ana Rivera', 'vault_id' => 'existing-vault-9',
            'pseudo_id' => 'their-pseudo', 'otp' => '00000000',
        ])->assertStatus(422)->assertJsonPath('error', 'grant_redemption_failed');

        $this->assertSame(0, DB::table('patients')->count());
    }
}
