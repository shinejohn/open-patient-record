<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Structured clinical capture: vitals, problems, medications, allergies,
 * immunizations. Every write commits through the vault's FHIR door (never a
 * local copy) and a vault failure is surfaced honestly — mirrors
 * ClinicalWorkflowTest's encounter-signing coverage.
 */
final class StructuredClinicalDataTest extends TestCase
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
            self::VAULT.'/api/fhir/vault-uuid-1/Patient/$everything' => Http::response([
                'resourceType' => 'Bundle', 'type' => 'searchset',
                'entry' => [
                    ['resource' => ['resourceType' => 'Observation', 'id' => 'obs-1']],
                    ['resource' => ['resourceType' => 'Condition', 'id' => 'cond-1']],
                    ['resource' => ['resourceType' => 'MedicationStatement', 'id' => 'med-1']],
                    ['resource' => ['resourceType' => 'AllergyIntolerance', 'id' => 'allergy-1']],
                    ['resource' => ['resourceType' => 'Immunization', 'id' => 'imm-1']],
                ],
            ], 200),
            self::VAULT.'/api/fhir/vault-uuid-1/Condition' => Http::response(['resourceType' => 'Condition', 'id' => 'cond-1'], 201),
            self::VAULT.'/api/fhir/vault-uuid-1/MedicationStatement' => Http::response(['resourceType' => 'MedicationStatement', 'id' => 'med-1'], 201),
            self::VAULT.'/api/fhir/vault-uuid-1/AllergyIntolerance' => Http::response(['resourceType' => 'AllergyIntolerance', 'id' => 'allergy-1'], 201),
            self::VAULT.'/api/fhir/vault-uuid-1/Immunization' => Http::response(['resourceType' => 'Immunization', 'id' => 'imm-1'], 201),
            self::VAULT.'/api/fhir/vault-uuid-1' => Http::response([
                'resourceType' => 'Bundle', 'type' => 'transaction-response',
                'entry' => [
                    ['response' => ['status' => '201 Created'], 'resource' => ['resourceType' => 'Observation', 'id' => 'obs-1']],
                    ['response' => ['status' => '201 Created'], 'resource' => ['resourceType' => 'Observation', 'id' => 'obs-2']],
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

    public function test_vitals_are_committed_as_one_transaction_bundle(): void
    {
        $ctx = $this->practiceWithPatient();

        $res = $this->withToken($ctx['token'])->postJson("/api/patients/{$ctx['patient_id']}/vitals", [
            'bp_systolic' => 120,
            'bp_diastolic' => 80,
            'heart_rate' => 72,
        ])->assertCreated();

        $this->assertTrue($res->json('committed'));
        $this->assertSame(['obs-1', 'obs-2'], $res->json('ids'));

        Http::assertSent(function ($req) {
            if (! str_ends_with(parse_url($req->url(), PHP_URL_PATH), '/api/fhir/vault-uuid-1')) {
                return false;
            }
            $body = $req->data();
            if (($body['type'] ?? null) !== 'transaction') {
                return false;
            }
            $codes = array_map(fn ($e) => $e['resource']['code']['coding'][0]['code'] ?? null, $body['entry'] ?? []);

            return in_array('8480-6', $codes, true) && in_array('8462-4', $codes, true) && in_array('8867-4', $codes, true);
        });

        // Read-back: the chart's $everything includes the vital-sign Observations.
        $chart = $this->withToken($ctx['token'])->getJson("/api/patients/{$ctx['patient_id']}/chart")->assertOk();
        $types = array_map(fn ($e) => $e['resource']['resourceType'] ?? null, $chart->json('entry') ?? []);
        $this->assertContains('Observation', $types);
    }

    public function test_vitals_requires_at_least_one_value(): void
    {
        $ctx = $this->practiceWithPatient();

        $this->withToken($ctx['token'])->postJson("/api/patients/{$ctx['patient_id']}/vitals", [])
            ->assertStatus(422)->assertJsonPath('error', 'no_vitals');
    }

    public function test_a_problem_is_recorded_as_a_condition_and_readable_from_the_chart(): void
    {
        $ctx = $this->practiceWithPatient();

        $res = $this->withToken($ctx['token'])->postJson("/api/patients/{$ctx['patient_id']}/problems", [
            'icd10_code' => 'J06.9',
            'display' => 'Acute upper respiratory infection',
            'clinical_status' => 'active',
        ])->assertCreated();
        $this->assertSame('Condition', $res->json('type'));

        Http::assertSent(fn ($req) => str_ends_with(parse_url($req->url(), PHP_URL_PATH), '/api/fhir/vault-uuid-1/Condition')
            && ($req->data()['code']['coding'][0]['code'] ?? null) === 'J06.9'
            && ($req->data()['clinicalStatus']['coding'][0]['code'] ?? null) === 'active');

        $chart = $this->withToken($ctx['token'])->getJson("/api/patients/{$ctx['patient_id']}/chart")->assertOk();
        $types = array_map(fn ($e) => $e['resource']['resourceType'] ?? null, $chart->json('entry') ?? []);
        $this->assertContains('Condition', $types);
    }

    public function test_a_medication_is_recorded_as_a_medication_statement(): void
    {
        $ctx = $this->practiceWithPatient();

        $res = $this->withToken($ctx['token'])->postJson("/api/patients/{$ctx['patient_id']}/medications", [
            'medication_display' => 'Amoxicillin 500mg',
            'rxnorm_code' => '308191',
            'dosage_text' => '1 tablet TID',
        ])->assertCreated();
        $this->assertSame('MedicationStatement', $res->json('type'));

        Http::assertSent(fn ($req) => str_ends_with(parse_url($req->url(), PHP_URL_PATH), '/api/fhir/vault-uuid-1/MedicationStatement')
            && ($req->data()['status'] ?? null) === 'active'
            && ($req->data()['medicationCodeableConcept']['coding'][0]['code'] ?? null) === '308191');
    }

    public function test_an_allergy_is_recorded_as_an_allergy_intolerance(): void
    {
        $ctx = $this->practiceWithPatient();

        $res = $this->withToken($ctx['token'])->postJson("/api/patients/{$ctx['patient_id']}/allergies", [
            'substance_display' => 'Penicillin',
            'reaction' => 'Hives',
            'criticality' => 'high',
        ])->assertCreated();
        $this->assertSame('AllergyIntolerance', $res->json('type'));

        Http::assertSent(fn ($req) => str_ends_with(parse_url($req->url(), PHP_URL_PATH), '/api/fhir/vault-uuid-1/AllergyIntolerance')
            && ($req->data()['patient']['reference'] ?? null) === 'Patient/vault-uuid-1'
            && ($req->data()['criticality'] ?? null) === 'high'
            && ($req->data()['reaction'][0]['manifestation'][0]['text'] ?? null) === 'Hives');
    }

    public function test_an_immunization_is_recorded_with_cvx_code_and_date(): void
    {
        $ctx = $this->practiceWithPatient();

        $res = $this->withToken($ctx['token'])->postJson("/api/patients/{$ctx['patient_id']}/immunizations", [
            'cvx_code' => '208',
            'vaccine_display' => 'COVID-19 vaccine',
            'occurrence_date' => '2026-01-15',
        ])->assertCreated();
        $this->assertSame('Immunization', $res->json('type'));

        Http::assertSent(fn ($req) => str_ends_with(parse_url($req->url(), PHP_URL_PATH), '/api/fhir/vault-uuid-1/Immunization')
            && ($req->data()['vaccineCode']['coding'][0]['code'] ?? null) === '208'
            && ($req->data()['occurrenceDateTime'] ?? null) === '2026-01-15');
    }

    public function test_a_vault_failure_on_a_problem_write_is_surfaced_honestly(): void
    {
        Http::fake([
            self::VAULT.'/api/users' => Http::response(['token' => 'subject-token-1', 'user' => ['id' => 'user-uuid-1']], 201),
            self::VAULT.'/api/vaults' => Http::response(['id' => 'vault-uuid-1'], 201),
            self::VAULT.'/api/vaults/vault-uuid-1/grants' => Http::response(['pseudo_id' => 'pseudo-1', 'otp' => '12345678'], 201),
            self::VAULT.'/api/grants/redeem' => Http::response(['token' => 'grant-token-1'], 200),
            self::VAULT.'/api/fhir/vault-uuid-1/Patient' => Http::response(['resourceType' => 'Patient'], 201),
            self::VAULT.'/api/fhir/vault-uuid-1/Condition' => Http::response(['error' => 'boom'], 500),
        ]);
        $p = $this->practice();
        $pid = $this->withToken($p['token'])->postJson('/api/patients', ['name' => 'Ana'])->assertCreated()->json('id');

        $this->withToken($p['token'])->postJson("/api/patients/{$pid}/problems", [
            'icd10_code' => 'J06.9',
            'display' => 'URI',
        ])->assertStatus(502)->assertJsonPath('error', 'vault_unreachable');
    }

    public function test_staff_role_cannot_record_clinical_data(): void
    {
        $ctx = $this->practiceWithPatient();
        $staff = $this->withToken($ctx['token'])->postJson('/api/staff', [
            'name' => 'Front Desk', 'email' => 'desk@riverbend.test',
            'password' => 'correct-horse-battery', 'role' => 'staff',
        ])->assertCreated();

        $this->withToken($staff->json('token'))->postJson("/api/patients/{$ctx['patient_id']}/vitals", [
            'heart_rate' => 70,
        ])->assertStatus(403);
    }
}
