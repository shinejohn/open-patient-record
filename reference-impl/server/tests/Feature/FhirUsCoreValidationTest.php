<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsVaults;
use Tests\TestCase;

/**
 * US Core required-element + required-binding validation (bounded — see
 * FhirResourceRegistry::usCoreConformanceViolations doc for exact scope).
 *
 * This only engages when the CLIENT asserts a US Core profile via
 * meta.profile; an unasserted create keeps the pre-existing presence-level
 * badge-only behavior (covered by FhirDemographicsAndProfilesTest, which
 * this file must not break).
 */
final class FhirUsCoreValidationTest extends TestCase
{
    use BuildsVaults;
    use RefreshDatabase;

    private const CONDITION_PROFILE = 'http://hl7.org/fhir/us/core/StructureDefinition/us-core-condition';
    private const OBSERVATION_PROFILE = 'http://hl7.org/fhir/us/core/StructureDefinition/us-core-observation-lab';

    public function test_conformant_resource_asserting_a_us_core_profile_is_accepted(): void
    {
        $s = $this->subjectWithVault();

        $this->withToken($s['token'])
            ->postJson("/api/fhir/{$s['vault_id']}/Condition", [
                'resourceType' => 'Condition',
                'meta' => ['profile' => [self::CONDITION_PROFILE]],
                'subject' => ['reference' => "Patient/{$s['vault_id']}"],
                'code' => ['coding' => [['system' => 'http://snomed.info/sct', 'code' => '38341003']]],
                'category' => [['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/condition-category', 'code' => 'problem-list-item']]]],
            ])
            ->assertCreated();
    }

    public function test_resource_asserting_a_us_core_profile_but_missing_a_required_element_is_rejected(): void
    {
        $s = $this->subjectWithVault();

        $response = $this->withToken($s['token'])
            ->postJson("/api/fhir/{$s['vault_id']}/Condition", [
                'resourceType' => 'Condition',
                'meta' => ['profile' => [self::CONDITION_PROFILE]],
                'subject' => ['reference' => "Patient/{$s['vault_id']}"],
                'code' => ['coding' => [['system' => 'http://snomed.info/sct', 'code' => '38341003']]],
                // category omitted — required by us-core-condition
            ])
            ->assertStatus(422)
            ->assertJsonPath('resourceType', 'OperationOutcome');

        $this->assertStringContainsString('Condition.category', $response->json('issue.0.diagnostics'));
    }

    public function test_resource_asserting_a_us_core_profile_with_an_out_of_binding_status_code_is_rejected(): void
    {
        $s = $this->subjectWithVault();

        $response = $this->withToken($s['token'])
            ->postJson("/api/fhir/{$s['vault_id']}/Observation", [
                'resourceType' => 'Observation',
                'meta' => ['profile' => [self::OBSERVATION_PROFILE]],
                'status' => 'not-a-real-status',
                'category' => [['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/observation-category', 'code' => 'laboratory']]]],
                'code' => ['coding' => [['system' => 'http://loinc.org', 'code' => '2339-0']]],
            ])
            ->assertStatus(422)
            ->assertJsonPath('resourceType', 'OperationOutcome');

        $this->assertStringContainsString('Observation.status', $response->json('issue.0.diagnostics'));
        $this->assertStringContainsString('required binding', $response->json('issue.0.diagnostics'));
    }

    public function test_a_valid_us_core_binding_status_is_accepted(): void
    {
        $s = $this->subjectWithVault();

        $this->withToken($s['token'])
            ->postJson("/api/fhir/{$s['vault_id']}/Observation", [
                'resourceType' => 'Observation',
                'meta' => ['profile' => [self::OBSERVATION_PROFILE]],
                'status' => 'final',
                'category' => [['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/observation-category', 'code' => 'laboratory']]]],
                'code' => ['coding' => [['system' => 'http://loinc.org', 'code' => '2339-0']]],
            ])
            ->assertCreated();
    }

    public function test_a_create_that_does_not_assert_a_us_core_profile_is_never_rejected_for_lacking_extras(): void
    {
        $s = $this->subjectWithVault();

        // No meta.profile asserted — the pre-existing presence-level badge
        // behavior applies (FhirDemographicsAndProfilesTest); this must still
        // succeed even though it doesn't earn the US Core badge.
        $this->withToken($s['token'])
            ->postJson("/api/fhir/{$s['vault_id']}/Condition", [
                'resourceType' => 'Condition',
                'subject' => ['reference' => "Patient/{$s['vault_id']}"],
            ])
            ->assertCreated();
    }
}
