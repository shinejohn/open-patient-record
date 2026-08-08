<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * F4 remainder: patient intake forms. Templates are practice-defined
 * (name + JSON field list) and owner-authored; any practice member can list
 * templates and record a patient's answers, matching the roster/scheduling
 * routes' role posture.
 */
final class IntakeFormTest extends TestCase
{
    use RefreshDatabase;

    private const VAULT = 'https://vault.test';

    /** @return array{token: string, patient_id: string, staff_token: string} */
    private function ctx(): array
    {
        Http::fake([
            self::VAULT.'/api/users' => Http::response(['token' => 't', 'user' => ['id' => 'u1']], 201),
            self::VAULT.'/api/vaults' => Http::response(['id' => 'vault-uuid-1'], 201),
            self::VAULT.'/api/vaults/vault-uuid-1/grants' => Http::response(['pseudo_id' => 'p1', 'otp' => '12345678'], 201),
            self::VAULT.'/api/grants/redeem' => Http::response(['token' => 'g1'], 200),
            self::VAULT.'/api/fhir/vault-uuid-1/Patient' => Http::response(['resourceType' => 'Patient'], 201),
        ]);

        $p = $this->postJson('/api/register-practice', [
            'practice_name' => 'Riverbend', 'name' => 'Dr. O',
            'email' => 'o@r.test', 'password' => 'correct-horse-battery',
        ])->assertCreated();
        $token = (string) $p->json('token');

        $pid = $this->withToken($token)->postJson('/api/patients', ['name' => 'Ana'])
            ->assertCreated()->json('id');

        $staff = $this->withToken($token)->postJson('/api/staff', [
            'name' => 'Sam Staff', 'email' => 'sam@r.test',
            'password' => 'correct-horse-battery', 'role' => 'staff',
        ])->assertCreated();

        return ['token' => $token, 'patient_id' => $pid, 'staff_token' => (string) $staff->json('token')];
    }

    public function test_owner_creates_a_template_staff_cannot(): void
    {
        $c = $this->ctx();

        $this->withToken($c['token'])->postJson('/api/form-templates', [
            'name' => 'New Patient Intake',
            'fields' => [
                ['key' => 'allergies', 'label' => 'Allergies', 'type' => 'text', 'required' => false],
            ],
        ])->assertCreated()->assertJsonPath('name', 'New Patient Intake');

        $this->withToken($c['staff_token'])->postJson('/api/form-templates', [
            'name' => 'Blocked', 'fields' => [],
        ])->assertStatus(403);
    }

    public function test_staff_can_list_templates(): void
    {
        $c = $this->ctx();
        $this->withToken($c['token'])->postJson('/api/form-templates', [
            'name' => 'Intake', 'fields' => [['key' => 'dob', 'label' => 'DOB', 'type' => 'date', 'required' => true]],
        ])->assertCreated();

        $list = $this->withToken($c['staff_token'])->getJson('/api/form-templates')->assertOk();
        $this->assertCount(1, $list->json('templates'));
        $this->assertSame('Intake', $list->json('templates.0.name'));
    }

    public function test_owner_updates_a_template(): void
    {
        $c = $this->ctx();
        $id = $this->withToken($c['token'])->postJson('/api/form-templates', [
            'name' => 'V1', 'fields' => [],
        ])->assertCreated()->json('id');

        $this->withToken($c['token'])->patchJson("/api/form-templates/{$id}", [
            'name' => 'V2', 'fields' => [['key' => 'x', 'label' => 'X', 'type' => 'text', 'required' => false]],
        ])->assertOk()->assertJsonPath('name', 'V2');
    }

    public function test_intake_response_is_recorded_and_listed_per_patient(): void
    {
        $c = $this->ctx();
        $templateId = $this->withToken($c['token'])->postJson('/api/form-templates', [
            'name' => 'Intake', 'fields' => [['key' => 'allergies', 'label' => 'Allergies', 'type' => 'text', 'required' => false]],
        ])->assertCreated()->json('id');

        $this->withToken($c['staff_token'])->postJson("/api/patients/{$c['patient_id']}/intake-responses", [
            'template_id' => $templateId,
            'answers' => ['allergies' => 'Penicillin'],
        ])->assertCreated();

        $list = $this->withToken($c['token'])->getJson("/api/patients/{$c['patient_id']}/intake-responses")->assertOk();
        $this->assertCount(1, $list->json('responses'));
        $this->assertSame('Penicillin', $list->json('responses.0.answers.allergies'));
    }
}
