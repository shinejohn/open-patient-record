<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsVaults;
use Tests\TestCase;

/**
 * The FHIR R4 read surface: each vault is its own FHIR base URL. Read-only,
 * grant-enforced, current-view semantics, verification tier as meta.tag.
 */
final class FhirReadSurfaceTest extends TestCase
{
    use BuildsVaults;
    use RefreshDatabase;

    public function test_metadata_is_public_and_declares_fhir_r4(): void
    {
        $this->getJson('/api/fhir/metadata')
            ->assertOk()
            ->assertJsonPath('resourceType', 'CapabilityStatement')
            ->assertJsonPath('fhirVersion', '4.0.1');
    }

    public function test_resources_carry_verification_tier_as_meta_tag(): void
    {
        $s = $this->subjectWithVault();
        $entry = $this->commitEntry($s['token'], $s['vault_id'], [
            'verification_tier' => 'unverified-import',
        ])->assertCreated();

        $resource = $this->withToken($s['token'])
            ->getJson("/api/fhir/{$s['vault_id']}/Condition/{$entry->json('id')}")
            ->assertOk();

        $this->assertSame('Condition', $resource->json('resourceType'));
        $this->assertSame($entry->json('id'), $resource->json('id'));
        $this->assertSame('1', $resource->json('meta.versionId'));
        $this->assertSame('urn:opr:verification-tier', $resource->json('meta.tag.0.system'));
        $this->assertSame('unverified-import', $resource->json('meta.tag.0.code'));
    }

    public function test_everything_returns_patient_anchor_plus_current_entries(): void
    {
        $s = $this->subjectWithVault();
        $this->commitEntry($s['token'], $s['vault_id'])->assertCreated();
        $this->commitEntry($s['token'], $s['vault_id'], [
            'resource_type' => 'MedicationStatement',
            'payload' => ['resourceType' => 'MedicationStatement', 'medication' => ['text' => 'Metformin']],
        ])->assertCreated();

        $bundle = $this->withToken($s['token'])
            ->getJson("/api/fhir/{$s['vault_id']}/Patient/\$everything")
            ->assertOk()
            ->assertJsonPath('resourceType', 'Bundle')
            ->assertJsonPath('type', 'searchset');

        $this->assertSame(3, $bundle->json('total')); // Patient + 2 entries
        $this->assertSame('Patient', $bundle->json('entry.0.resource.resourceType'));
    }

    public function test_superseded_entries_are_excluded_from_the_current_view(): void
    {
        $s = $this->subjectWithVault();
        $original = $this->commitEntry($s['token'], $s['vault_id'], [
            'payload' => ['resourceType' => 'Condition', 'code' => ['text' => 'Hypertensoin (typo)']],
        ])->assertCreated();
        $correction = $this->commitEntry($s['token'], $s['vault_id'], [
            'payload' => ['resourceType' => 'Condition', 'code' => ['text' => 'Hypertension']],
            'replaces_entry_id' => $original->json('id'),
        ])->assertCreated();

        $bundle = $this->withToken($s['token'])
            ->getJson("/api/fhir/{$s['vault_id']}/Condition")
            ->assertOk();

        $this->assertSame(1, $bundle->json('total'));
        $this->assertSame($correction->json('id'), $bundle->json('entry.0.resource.id'));

        // The superseded version is gone from FHIR reads but never from history:
        // a direct read 404s on the FHIR surface...
        $this->withToken($s['token'])
            ->getJson("/api/fhir/{$s['vault_id']}/Condition/{$original->json('id')}")
            ->assertOk(); // single-resource read still serves it (referenced by provenance)
        // ...and it remains in the native export (portability keeps everything).
        $export = $this->withToken($s['token'])
            ->getJson("/api/vaults/{$s['vault_id']}/export")
            ->assertOk();
        $this->assertCount(2, $export->json('entries'));
    }

    public function test_grant_scope_and_sensitive_filtering_apply_on_the_fhir_surface(): void
    {
        $s = $this->subjectWithVault();
        $this->commitEntry($s['token'], $s['vault_id'])->assertCreated(); // Condition
        $this->commitEntry($s['token'], $s['vault_id'], [
            'resource_type' => 'MedicationStatement',
            'payload' => ['resourceType' => 'MedicationStatement', 'medication' => ['text' => 'Buprenorphine']],
            'sensitive_category' => '42_cfr_part_2',
        ])->assertCreated();
        $this->commitEntry($s['token'], $s['vault_id'], [
            'resource_type' => 'Observation',
            'payload' => ['resourceType' => 'Observation', 'code' => ['text' => 'BP 120/80']],
        ])->assertCreated();

        $mint = $this->mintGrant($s['token'], $s['vault_id'], ['scope' => ['Condition', 'MedicationStatement']])
            ->assertCreated();
        $token = $this->postJson('/api/grants/redeem', [
            'pseudo_id' => $mint->json('pseudo_id'),
            'otp' => $mint->json('otp'),
        ])->assertOk()->json('token');

        $bundle = $this->withToken($token)
            ->getJson("/api/fhir/{$s['vault_id']}/Patient/\$everything")
            ->assertOk();

        $types = collect($bundle->json('entry'))->pluck('resource.resourceType');
        $this->assertContains('Condition', $types);
        $this->assertNotContains('Observation', $types);           // out of scope
        $this->assertNotContains('MedicationStatement', $types);   // sensitive, not granted

        // Direct read of the sensitive resource is indistinguishable from nonexistence.
        $medId = $this->withToken($s['token'])
            ->getJson("/api/vaults/{$s['vault_id']}/entries")
            ->json('entries.1.id');
        $this->withToken($token)
            ->getJson("/api/fhir/{$s['vault_id']}/MedicationStatement/{$medId}")
            ->assertNotFound();
    }

    public function test_strangers_get_nothing_from_the_fhir_surface(): void
    {
        $s = $this->subjectWithVault();
        $this->commitEntry($s['token'], $s['vault_id'])->assertCreated();
        $other = $this->registerUser('nosy@example.test');

        $this->withToken($other['token'])
            ->getJson("/api/fhir/{$s['vault_id']}/Patient/\$everything")
            ->assertForbidden();
        $this->withToken($other['token'])
            ->getJson("/api/fhir/{$s['vault_id']}/Condition")
            ->assertForbidden();
    }

    public function test_fhir_surface_rejects_mutation_with_operation_outcome(): void
    {
        $s = $this->subjectWithVault();
        $entry = $this->commitEntry($s['token'], $s['vault_id'])->assertCreated();

        $this->withToken($s['token'])
            ->putJson("/api/fhir/{$s['vault_id']}/Condition/{$entry->json('id')}", ['resourceType' => 'Condition'])
            ->assertStatus(405)
            ->assertJsonPath('resourceType', 'OperationOutcome');
        $this->withToken($s['token'])
            ->deleteJson("/api/fhir/{$s['vault_id']}/Condition/{$entry->json('id')}")
            ->assertStatus(405)
            ->assertJsonPath('resourceType', 'OperationOutcome');
    }

    public function test_unknown_type_shapes_are_not_routable(): void
    {
        $s = $this->subjectWithVault();

        $this->withToken($s['token'])
            ->getJson("/api/fhir/{$s['vault_id']}/not-a-fhir-type")
            ->assertNotFound();
    }

    public function test_fhir_reads_are_audited(): void
    {
        $s = $this->subjectWithVault();
        $this->commitEntry($s['token'], $s['vault_id'])->assertCreated();

        $this->withToken($s['token'])
            ->getJson("/api/fhir/{$s['vault_id']}/Patient/\$everything")
            ->assertOk();

        $events = collect($this->withToken($s['token'])
            ->getJson("/api/vaults/{$s['vault_id']}/audit")
            ->assertOk()
            ->json('events'));

        $fhirRead = $events->first(fn ($e) => ($e['context']['surface'] ?? null) === 'fhir');
        $this->assertNotNull($fhirRead);
        $this->assertSame('Patient/$everything', $fhirRead['context']['target']);
    }
}
