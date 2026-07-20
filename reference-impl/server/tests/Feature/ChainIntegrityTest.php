<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\BuildsVaults;
use Tests\TestCase;

/** Spec §4.2 (hash chain) and §4.5 (chain head as tamper witness). */
final class ChainIntegrityTest extends TestCase
{
    use BuildsVaults;
    use RefreshDatabase;

    public function test_chain_links_entries_and_verifies_clean(): void
    {
        $s = $this->subjectWithVault();

        $first = $this->commitEntry($s['token'], $s['vault_id'])->assertCreated();
        $second = $this->commitEntry($s['token'], $s['vault_id'], [
            'resource_type' => 'MedicationStatement',
            'payload' => ['resourceType' => 'MedicationStatement', 'medication' => ['text' => 'Lisinopril 10mg']],
        ])->assertCreated();

        $this->assertNotSame($first->json('chain_hash'), $second->json('chain_hash'));

        $verify = $this->withToken($s['token'])
            ->getJson("/api/vaults/{$s['vault_id']}/verify")
            ->assertOk();

        $this->assertTrue($verify->json('valid'));
        $this->assertSame(2, $verify->json('entries'));

        // The vault's chain head equals the newest entry's chain hash.
        $this->withToken($s['token'])
            ->getJson("/api/vaults/{$s['vault_id']}")
            ->assertOk()
            ->assertJsonPath('chain_head_hash', $second->json('chain_hash'));
    }

    public function test_semantically_identical_payloads_hash_identically_regardless_of_key_order(): void
    {
        $s = $this->subjectWithVault();

        $a = $this->commitEntry($s['token'], $s['vault_id'], [
            'payload' => ['resourceType' => 'Condition', 'code' => ['text' => 'Asthma']],
        ])->assertCreated();
        $b = $this->commitEntry($s['token'], $s['vault_id'], [
            'payload' => ['code' => ['text' => 'Asthma'], 'resourceType' => 'Condition'],
        ])->assertCreated();

        $this->assertSame($a->json('content_hash'), $b->json('content_hash'));
    }

    public function test_tampering_is_detected_by_verification(): void
    {
        $s = $this->subjectWithVault();
        $this->commitEntry($s['token'], $s['vault_id'])->assertCreated();
        $this->commitEntry($s['token'], $s['vault_id'], [
            'payload' => ['resourceType' => 'Condition', 'code' => ['text' => 'Diabetes']],
        ])->assertCreated();

        // Simulate a hostile custodian: bypass the append-only trigger and rewrite history.
        DB::statement('ALTER TABLE vault_entries DISABLE TRIGGER vault_entries_immutable');
        DB::update(
            "UPDATE vault_entries SET payload = ?::jsonb WHERE vault_id = ? AND seq = 1",
            [json_encode(['resourceType' => 'Condition', 'code' => ['text' => 'Nothing to see here']]), $s['vault_id']],
        );
        DB::statement('ALTER TABLE vault_entries ENABLE TRIGGER vault_entries_immutable');

        $verify = $this->withToken($s['token'])
            ->getJson("/api/vaults/{$s['vault_id']}/verify")
            ->assertOk();

        $this->assertFalse($verify->json('valid'));
        $this->assertSame(1, $verify->json('first_invalid_seq'));
    }

    public function test_export_is_complete_and_carries_the_chain_head(): void
    {
        $s = $this->subjectWithVault();
        $this->commitEntry($s['token'], $s['vault_id'])->assertCreated();
        $last = $this->commitEntry($s['token'], $s['vault_id'], [
            'resource_type' => 'AllergyIntolerance',
            'payload' => ['resourceType' => 'AllergyIntolerance', 'code' => ['text' => 'Penicillin']],
        ])->assertCreated();

        $export = $this->withToken($s['token'])
            ->getJson("/api/vaults/{$s['vault_id']}/export")
            ->assertOk();

        $this->assertSame($last->json('chain_hash'), $export->json('chain_head_hash'));
        $this->assertCount(2, $export->json('entries'));
        $this->assertNotEmpty($export->json('entries.0.provenance'));
        $this->assertNotEmpty($export->json('audit_events'));
    }

    public function test_full_provenance_is_preserved_not_truncated_to_organization(): void
    {
        // Regression: validate() with a nested provenance.organization rule used to
        // strip every other provenance key (verifier, span, method) on commit.
        $s = $this->subjectWithVault();
        $this->commitEntry($s['token'], $s['vault_id'], [
            'provenance' => [
                'organization' => 'Riverside Clinic',
                'source_system' => 'ccda-import',
                'extraction_method' => 'structured-parse',
                'source_span' => 'medication[0]',
                'verifier_name' => 'Dr. Okafor',
            ],
        ])->assertCreated();

        $prov = $this->withToken($s['token'])
            ->getJson("/api/vaults/{$s['vault_id']}/export")
            ->assertOk()
            ->json('entries.0.provenance');

        $this->assertSame('Riverside Clinic', $prov['organization']);
        $this->assertSame('Dr. Okafor', $prov['verifier_name']);
        $this->assertSame('structured-parse', $prov['extraction_method']);
        $this->assertSame('medication[0]', $prov['source_span']);
    }

    public function test_only_the_subject_can_export(): void
    {
        $s = $this->subjectWithVault();
        $other = $this->registerUser('stranger@example.test');

        $this->withToken($other['token'])
            ->getJson("/api/vaults/{$s['vault_id']}/export")
            ->assertForbidden();
    }
}
