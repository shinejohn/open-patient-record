<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsVaults;
use Tests\TestCase;

/**
 * F1: the FHIR write surface — POST /api/fhir/{vault}/{type}.
 *
 * The native OPR envelope (POST /vaults/{vault}/entries) is deliberately
 * type-agnostic: custody is format-neutral. The FHIR door is the OPPOSITE — it is
 * strict, because a stock FHIR client gets no envelope to carry its honesty in:
 *
 *   - only registry-supported resource types are accepted;
 *   - R4 required elements are validated (a Condition without a subject is not a
 *     Condition, it's a liability);
 *   - the server assigns ids — client-asserted ids are ignored, never trusted;
 *   - verification tier derives from WHO writes: the subject's own hand is
 *     unverified-import (excluded from CDS until a clinician verifies — spec
 *     §4.3), a grant-holding system writes at verified-source;
 *   - a sensitive-category meta.tag on the way in becomes the entry's
 *     sensitive_category, with all existing grant filtering applied on the way
 *     back out.
 *
 * Everything lands through VaultService::commitEntry, so every FHIR write is
 * hash-chained, audited, append-only, and provenance-carrying like any other
 * commit. There is exactly one write path; FHIR is a door onto it, not a bypass.
 */
final class FhirWriteTest extends TestCase
{
    use BuildsVaults;
    use RefreshDatabase;

    public function test_subject_creates_a_condition_and_it_lands_on_the_chain(): void
    {
        $s = $this->subjectWithVault();

        $created = $this->withToken($s['token'])
            ->postJson("/api/fhir/{$s['vault_id']}/Condition", [
                'resourceType' => 'Condition',
                'subject' => ['reference' => "Patient/{$s['vault_id']}"],
                'code' => ['text' => 'Hypertension'],
            ])
            ->assertCreated();

        $id = $created->json('id');
        $this->assertNotNull($id);
        $this->assertSame('Condition', $created->json('resourceType'));
        $this->assertSame('1', $created->json('meta.versionId'));
        $this->assertStringContainsString(
            "/api/fhir/{$s['vault_id']}/Condition/{$id}",
            (string) $created->headers->get('Location'),
        );

        // The subject's own hand is unverified-import until a clinician verifies.
        $this->assertSame('urn:opr:verification-tier', $created->json('meta.tag.0.system'));
        $this->assertSame('unverified-import', $created->json('meta.tag.0.code'));

        // Visible on the read surface…
        $bundle = $this->withToken($s['token'])
            ->getJson("/api/fhir/{$s['vault_id']}/Condition")
            ->assertOk();
        $this->assertSame(1, $bundle->json('total'));

        // …and the chain still verifies: a FHIR write is a real commit.
        $this->withToken($s['token'])
            ->getJson("/api/vaults/{$s['vault_id']}/verify")
            ->assertOk()
            ->assertJsonPath('valid', true)
            ->assertJsonPath('entries', 1);
    }

    public function test_grant_holder_with_write_creates_at_verified_source(): void
    {
        $s = $this->subjectWithVault();
        $mint = $this->mintGrant($s['token'], $s['vault_id'], [
            'scope' => ['MedicationStatement'],
            'permissions' => ['read', 'write'],
        ])->assertCreated();
        $redeem = $this->postJson('/api/grants/redeem', [
            'pseudo_id' => $mint->json('pseudo_id'),
            'otp' => $mint->json('otp'),
        ])->assertOk();

        $created = $this->withToken($redeem->json('token'))
            ->withHeader('X-OPR-Organization', 'Riverbend Family Medicine')
            ->postJson("/api/fhir/{$s['vault_id']}/MedicationStatement", [
                'resourceType' => 'MedicationStatement',
                'status' => 'active',
                'medicationCodeableConcept' => ['text' => 'Metformin 500mg'],
                'subject' => ['reference' => "Patient/{$s['vault_id']}"],
            ])
            ->assertCreated();

        $this->assertSame('verified-source', $created->json('meta.tag.0.code'));

        // Provenance carries the contributing organization (spec §5), visible on
        // the native surface the subject reads.
        $entries = $this->withToken($s['token'])
            ->getJson("/api/vaults/{$s['vault_id']}/entries")
            ->assertOk();
        $this->assertSame('Riverbend Family Medicine', $entries->json('entries.0.provenance.organization'));
    }

    public function test_read_only_grant_cannot_write(): void
    {
        $s = $this->subjectWithVault();
        $mint = $this->mintGrant($s['token'], $s['vault_id'], [
            'scope' => ['Condition'],
            'permissions' => ['read'],
        ])->assertCreated();
        $redeem = $this->postJson('/api/grants/redeem', [
            'pseudo_id' => $mint->json('pseudo_id'),
            'otp' => $mint->json('otp'),
        ])->assertOk();

        $this->withToken($redeem->json('token'))
            ->postJson("/api/fhir/{$s['vault_id']}/Condition", [
                'resourceType' => 'Condition',
                'subject' => ['reference' => "Patient/{$s['vault_id']}"],
            ])
            ->assertStatus(403);
    }

    public function test_write_grant_scope_excludes_other_resource_types(): void
    {
        $s = $this->subjectWithVault();
        $mint = $this->mintGrant($s['token'], $s['vault_id'], [
            'scope' => ['MedicationStatement'],
            'permissions' => ['read', 'write'],
        ])->assertCreated();
        $redeem = $this->postJson('/api/grants/redeem', [
            'pseudo_id' => $mint->json('pseudo_id'),
            'otp' => $mint->json('otp'),
        ])->assertOk();

        $this->withToken($redeem->json('token'))
            ->postJson("/api/fhir/{$s['vault_id']}/Condition", [
                'resourceType' => 'Condition',
                'subject' => ['reference' => "Patient/{$s['vault_id']}"],
            ])
            ->assertStatus(403);
    }

    public function test_unsupported_resource_type_is_rejected_with_operation_outcome(): void
    {
        $s = $this->subjectWithVault();

        $this->withToken($s['token'])
            ->postJson("/api/fhir/{$s['vault_id']}/ZzzImaginary", [
                'resourceType' => 'ZzzImaginary',
            ])
            ->assertStatus(400)
            ->assertJsonPath('resourceType', 'OperationOutcome')
            ->assertJsonPath('issue.0.code', 'not-supported');
    }

    public function test_body_resource_type_must_match_the_url(): void
    {
        $s = $this->subjectWithVault();

        $this->withToken($s['token'])
            ->postJson("/api/fhir/{$s['vault_id']}/Condition", [
                'resourceType' => 'Observation',
                'status' => 'final',
                'code' => ['text' => 'BP'],
            ])
            ->assertStatus(400)
            ->assertJsonPath('resourceType', 'OperationOutcome')
            ->assertJsonPath('issue.0.code', 'invalid');
    }

    public function test_missing_required_elements_are_rejected_with_named_paths(): void
    {
        $s = $this->subjectWithVault();

        $response = $this->withToken($s['token'])
            ->postJson("/api/fhir/{$s['vault_id']}/Observation", [
                'resourceType' => 'Observation',
            ])
            ->assertStatus(422)
            ->assertJsonPath('resourceType', 'OperationOutcome');

        $issues = $response->json('issue');
        $this->assertIsArray($issues);
        $expressions = array_map(
            static fn (array $i): string => $i['expression'][0] ?? '',
            $issues,
        );
        $this->assertContains('Observation.status', $expressions);
        $this->assertContains('Observation.code', $expressions);
        foreach ($issues as $issue) {
            $this->assertSame('required', $issue['code']);
        }
    }

    public function test_choice_elements_count_as_required(): void
    {
        $s = $this->subjectWithVault();

        // MedicationStatement requires medication[x] — either the codeable
        // concept or the reference satisfies it; absence of both fails.
        $this->withToken($s['token'])
            ->postJson("/api/fhir/{$s['vault_id']}/MedicationStatement", [
                'resourceType' => 'MedicationStatement',
                'status' => 'active',
                'subject' => ['reference' => "Patient/{$s['vault_id']}"],
            ])
            ->assertStatus(422)
            ->assertJsonPath('issue.0.code', 'required');

        $this->withToken($s['token'])
            ->postJson("/api/fhir/{$s['vault_id']}/MedicationStatement", [
                'resourceType' => 'MedicationStatement',
                'status' => 'active',
                'subject' => ['reference' => "Patient/{$s['vault_id']}"],
                'medicationReference' => ['reference' => 'Medication/example'],
            ])
            ->assertCreated();
    }

    public function test_server_assigns_the_id_and_ignores_a_client_asserted_one(): void
    {
        $s = $this->subjectWithVault();

        $created = $this->withToken($s['token'])
            ->postJson("/api/fhir/{$s['vault_id']}/Condition", [
                'resourceType' => 'Condition',
                'id' => 'attacker-chosen-id',
                'meta' => ['versionId' => '999'],
                'subject' => ['reference' => "Patient/{$s['vault_id']}"],
            ])
            ->assertCreated();

        $this->assertNotSame('attacker-chosen-id', $created->json('id'));
        $this->assertSame('1', $created->json('meta.versionId'));
    }

    public function test_incoming_sensitive_tag_is_stored_and_grant_filtering_applies(): void
    {
        $s = $this->subjectWithVault();

        $this->withToken($s['token'])
            ->postJson("/api/fhir/{$s['vault_id']}/MedicationStatement", [
                'resourceType' => 'MedicationStatement',
                'status' => 'active',
                'medicationCodeableConcept' => ['text' => 'Buprenorphine'],
                'subject' => ['reference' => "Patient/{$s['vault_id']}"],
                'meta' => ['tag' => [[
                    'system' => 'urn:opr:sensitive-category',
                    'code' => '42_cfr_part_2',
                ]]],
            ])
            ->assertCreated()
            ->assertJsonPath('meta.tag.1.code', '42_cfr_part_2');

        // A grant that does not cover the category never sees the entry.
        $mint = $this->mintGrant($s['token'], $s['vault_id'], [
            'scope' => ['MedicationStatement'],
            'permissions' => ['read'],
        ])->assertCreated();
        $redeem = $this->postJson('/api/grants/redeem', [
            'pseudo_id' => $mint->json('pseudo_id'),
            'otp' => $mint->json('otp'),
        ])->assertOk();

        $bundle = $this->withToken($redeem->json('token'))
            ->getJson("/api/fhir/{$s['vault_id']}/MedicationStatement")
            ->assertOk();
        $this->assertSame(0, $bundle->json('total'));
    }

    public function test_capability_statement_declares_supported_resources(): void
    {
        $metadata = $this->getJson('/api/fhir/metadata')->assertOk();

        $resources = $metadata->json('rest.0.resource');
        $this->assertIsArray($resources);
        $this->assertNotEmpty($resources);

        $types = array_column($resources, 'type');
        foreach (['Patient', 'Condition', 'Observation', 'MedicationStatement', 'Immunization', 'Encounter', 'Procedure', 'DocumentReference'] as $expected) {
            $this->assertContains($expected, $types);
        }

        $condition = $resources[array_search('Condition', $types, true)];
        $interactions = array_column($condition['interaction'], 'code');
        $this->assertContains('read', $interactions);
        $this->assertContains('create', $interactions);
        $this->assertContains('search-type', $interactions);
    }
}
