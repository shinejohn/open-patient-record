<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Vault;
use App\Support\EnvelopeCrypto;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Per-vault DEK rotation (key-management runbook, mirrors
 * WitnessService::rotateKey's pattern for the vault-content envelope key
 * instead of the witness signing key).
 *
 * A vault's data-encryption key (DEK) never appears outside this process:
 * generated fresh, used to re-seal every payload, then discarded — only the
 * new WRAPPED key persists (wrapped by the app master key, same as first
 * generation in Vault::resolveDek).
 *
 * CRITICAL INVARIANT: content_hash / chain_hash are computed over the
 * PLAINTEXT canonical form (VaultService::commitEntry), never over the
 * ciphertext. Rotation therefore changes ONLY the `payload` column — it must
 * NEVER touch content_hash, chain_hash, seq, or any other column, and the
 * hash chain must verify identically before and after. This command asserts
 * that invariant itself (see the chain-hash recomputation check below) before
 * committing, so a bug here fails loudly instead of silently corrupting the
 * chain.
 *
 * DESIGN NOTE on the append-only guard (spec §4.1): `vault_entries` carries a
 * DB-level trigger that unconditionally blocks UPDATE/DELETE at every other
 * write path (Eloquent model, API, ad-hoc SQL) — that guard is untouched and
 * stays in force. This command is the ONE narrowly-scoped, transaction-local
 * exception: `SET LOCAL session_replication_role = replica` suspends trigger
 * firing only for the duration of this single DB transaction (Postgres reverts
 * it automatically at commit/rollback; it is never a session- or table-level
 * change). Content itself is never mutated — only its at-rest encryption
 * envelope — and the invariant check above proves the plaintext, and
 * therefore every hash, is unchanged before the transaction is allowed to
 * commit.
 *
 * No plaintext payload is ever printed or logged by this command.
 */
final class RotateVaultDek extends Command
{
    protected $signature = 'vault:rotate-dek {vault : Vault UUID}';

    protected $description = 'Rotate a vault\'s per-vault data-encryption key (DEK), re-sealing every entry payload under a freshly generated key, all-or-nothing.';

    public function handle(): int
    {
        $vaultId = (string) $this->argument('vault');

        try {
            $result = DB::transaction(function () use ($vaultId): array {
                // Transaction-local only: reverts automatically at commit/rollback.
                // Suspends the append-only trigger for THIS re-encryption
                // transaction alone; every other write path stays fully guarded.
                DB::statement('SET LOCAL session_replication_role = replica');

                /** @var Vault $vault */
                $vault = Vault::query()->whereKey($vaultId)->lockForUpdate()->firstOrFail();

                $oldDek = Vault::dekFor($vault->id);
                $newDek = EnvelopeCrypto::generateKey();

                $rows = DB::table('vault_entries')
                    ->where('vault_id', $vault->id)
                    ->orderBy('seq')
                    ->get(['id', 'payload', 'content_hash', 'chain_hash']);

                $previousChainHash = null;
                $rewrapped = 0;

                foreach ($rows as $row) {
                    $plaintext = EnvelopeCrypto::decrypt((string) $row->payload, $oldDek);

                    // Invariant proof: recompute the content/chain hash over the
                    // SAME plaintext this row already claims, and require it to
                    // match what's stored — re-encryption must never be able to
                    // produce a different hash, because hashes are plaintext-only.
                    $recomputedContentHash = \App\Support\Canonicalizer::contentHash($plaintext);
                    $recomputedChainHash = \App\Support\Canonicalizer::chainHash($previousChainHash, $recomputedContentHash);
                    if (! hash_equals($row->content_hash, $recomputedContentHash)
                        || ! hash_equals($row->chain_hash, $recomputedChainHash)) {
                        throw new RuntimeException(
                            "OPR: refusing to rotate — entry {$row->id} hash mismatch before rotation (chain already invalid).",
                        );
                    }
                    $previousChainHash = $row->chain_hash;

                    $newCiphertext = EnvelopeCrypto::encrypt($plaintext, $newDek);

                    // The one deliberate, transaction-scoped exception to the
                    // append-only guard (see class doc). Only `payload` changes;
                    // content_hash/chain_hash/seq are never written here.
                    DB::table('vault_entries')->where('id', $row->id)->update([
                        'payload' => $newCiphertext,
                    ]);
                    $rewrapped++;
                }

                $vault->forceFill([
                    'wrapped_dek' => Crypt::encryptString(base64_encode($newDek)),
                ])->save();

                // Evict the stale in-process DEK cache so any read in THIS
                // process after rotation unwraps under the new key.
                Vault::forgetDekCache($vault->id);

                return ['vault_id' => $vault->id, 'entries_rewrapped' => $rewrapped];
            });
        } catch (\Throwable $e) {
            $this->error('Rotation aborted, no changes committed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Vault {$result['vault_id']}: DEK rotated, {$result['entries_rewrapped']} entr".($result['entries_rewrapped'] === 1 ? 'y' : 'ies').' re-sealed.');

        return self::SUCCESS;
    }
}
