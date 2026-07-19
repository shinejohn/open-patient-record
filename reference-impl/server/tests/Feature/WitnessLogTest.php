<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Vault;
use App\Services\WitnessService;
use App\Support\Canonicalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\BuildsVaults;
use Tests\TestCase;

/** Spec §4.5: the published, signed digest committing to all vault chain heads. */
final class WitnessLogTest extends TestCase
{
    use BuildsVaults;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_publish_signs_a_merkle_root_over_all_chain_heads(): void
    {
        $a = $this->subjectWithVault('alice@example.test');
        $b = $this->subjectWithVault('bob@example.test');
        $this->commitEntry($a['token'], $a['vault_id'])->assertCreated();
        $this->commitEntry($b['token'], $b['vault_id'])->assertCreated();

        $this->artisan('opr:publish-witness')->assertSuccessful();

        $entries = $this->getJson('/api/witness-log')->assertOk()->json('entries');
        $this->assertCount(1, $entries);
        $record = $entries[0];

        // The root is independently recomputable from the vault heads...
        $heads = Vault::query()->whereNotNull('chain_head_hash')->orderBy('id')->pluck('chain_head_hash')->all();
        $this->assertSame(WitnessService::merkleRoot($heads), $record['merkle_root']);
        $this->assertSame(2, $record['vault_count']);

        // ...and the signature verifies over the canonical unsigned record.
        $unsigned = [
            'published_at' => $record['published_at'],
            'merkle_root' => $record['merkle_root'],
            'vault_count' => $record['vault_count'],
        ];
        $this->assertTrue(sodium_crypto_sign_verify_detached(
            base64_decode($record['signature'], true),
            Canonicalizer::canonicalize($unsigned),
            base64_decode($record['public_key'], true),
        ));
    }

    public function test_new_commits_change_the_published_root(): void
    {
        $a = $this->subjectWithVault();
        $this->commitEntry($a['token'], $a['vault_id'])->assertCreated();
        $this->artisan('opr:publish-witness')->assertSuccessful();

        $this->commitEntry($a['token'], $a['vault_id'], [
            'resource_type' => 'Observation',
            'payload' => ['resourceType' => 'Observation', 'code' => ['text' => 'HbA1c 6.1%']],
        ])->assertCreated();
        $this->artisan('opr:publish-witness')->assertSuccessful();

        $entries = $this->getJson('/api/witness-log')->assertOk()->json('entries');
        $this->assertCount(2, $entries);
        $this->assertNotSame($entries[0]['merkle_root'], $entries[1]['merkle_root']);
    }

    public function test_witness_log_is_public_and_contains_no_phi(): void
    {
        $a = $this->subjectWithVault();
        $this->commitEntry($a['token'], $a['vault_id'], [
            'payload' => ['resourceType' => 'Condition', 'code' => ['text' => 'Hypertension']],
        ])->assertCreated();
        $this->artisan('opr:publish-witness')->assertSuccessful();

        // No auth required (that's the point), and nothing clinical or identifying leaks.
        $body = $this->getJson('/api/witness-log')->assertOk()->getContent();
        $this->assertStringNotContainsString('Hypertension', $body);
        $this->assertStringNotContainsString($a['vault_id'], $body);
    }
}
