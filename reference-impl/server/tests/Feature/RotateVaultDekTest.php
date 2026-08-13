<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Vault;
use App\Models\VaultEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\BuildsVaults;
use Tests\TestCase;

/**
 * vault:rotate-dek — per-vault envelope key rotation.
 *
 * The critical invariant: hashes are computed over PLAINTEXT (VaultService::
 * commitEntry), so rotating the encryption-at-rest key must never change a
 * single content_hash or chain_hash, and the chain must still verify.
 */
final class RotateVaultDekTest extends TestCase
{
    use BuildsVaults;
    use RefreshDatabase;

    public function test_rotation_re_wraps_the_dek_and_re_encrypts_every_payload(): void
    {
        $s = $this->subjectWithVault();
        $this->commitEntry($s['token'], $s['vault_id'], [
            'payload' => ['resourceType' => 'Condition', 'code' => ['text' => 'Hypertension']],
        ])->assertCreated();
        $this->commitEntry($s['token'], $s['vault_id'], [
            'resource_type' => 'Observation',
            'payload' => ['resourceType' => 'Observation', 'code' => ['text' => 'BP 120/80']],
        ])->assertCreated();

        $beforeWrappedDek = DB::table('vaults')->where('id', $s['vault_id'])->value('wrapped_dek');
        $beforeCiphertexts = DB::table('vault_entries')
            ->where('vault_id', $s['vault_id'])
            ->orderBy('seq')
            ->pluck('payload')
            ->all();
        $beforeHashes = DB::table('vault_entries')
            ->where('vault_id', $s['vault_id'])
            ->orderBy('seq')
            ->get(['content_hash', 'chain_hash'])
            ->map(fn ($r) => (array) $r)
            ->all();
        $beforeChainHead = DB::table('vaults')->where('id', $s['vault_id'])->value('chain_head_hash');

        $exitCode = Artisan::call('vault:rotate-dek', ['vault' => $s['vault_id']]);
        $this->assertSame(0, $exitCode);

        // The wrapped DEK changed…
        $afterWrappedDek = DB::table('vaults')->where('id', $s['vault_id'])->value('wrapped_dek');
        $this->assertNotSame($beforeWrappedDek, $afterWrappedDek);

        // …ciphertext at rest changed (proves re-encryption actually happened)…
        $afterCiphertexts = DB::table('vault_entries')
            ->where('vault_id', $s['vault_id'])
            ->orderBy('seq')
            ->pluck('payload')
            ->all();
        $this->assertNotSame($beforeCiphertexts, $afterCiphertexts);
        foreach ($afterCiphertexts as $c) {
            $this->assertStringStartsWith('oprv1:', $c);
        }

        // …but content_hash/chain_hash and the vault's chain head are BYTE-IDENTICAL.
        $afterHashes = DB::table('vault_entries')
            ->where('vault_id', $s['vault_id'])
            ->orderBy('seq')
            ->get(['content_hash', 'chain_hash'])
            ->map(fn ($r) => (array) $r)
            ->all();
        $this->assertSame($beforeHashes, $afterHashes);
        $this->assertSame($beforeChainHead, DB::table('vaults')->where('id', $s['vault_id'])->value('chain_head_hash'));

        // Chain verification still passes…
        $this->withToken($s['token'])
            ->getJson("/api/vaults/{$s['vault_id']}/verify")
            ->assertOk()
            ->assertJsonPath('valid', true)
            ->assertJsonPath('entries', 2);

        // …and every entry decrypts to the identical plaintext under the new key.
        Vault::query(); // ensure model booted
        $entries = VaultEntry::query()->where('vault_id', $s['vault_id'])->orderBy('seq')->get();
        $this->assertSame('Hypertension', $entries[0]->payload['code']['text']);
        $this->assertSame('BP 120/80', $entries[1]->payload['code']['text']);

        // The API still serves plaintext identically post-rotation.
        $this->withToken($s['token'])
            ->getJson("/api/vaults/{$s['vault_id']}/entries")
            ->assertOk()
            ->assertJsonPath('entries.0.payload.code.text', 'Hypertension')
            ->assertJsonPath('entries.1.payload.code.text', 'BP 120/80');
    }

    public function test_rotation_is_all_or_nothing_on_an_unknown_vault(): void
    {
        $exitCode = Artisan::call('vault:rotate-dek', ['vault' => '00000000-0000-0000-0000-000000000000']);
        $this->assertSame(1, $exitCode);
    }

    public function test_append_only_guard_still_blocks_ordinary_updates_after_rotation_ships(): void
    {
        // Regression: rotation's transaction-local trigger bypass must NEVER
        // leak into any other write path. A plain SQL UPDATE outside the
        // rotation command must still be rejected by the DB trigger.
        $s = $this->subjectWithVault();
        $this->commitEntry($s['token'], $s['vault_id'])->assertCreated();

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('vault_entries')->where('vault_id', $s['vault_id'])->update(['payload' => 'tampered']);
    }
}
