<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AccessGrant;
use App\Models\AuditEvent;
use App\Models\User;
use App\Models\Vault;
use App\Models\VaultEntry;
use App\Support\Canonicalizer;
use App\Support\EnvelopeCrypto;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class VaultService
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    /**
     * Commit an entry (spec §2.4, §4.2). Runs in a transaction with the vault row
     * locked so concurrent commits cannot fork the chain.
     *
     * @param array{resource_type: string, payload: array<mixed>, verification_tier: string,
     *              sensitive_category?: ?string, replaces_entry_id?: ?string,
     *              provenance: array<mixed>} $data
     */
    public function commitEntry(Vault $vault, User $contributor, array $data, ?AccessGrant $grant = null): VaultEntry
    {
        if (! in_array($data['verification_tier'], VaultEntry::TIERS, true)) {
            throw new InvalidArgumentException('Invalid verification_tier.');
        }
        if ($data['provenance'] === [] || ! isset($data['provenance']['organization'])) {
            throw new InvalidArgumentException('Provenance with an organization is mandatory (spec §5).');
        }

        return DB::transaction(function () use ($vault, $contributor, $data, $grant): VaultEntry {
            /** @var Vault $locked */
            $locked = Vault::query()->whereKey($vault->id)->lockForUpdate()->firstOrFail();

            if (isset($data['replaces_entry_id']) && $data['replaces_entry_id'] !== null) {
                $replaced = VaultEntry::query()->whereKey($data['replaces_entry_id'])->first();
                if ($replaced === null || $replaced->vault_id !== $locked->id) {
                    throw new InvalidArgumentException('replaces_entry_id must reference an entry in the same vault.');
                }
            }

            // Hashes are computed over the PLAINTEXT canonical form (portability:
            // hashes must verify on any custodian, whatever its at-rest scheme).
            $contentHash = Canonicalizer::contentHash($data['payload']);
            $chainHash = Canonicalizer::chainHash($locked->chain_head_hash, $contentHash);

            $entry = VaultEntry::query()->create([
                'vault_id' => $locked->id,
                'seq' => $locked->entry_count + 1,
                'resource_type' => $data['resource_type'],
                'payload' => EnvelopeCrypto::encrypt($data['payload'], $locked->dek()),
                'verification_tier' => $data['verification_tier'],
                'sensitive_category' => $data['sensitive_category'] ?? null,
                'replaces_entry_id' => $data['replaces_entry_id'] ?? null,
                'content_hash' => $contentHash,
                'chain_hash' => $chainHash,
                'contributor_user_id' => $contributor->id,
                'provenance' => $data['provenance'],
            ]);

            $locked->forceFill([
                'entry_count' => $locked->entry_count + 1,
                'chain_head_hash' => $chainHash,
            ])->save();

            $this->audit->record($locked, 'entry.committed', actor: $contributor, grant: $grant, context: [
                'entry_id' => $entry->id,
                'resource_type' => $entry->resource_type,
                'seq' => $entry->seq,
            ]);

            return $entry;
        });
    }

    /**
     * Chain verification (spec §4.2, §4.5): recompute every content and chain hash
     * and compare against stored values and the vault's chain head.
     *
     * @return array{valid: bool, entries: int, first_invalid_seq: ?int, chain_head_matches: bool}
     */
    public function verifyChain(Vault $vault): array
    {
        $previous = null;
        $firstInvalid = null;
        $count = 0;
        $lastChainHash = null;

        foreach ($vault->entries()->cursor() as $entry) {
            $count++;

            try {
                // An undecryptable/corrupt payload IS tamper evidence — report
                // invalid at this entry rather than erroring out (fail-closed).
                $expectedContent = Canonicalizer::contentHash($entry->payload);
            } catch (\Throwable) {
                $expectedContent = '<undecryptable>';
            }
            $expectedChain = Canonicalizer::chainHash($previous, $expectedContent);

            if ($firstInvalid === null
                && ($expectedContent !== $entry->content_hash || $expectedChain !== $entry->chain_hash)) {
                $firstInvalid = (int) $entry->seq;
            }

            $previous = $entry->chain_hash;
            $lastChainHash = $entry->chain_hash;
        }

        $headMatches = $vault->chain_head_hash === $lastChainHash;

        return [
            'valid' => $firstInvalid === null && $headMatches,
            'entries' => $count,
            'first_invalid_seq' => $firstInvalid,
            'chain_head_matches' => $headMatches,
        ];
    }

    /**
     * Entries visible to a caller. Subject access ($grant === null) sees everything.
     * Grant access is filtered by scope, and sensitive-category entries are excluded
     * unless the grant explicitly includes that exact category — unknown categories
     * are excluded by construction (spec §3.2).
     *
     * @return Builder<VaultEntry>
     */
    public function visibleEntries(Vault $vault, ?AccessGrant $grant): Builder
    {
        $query = VaultEntry::query()->where('vault_id', $vault->id)->orderBy('seq');

        if ($grant === null) {
            return $query;
        }

        if (! in_array('*', $grant->scope, true)) {
            $query->whereIn('resource_type', $grant->scope);
        }

        $included = $grant->sensitive_categories;
        $query->where(function (Builder $q) use ($included): void {
            $q->whereNull('sensitive_category');
            if ($included !== []) {
                $q->orWhereIn('sensitive_category', $included);
            }
        });

        return $query;
    }

    /**
     * Complete export with chain head (spec §7.1, §4.5). Subject-only at the
     * controller layer.
     *
     * @return array<string, mixed>
     */
    public function export(Vault $vault): array
    {
        return [
            'opr_export_version' => '0.1',
            'vault_id' => $vault->id,
            'chain_head_hash' => $vault->chain_head_hash,
            'entry_count' => $vault->entry_count,
            'entries' => $vault->entries()->get()->map(fn (VaultEntry $e) => [
                'id' => $e->id,
                'seq' => $e->seq,
                'resource_type' => $e->resource_type,
                'payload' => $e->payload,
                'verification_tier' => $e->verification_tier,
                'sensitive_category' => $e->sensitive_category,
                'replaces_entry_id' => $e->replaces_entry_id,
                'content_hash' => $e->content_hash,
                'chain_hash' => $e->chain_hash,
                'provenance' => $e->provenance,
                'created_at' => $e->created_at?->toIso8601String(),
            ])->all(),
            'audit_events' => $vault->auditEvents()->get()->map(fn (AuditEvent $a) => [
                'action' => $a->action,
                'purpose' => $a->purpose,
                'is_emergency' => $a->is_emergency,
                'grant_id' => $a->grant_id,
                'context' => $a->context,
                'created_at' => $a->created_at?->toIso8601String(),
            ])->all(),
        ];
    }
}
