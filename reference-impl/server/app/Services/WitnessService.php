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
    private const LEAVES_PATH = 'witness/leaves-%d.json';

    /** @return array<string, mixed> the published record */
    public function publish(): array
    {
        $headsByVault = Vault::query()
            ->whereNotNull('chain_head_hash')
            ->orderBy('id')
            ->pluck('chain_head_hash', 'id')
            ->all();
        $heads = array_values($headsByVault);

        // The SIGNED portion is exactly this triple; metadata below is unsigned.
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
        $record['type'] = 'digest';

        $seq = count($this->log()) + 1;
        $record['seq'] = $seq;

        // Server-side leaves snapshot (vault→head at publish time) so inclusion
        // proofs can be generated later. Not exposed publicly as a bulk map.
        Storage::disk('local')->put(
            sprintf(self::LEAVES_PATH, $seq),
            json_encode($headsByVault, JSON_UNESCAPED_SLASHES),
        );

        Storage::disk('local')->append(self::LOG_PATH, json_encode($record, JSON_UNESCAPED_SLASHES));

        return $record;
    }

    /**
     * Merkle inclusion proof for one vault against the most recent published
     * digest: the sibling path from sha256(chain_head_at_publish) up to the signed
     * root. Anyone can verify with only the public witness log — no server trust.
     *
     * @return array<string, mixed>|null null if no digest covers this vault yet
     */
    public function proof(string $vaultId): ?array
    {
        $digests = array_values(array_filter(
            $this->log(),
            fn (array $r): bool => ($r['type'] ?? 'digest') === 'digest',
        ));
        if ($digests === []) {
            return null;
        }
        $latest = end($digests);

        $leavesPath = sprintf(self::LEAVES_PATH, $latest['seq'] ?? count($digests));
        if (! Storage::disk('local')->exists($leavesPath)) {
            return null;
        }

        /** @var array<string, string> $headsByVault */
        $headsByVault = json_decode(Storage::disk('local')->get($leavesPath), true, 512, JSON_THROW_ON_ERROR);
        if (! isset($headsByVault[$vaultId])) {
            return null; // vault created (or first-committed) after the last publish
        }

        $heads = array_values($headsByVault);
        sort($heads);
        $level = array_map(fn (string $h): string => hash('sha256', $h), $heads);
        $index = array_search(hash('sha256', $headsByVault[$vaultId]), $level, true);
        if ($index === false) {
            return null;
        }

        $path = [];
        while (count($level) > 1) {
            $sibling = ($index % 2 === 0) ? $index + 1 : $index - 1;
            if ($sibling < count($level)) {
                $path[] = [
                    'hash' => $level[$sibling],
                    'position' => $sibling < $index ? 'left' : 'right',
                ];
            }
            // else: odd last node promotes — no sibling at this level.

            $next = [];
            for ($i = 0, $n = count($level); $i < $n; $i += 2) {
                $next[] = $i + 1 < $n ? hash('sha256', $level[$i].$level[$i + 1]) : $level[$i];
            }
            $level = $next;
            $index = intdiv($index, 2);
        }

        return [
            'chain_head_at_publish' => $headsByVault[$vaultId],
            'proof' => $path,
            'merkle_root' => $latest['merkle_root'],
            'published_at' => $latest['published_at'],
            'signature' => $latest['signature'],
            'public_key' => $latest['public_key'],
        ];
    }

    /**
     * Recompute the root from a proof — the client-side verification algorithm.
     * Leaf = sha256(chain head); fold each sibling per its position; compare roots.
     *
     * @param list<array{hash: string, position: 'left'|'right'}> $path
     */
    public static function verifyProof(string $chainHead, array $path, string $expectedRoot): bool
    {
        $hash = hash('sha256', $chainHead);

        foreach ($path as $step) {
            $hash = $step['position'] === 'left'
                ? hash('sha256', $step['hash'].$hash)
                : hash('sha256', $hash.$step['hash']);
        }

        return hash_equals($expectedRoot, $hash);
    }

    /**
     * Witness-key rotation (key-management doc §4): publish a rollover statement
     * signed by BOTH the outgoing and incoming keys, so the log itself proves
     * continuity of the signer, then swap the key.
     *
     * @return array<string, mixed> the published rollover record
     */
    public function rotateKey(): array
    {
        $oldSecret = $this->secretKey();
        $oldPublic = $this->publicKey();

        $newPair = sodium_crypto_sign_keypair();
        $newSecret = sodium_crypto_sign_secretkey($newPair);
        $newPublic = sodium_crypto_sign_publickey($newPair);

        $statement = [
            'new_public_key' => base64_encode($newPublic),
            'published_at' => now()->toIso8601String(),
            'type' => 'key-rollover',
        ];
        $canonical = Canonicalizer::canonicalize($statement);

        $record = $statement + [
            'old_public_key' => base64_encode($oldPublic),
            'old_signature' => base64_encode(sodium_crypto_sign_detached($canonical, $oldSecret)),
            'new_signature' => base64_encode(sodium_crypto_sign_detached($canonical, $newSecret)),
        ];

        Storage::disk('local')->put(self::KEY_PATH, base64_encode($newPair));
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
