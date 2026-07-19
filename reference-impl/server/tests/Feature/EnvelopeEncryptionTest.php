<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Vault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\BuildsVaults;
use Tests\TestCase;

/** Per-vault envelope encryption: ciphertext at rest, plaintext only through the API. */
final class EnvelopeEncryptionTest extends TestCase
{
    use BuildsVaults;
    use RefreshDatabase;

    public function test_payloads_are_ciphertext_at_rest(): void
    {
        $s = $this->subjectWithVault();
        $this->commitEntry($s['token'], $s['vault_id'], [
            'payload' => ['resourceType' => 'Condition', 'code' => ['text' => 'Hypertension']],
        ])->assertCreated();

        $raw = DB::table('vault_entries')->value('payload');

        $this->assertStringStartsWith('oprv1:', $raw);
        $this->assertStringNotContainsString('Hypertension', $raw);

        // ...while the API still serves plaintext to authorized callers.
        $this->withToken($s['token'])
            ->getJson("/api/vaults/{$s['vault_id']}/entries")
            ->assertOk()
            ->assertJsonPath('entries.0.payload.code.text', 'Hypertension');
    }

    public function test_each_vault_has_its_own_wrapped_key_and_it_never_serializes(): void
    {
        $a = $this->subjectWithVault('alice@example.test');
        $b = $this->subjectWithVault('bob@example.test');
        $this->commitEntry($a['token'], $a['vault_id'])->assertCreated();
        $this->commitEntry($b['token'], $b['vault_id'])->assertCreated();

        $keys = DB::table('vaults')->pluck('wrapped_dek', 'id');
        $this->assertNotNull($keys[$a['vault_id']]);
        $this->assertNotNull($keys[$b['vault_id']]);
        $this->assertNotSame($keys[$a['vault_id']], $keys[$b['vault_id']]);

        // The wrapped key must never appear in any API response.
        $show = $this->withToken($a['token'])->getJson("/api/vaults/{$a['vault_id']}")->assertOk();
        $this->assertArrayNotHasKey('wrapped_dek', $show->json());
        $this->assertNull(Vault::query()->first()->toArray()['wrapped_dek'] ?? null);
    }

    public function test_hashes_verify_over_plaintext_despite_encrypted_storage(): void
    {
        $s = $this->subjectWithVault();
        $this->commitEntry($s['token'], $s['vault_id'])->assertCreated();
        $this->commitEntry($s['token'], $s['vault_id'], [
            'resource_type' => 'Observation',
            'payload' => ['resourceType' => 'Observation', 'code' => ['text' => 'BP 120/80']],
        ])->assertCreated();

        $this->withToken($s['token'])
            ->getJson("/api/vaults/{$s['vault_id']}/verify")
            ->assertOk()
            ->assertJsonPath('valid', true);
    }
}
