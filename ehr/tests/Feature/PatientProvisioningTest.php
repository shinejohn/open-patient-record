<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * F4 / E1 slice 1: practice onboarding + patient registration that provisions a
 * vault — the EHR backend as the first full Contributor consumer of the OPR
 * standard, coupled to the vault ONLY over its public HTTP API.
 *
 * The custody design under test (the part that must never regress):
 *
 *   Practice-created patients get a vault the practice CANNOT own. The bridge
 *   registers the subject with a random password that is never stored, mints a
 *   treatment AccessGrant, redeems it, and keeps ONLY the grant token. The
 *   subject credential is discarded — the patient claims their vault later via
 *   account recovery, and the practice's access remains what it always was: a
 *   scoped, revocable grant. If the practice kept the subject token it would
 *   BE the patient; a grant keeps it a guest with a key the patient controls.
 *
 * Vault HTTP calls are faked against the recorded contract (external I/O);
 * the orchestration logic is what's under test. A live two-app E2E script is
 * E4 work and these fakes must match reference-impl/server's real responses.
 */
final class PatientProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private const VAULT = 'https://vault.test';

    /** @return array{token: string} */
    private function practice(string $name = 'Riverbend Family Medicine'): array
    {
        $response = $this->postJson('/api/register-practice', [
            'practice_name' => $name,
            'name' => 'Dr. Owner',
            'email' => 'owner@riverbend.test',
            'password' => 'correct-horse-battery',
        ])->assertCreated();

        return ['token' => $response->json('token')];
    }

    private function fakeVaultProvisioning(): void
    {
        Http::fake([
            self::VAULT.'/api/users' => Http::response(['token' => 'subject-token-1', 'user' => ['id' => 'user-uuid-1']], 201),
            self::VAULT.'/api/vaults' => Http::response(['id' => 'vault-uuid-1'], 201),
            self::VAULT.'/api/vaults/vault-uuid-1/grants' => Http::response(['id' => 'grant-uuid-1', 'pseudo_id' => 'pseudo-1', 'otp' => '12345678'], 201),
            self::VAULT.'/api/grants/redeem' => Http::response(['token' => 'grant-token-1'], 200),
            self::VAULT.'/api/fhir/vault-uuid-1/Patient' => Http::response(['resourceType' => 'Patient', 'id' => 'entry-1'], 201),
            self::VAULT.'/api/fhir/vault-uuid-1/Patient/$everything' => Http::response(['resourceType' => 'Bundle', 'type' => 'searchset', 'total' => 1], 200),
        ]);
    }

    public function test_practice_registration_creates_owner_and_returns_token(): void
    {
        $p = $this->practice();
        $this->assertNotEmpty($p['token']);
        $this->assertDatabaseHas('practices', ['name' => 'Riverbend Family Medicine']);
        $this->assertDatabaseHas('users', ['email' => 'owner@riverbend.test', 'role' => 'owner']);
    }

    public function test_registering_a_patient_provisions_a_vault_and_keeps_only_the_grant(): void
    {
        $this->fakeVaultProvisioning();
        $p = $this->practice();

        $created = $this->withToken($p['token'])
            ->postJson('/api/patients', [
                'name' => 'Ana Rivera',
                'email' => 'ana@example.test',
                'birth_date' => '1987-03-14',
                'gender' => 'female',
            ])
            ->assertCreated();

        $this->assertSame('vault-uuid-1', $created->json('vault_id'));

        // Orchestration hit the vault's public API in contract order.
        Http::assertSent(fn ($req) => str_contains($req->url(), '/api/users'));
        Http::assertSent(fn ($req) => str_ends_with($req->url(), '/api/vaults'));
        Http::assertSent(fn ($req) => str_contains($req->url(), '/grants') && ($req['purpose'] ?? null) === 'treatment');
        Http::assertSent(fn ($req) => str_contains($req->url(), '/grants/redeem') && ($req['otp'] ?? null) === '12345678');

        // Demographics committed through the FHIR door, attributed to the practice.
        Http::assertSent(fn ($req) => str_contains($req->url(), '/fhir/vault-uuid-1/Patient')
            && $req->hasHeader('X-OPR-Organization', 'Riverbend Family Medicine')
            && ($req['birthDate'] ?? null) === '1987-03-14');

        // ONLY the grant token is kept — never the subject credential.
        $row = DB::table('patients')->first();
        $this->assertNotNull($row->grant_token);
        $this->assertStringNotContainsString('subject-token-1', (string) $row->grant_token);
        $this->assertFalse(property_exists($row, 'subject_token'));
    }

    public function test_grant_token_and_demographics_are_encrypted_at_rest(): void
    {
        $this->fakeVaultProvisioning();
        $p = $this->practice();

        $this->withToken($p['token'])->postJson('/api/patients', [
            'name' => 'Ana Rivera',
            'email' => 'ana@example.test',
        ])->assertCreated();

        $row = DB::table('patients')->first();
        $this->assertNotSame('grant-token-1', $row->grant_token);
        $this->assertNotSame('Ana Rivera', $row->name);
        $this->assertNotSame('ana@example.test', $row->email);
    }

    public function test_vault_failure_rolls_back_and_reports_honestly(): void
    {
        Http::fake([self::VAULT.'/api/users' => Http::response(['error' => 'boom'], 500)]);
        $p = $this->practice();

        $this->withToken($p['token'])
            ->postJson('/api/patients', ['name' => 'Ana Rivera'])
            ->assertStatus(502)
            ->assertJsonPath('error', 'vault_unreachable');

        $this->assertSame(0, DB::table('patients')->count());
    }

    public function test_chart_proxies_the_vaults_everything_under_the_grant(): void
    {
        $this->fakeVaultProvisioning();
        $p = $this->practice();

        $id = $this->withToken($p['token'])
            ->postJson('/api/patients', ['name' => 'Ana Rivera'])
            ->assertCreated()
            ->json('id');

        $chart = $this->withToken($p['token'])
            ->getJson("/api/patients/{$id}/chart")
            ->assertOk()
            ->assertJsonPath('resourceType', 'Bundle');

        Http::assertSent(fn ($req) => str_contains($req->url(), '/Patient/$everything')
            && $req->header('Authorization')[0] === 'Bearer grant-token-1');
    }

    public function test_patients_are_scoped_to_their_practice(): void
    {
        $this->fakeVaultProvisioning();
        $a = $this->practice('Practice A');
        $id = $this->withToken($a['token'])
            ->postJson('/api/patients', ['name' => 'Ana Rivera'])
            ->assertCreated()->json('id');

        $b = $this->postJson('/api/register-practice', [
            'practice_name' => 'Practice B',
            'name' => 'Dr. Other',
            'email' => 'other@b.test',
            'password' => 'correct-horse-battery',
        ])->assertCreated();

        $this->withToken($b->json('token'))
            ->getJson("/api/patients/{$id}/chart")
            ->assertStatus(404); // invisible, not just forbidden — no cross-practice oracle

        $this->withToken($b->json('token'))
            ->getJson('/api/patients')
            ->assertOk()
            ->assertJsonCount(0, 'patients');
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->postJson('/api/patients', ['name' => 'X'])->assertStatus(401);
        $this->getJson('/api/patients')->assertStatus(401);
    }
}
