<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsVaults;
use Tests\TestCase;

/**
 * F1: real Patient demographics + presence-level US Core profile stamping.
 *
 * Demographics: a committed Patient entry (current view) becomes the
 * $everything anchor — the vault stops synthesizing a name-only Patient the
 * moment real demographics are contributed. The anchor keeps the VAULT id
 * (it is the join point every subject reference in the record points at).
 *
 * US Core: meta.profile is stamped on read when a resource carries the
 * elements the corresponding US Core profile requires — PRESENCE-level
 * checking only, deliberately: it makes the claim "this resource is
 * US-Core-shaped" without pretending to be a binding/slicing validator.
 * A resource missing those elements gets no profile claim at all — an
 * honest absence, not a false badge.
 */
final class FhirDemographicsAndProfilesTest extends TestCase
{
    use BuildsVaults;
    use RefreshDatabase;

    public function test_committed_patient_entry_becomes_the_everything_anchor(): void
    {
        $s = $this->subjectWithVault();

        $this->withToken($s['token'])
            ->postJson("/api/fhir/{$s['vault_id']}/Patient", [
                'resourceType' => 'Patient',
                'name' => [['family' => 'Rivera', 'given' => ['Ana']]],
                'gender' => 'female',
                'birthDate' => '1987-03-14',
                'identifier' => [['system' => 'urn:example:mrn', 'value' => 'MRN-001']],
            ])
            ->assertCreated();

        $bundle = $this->withToken($s['token'])
            ->getJson("/api/fhir/{$s['vault_id']}/Patient/\$everything")
            ->assertOk();

        $anchor = $bundle->json('entry.0.resource');
        $this->assertSame('Patient', $anchor['resourceType']);
        $this->assertSame($s['vault_id'], $anchor['id']); // the join point keeps the vault id
        $this->assertSame('1987-03-14', $anchor['birthDate']);
        $this->assertSame('female', $anchor['gender']);
        $this->assertSame('Rivera', $anchor['name'][0]['family']);
    }

    public function test_without_a_patient_entry_the_anchor_is_still_synthesized(): void
    {
        $s = $this->subjectWithVault();
        $this->commitEntry($s['token'], $s['vault_id'])->assertCreated();

        $bundle = $this->withToken($s['token'])
            ->getJson("/api/fhir/{$s['vault_id']}/Patient/\$everything")
            ->assertOk();

        $anchor = $bundle->json('entry.0.resource');
        $this->assertSame('Patient', $anchor['resourceType']);
        $this->assertSame($s['vault_id'], $anchor['id']);
        $this->assertArrayHasKey('name', $anchor);
    }

    public function test_a_superseding_patient_entry_wins(): void
    {
        $s = $this->subjectWithVault();

        $first = $this->withToken($s['token'])
            ->postJson("/api/fhir/{$s['vault_id']}/Patient", [
                'resourceType' => 'Patient',
                'name' => [['family' => 'Rivera']],
                'birthDate' => '1987-03-14',
            ])
            ->assertCreated();

        // Correction via the native envelope (supersession).
        $this->commitEntry($s['token'], $s['vault_id'], [
            'resource_type' => 'Patient',
            'payload' => ['resourceType' => 'Patient', 'name' => [['family' => 'Rivera-Gomez']], 'birthDate' => '1987-03-15'],
            'replaces_entry_id' => $first->json('id'),
        ])->assertCreated();

        $bundle = $this->withToken($s['token'])
            ->getJson("/api/fhir/{$s['vault_id']}/Patient/\$everything")
            ->assertOk();

        $this->assertSame('1987-03-15', $bundle->json('entry.0.resource.birthDate'));
        $this->assertSame('Rivera-Gomez', $bundle->json('entry.0.resource.name.0.family'));
    }

    public function test_condition_with_category_and_code_is_stamped_us_core(): void
    {
        $s = $this->subjectWithVault();

        $stamped = $this->withToken($s['token'])
            ->postJson("/api/fhir/{$s['vault_id']}/Condition", [
                'resourceType' => 'Condition',
                'subject' => ['reference' => "Patient/{$s['vault_id']}"],
                'code' => ['coding' => [['system' => 'http://snomed.info/sct', 'code' => '38341003']]],
                'category' => [['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/condition-category', 'code' => 'problem-list-item']]]],
            ])
            ->assertCreated();

        $this->assertContains(
            'http://hl7.org/fhir/us/core/StructureDefinition/us-core-condition',
            $stamped->json('meta.profile'),
        );
    }

    public function test_condition_without_category_gets_no_profile_claim(): void
    {
        $s = $this->subjectWithVault();

        $bare = $this->withToken($s['token'])
            ->postJson("/api/fhir/{$s['vault_id']}/Condition", [
                'resourceType' => 'Condition',
                'subject' => ['reference' => "Patient/{$s['vault_id']}"],
            ])
            ->assertCreated();

        $this->assertNull($bare->json('meta.profile'));
    }

    public function test_lab_observation_is_stamped_us_core_lab(): void
    {
        $s = $this->subjectWithVault();

        $lab = $this->withToken($s['token'])
            ->postJson("/api/fhir/{$s['vault_id']}/Observation", [
                'resourceType' => 'Observation',
                'status' => 'final',
                'category' => [['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/observation-category', 'code' => 'laboratory']]]],
                'code' => ['coding' => [['system' => 'http://loinc.org', 'code' => '2339-0']]],
            ])
            ->assertCreated();

        $this->assertContains(
            'http://hl7.org/fhir/us/core/StructureDefinition/us-core-observation-lab',
            $lab->json('meta.profile'),
        );
    }

    public function test_immunization_meeting_base_requirements_is_stamped(): void
    {
        $s = $this->subjectWithVault();

        $imm = $this->withToken($s['token'])
            ->postJson("/api/fhir/{$s['vault_id']}/Immunization", [
                'resourceType' => 'Immunization',
                'status' => 'completed',
                'vaccineCode' => ['coding' => [['system' => 'http://hl7.org/fhir/sid/cvx', 'code' => '208']]],
                'patient' => ['reference' => "Patient/{$s['vault_id']}"],
                'occurrenceDateTime' => '2025-10-01',
            ])
            ->assertCreated();

        $this->assertContains(
            'http://hl7.org/fhir/us/core/StructureDefinition/us-core-immunization',
            $imm->json('meta.profile'),
        );
    }
}
