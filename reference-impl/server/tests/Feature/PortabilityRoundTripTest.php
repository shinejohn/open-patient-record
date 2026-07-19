<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsVaults;
use Tests\TestCase;

/**
 * Spec §7.2/§7.3 — THE conformance centerpiece: export from vault A, import into
 * empty vault B, prove nothing was lost and the chain anchored intact.
 */
final class PortabilityRoundTripTest extends TestCase
{
    use BuildsVaults;
    use RefreshDatabase;

    /** @return array{export: array<string, mixed>, a: array<string,string>} */
    private function populatedExport(): array
    {
        $a = $this->subjectWithVault('alice@example.test');

        $first = $this->commitEntry($a['token'], $a['vault_id'])->assertCreated();
        $this->commitEntry($a['token'], $a['vault_id'], [
            'resource_type' => 'MedicationStatement',
            'payload' => ['resourceType' => 'MedicationStatement', 'medication' => ['text' => 'Buprenorphine']],
            'sensitive_category' => '42_cfr_part_2',
        ])->assertCreated();
        $this->commitEntry($a['token'], $a['vault_id'], [
            'payload' => ['resourceType' => 'Condition', 'code' => ['text' => 'Hypertension, corrected']],
            'replaces_entry_id' => $first->json('id'),
        ])->assertCreated();

        $export = $this->withToken($a['token'])
            ->getJson("/api/vaults/{$a['vault_id']}/export")
            ->assertOk()
            ->json();

        return ['export' => $export, 'a' => $a];
    }

    public function test_round_trip_preserves_everything_and_anchors_the_chain(): void
    {
        ['export' => $export] = $this->populatedExport();

        $b = $this->subjectWithVault('bob-new-custodian@example.test');
        $this->withToken($b['token'])
            ->postJson("/api/vaults/{$b['vault_id']}/import", $export)
            ->assertCreated()
            ->assertJsonPath('anchored', true)
            ->assertJsonPath('imported', 3)
            ->assertJsonPath('chain_head_hash', $export['chain_head_hash']);

        // B's chain verifies clean and lands on A's exact head (§7.3 anchor).
        $this->withToken($b['token'])
            ->getJson("/api/vaults/{$b['vault_id']}/verify")
            ->assertOk()
            ->assertJsonPath('valid', true);
        $this->withToken($b['token'])
            ->getJson("/api/vaults/{$b['vault_id']}")
            ->assertOk()
            ->assertJsonPath('chain_head_hash', $export['chain_head_hash']);

        // Semantic diff: re-export from B and compare entry-for-entry. Entry ids are
        // custodian-scoped (like FHIR resource ids) and legitimately differ; durable
        // identity is content + chain — everything else must match exactly.
        $reExport = $this->withToken($b['token'])
            ->getJson("/api/vaults/{$b['vault_id']}/export")
            ->assertOk()
            ->json();

        $strip = fn (array $entries): array => array_map(
            fn (array $e) => collect($e)->except(['id', 'replaces_entry_id'])->all(),
            $entries,
        );
        $this->assertSame($strip($export['entries']), $strip($reExport['entries']));

        // Supersession survived the id remap: B's entry 3 replaces B's entry 1.
        $this->assertSame(
            $reExport['entries'][0]['id'],
            $reExport['entries'][2]['replaces_entry_id'],
        );

        // The import itself is on B's audit trail with the source anchor.
        $imported = collect($this->withToken($b['token'])
            ->getJson("/api/vaults/{$b['vault_id']}/audit")->json('events'))
            ->firstWhere('action', 'vault.imported');
        $this->assertSame($export['chain_head_hash'], $imported['context']['source_chain_head']);
    }

    public function test_tampering_in_transit_is_rejected(): void
    {
        ['export' => $export] = $this->populatedExport();

        // Adversary edits a medication in the export file; declared hashes now lie.
        $export['entries'][1]['payload']['medication']['text'] = 'Placebo';

        $b = $this->subjectWithVault('bob2@example.test');
        $this->withToken($b['token'])
            ->postJson("/api/vaults/{$b['vault_id']}/import", $export)
            ->assertStatus(422)
            ->assertJsonPath('message', 'content_mismatch:2');

        // Nothing was partially imported — the transaction rolled back whole.
        $this->withToken($b['token'])
            ->getJson("/api/vaults/{$b['vault_id']}")
            ->assertOk()
            ->assertJsonPath('entry_count', 0);
    }

    public function test_forged_chain_head_is_rejected_at_the_anchor(): void
    {
        ['export' => $export] = $this->populatedExport();
        $export['chain_head_hash'] = str_repeat('f', 64);

        $b = $this->subjectWithVault('bob3@example.test');
        $this->withToken($b['token'])
            ->postJson("/api/vaults/{$b['vault_id']}/import", $export)
            ->assertStatus(422)
            ->assertJsonPath('message', 'anchor_mismatch');
    }

    public function test_import_requires_an_empty_vault(): void
    {
        ['export' => $export] = $this->populatedExport();

        $b = $this->subjectWithVault('bob4@example.test');
        $this->commitEntry($b['token'], $b['vault_id'])->assertCreated();

        $this->withToken($b['token'])
            ->postJson("/api/vaults/{$b['vault_id']}/import", $export)
            ->assertStatus(409);
    }

    public function test_only_the_subject_can_import(): void
    {
        ['export' => $export] = $this->populatedExport();
        $b = $this->subjectWithVault('bob5@example.test');
        $stranger = $this->registerUser('stranger@example.test');

        $this->withToken($stranger['token'])
            ->postJson("/api/vaults/{$b['vault_id']}/import", $export)
            ->assertForbidden();
    }
}
