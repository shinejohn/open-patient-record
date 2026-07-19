<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Vault;
use App\Support\Canonicalizer;
use Illuminate\Support\Facades\Storage;

/**
 * The witness log (spec §4.5): a periodically published, signed digest committing
 * to the chain heads of ALL hosted vaults — so not even the custodian can rewrite
 * any vault's history after publication without contradicting its own public
 * signature. One Merkle root commits to every vault while revealing nothing about
 * any of them (heads are hashes; no per-vault information is disclosed).
 *
 * Certificate-transparency thinking: ~1% of blockchain's cost, the useful 100% of
 * its guarantee. Publish the jsonl file anywhere append-only and public (a git
 * repo suffices to start). TODO(M3): per-vault Merkle inclusion proofs.
 */
final class WitnessService
{
    private const KEY_PATH = 'witness/ed25519.secret';
    private const LOG_PATH = 'witness/witness-log.jsonl';

    /** @return array<string, mixed> the published record */
    public function publish(): array
    {
        $heads = Vault::query()
            ->whereNotNull('chain_head_hash')
            ->orderBy('id')
            ->pluck('chain_head_hash')
            ->all();

        $record = [
            'published_at' => now()->toIso8601String(),
            'merkle_root' => self::merkleRoot($heads),
            'vault_count' => count($heads),
        ];

        $signature = sodium_crypto_sign_detached(
            Canonicalizer::canonicalize($record),
            $this->secretKey(),
        );

        $record['signature'] = base64_encode($signature);
        $record['public_key'] = base64_encode($this->publicKey());

        Storage::disk('local')->append(self::LOG_PATH, json_encode($record, JSON_UNESCAPED_SLASHES));

        return $record;
    }

    /** @return list<array<string, mixed>> */
    public function log(): array
    {
        if (! Storage::disk('local')->exists(self::LOG_PATH)) {
            return [];
        }

        $lines = array_filter(explode("\n", Storage::disk('local')->get(self::LOG_PATH)));

        return array_values(array_map(
            fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            $lines,
        ));
    }

    /**
     * Merkle root over sorted chain heads. Empty set commits to the hash of the
     * empty string (defined, stable). Odd nodes promote.
     *
     * @param list<string> $hashes
     */
    public static function merkleRoot(array $hashes): string
    {
        if ($hashes === []) {
            return hash('sha256', '');
        }

        sort($hashes);
        $level = array_map(fn (string $h): string => hash('sha256', $h), $hashes);

        while (count($level) > 1) {
            $next = [];
            for ($i = 0, $n = count($level); $i < $n; $i += 2) {
                $next[] = $i + 1 < $n
                    ? hash('sha256', $level[$i].$level[$i + 1])
                    : $level[$i];
            }
            $level = $next;
        }

        return $level[0];
    }

    private function secretKey(): string
    {
        $disk = Storage::disk('local');

        if (! $disk->exists(self::KEY_PATH)) {
            // Rotation: publish a rollover statement signed by old+new keys
            // (docs: key management §4). Generated lazily on first publish.
            $disk->put(self::KEY_PATH, base64_encode(sodium_crypto_sign_keypair()));
        }

        return sodium_crypto_sign_secretkey(base64_decode($disk->get(self::KEY_PATH), true));
    }

    private function publicKey(): string
    {
        $this->secretKey(); // ensure keypair exists

        return sodium_crypto_sign_publickey(
            base64_decode(Storage::disk('local')->get(self::KEY_PATH), true),
        );
    }
}
