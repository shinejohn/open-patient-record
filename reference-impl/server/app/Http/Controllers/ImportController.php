<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesGrantTokens;
use App\Models\Vault;
use App\Models\VaultEntry;
use App\Services\AuditLogger;
use App\Support\Canonicalizer;
use App\Support\EnvelopeCrypto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Portability import — the receiving half of spec §7.2/§7.3. A subject imports a
 * complete OPR export into their (empty) vault at a new custodian. Every content
 * hash and chain link is RECOMPUTED and checked against the export's declared
 * values, so tampering in transit is detected, and the replayed chain must land
 * exactly on the source's terminal chain head — that equality IS the §7.3 anchor:
 * record integrity provably survived the move.
 *
 * Local attribution note: contributor_user_id is a local FK and maps to the
 * importing subject; durable attribution lives in each entry's provenance (spec §5),
 * which is preserved byte-for-byte.
 *
 * Identity note: entry ids are custodian-scoped (as FHIR resource ids are
 * server-scoped) and are REMAPPED on import; durable identity is content + chain
 * (the hashes, which must replay exactly). Supersession links are remapped through
 * the same id map — a link pointing outside the batch is an incomplete export and
 * is rejected.
 */
final class ImportController
{
    use ResolvesGrantTokens;

    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function store(Request $request, Vault $vault): JsonResponse
    {
        $this->assertSubject($request, $vault);

        $data = $request->validate([
            'opr_export_version' => ['required', 'in:0.1'],
            'chain_head_hash' => ['required', 'string', 'size:64'],
            'entries' => ['required', 'array', 'min:1'],
            'entries.*.id' => ['required', 'uuid'],
            'entries.*.seq' => ['required', 'integer', 'min:1'],
            'entries.*.resource_type' => ['required', 'string', 'max:64'],
            'entries.*.payload' => ['required', 'array'],
            'entries.*.verification_tier' => ['required', 'in:verified-source,clinician-verified,unverified-import'],
            'entries.*.sensitive_category' => ['nullable', 'string', 'max:64'],
            'entries.*.replaces_entry_id' => ['nullable', 'uuid'],
            'entries.*.content_hash' => ['required', 'string', 'size:64'],
            'entries.*.chain_hash' => ['required', 'string', 'size:64'],
            'entries.*.provenance' => ['required', 'array'],
            'entries.*.created_at' => ['required', 'date'],
        ]);

        if ($vault->entry_count > 0) {
            return response()->json(['error' => 'vault_not_empty'], 409);
        }

        $entries = collect($data['entries'])->sortBy('seq')->values();

        // Sequence must be exactly 1..n — a gap means an incomplete export.
        foreach ($entries as $i => $e) {
            if ($e['seq'] !== $i + 1) {
                return response()->json(['error' => 'invalid_import', 'message' => 'Entry sequence has gaps.'], 422);
            }
        }

        try {
            DB::transaction(function () use ($vault, $entries, $data, $request): void {
                /** @var Vault $locked */
                $locked = Vault::query()->whereKey($vault->id)->lockForUpdate()->firstOrFail();
                if ($locked->entry_count > 0) {
                    throw new \RuntimeException('vault_not_empty');
                }

                $dek = $locked->dek();
                $prev = null;

                // Custodian-scoped ids: fresh UUIDs here, supersession remapped through the map.
                $idMap = [];
                foreach ($entries as $e) {
                    $idMap[$e['id']] = (string) \Illuminate\Support\Str::uuid7();
                }

                foreach ($entries as $e) {
                    // Recompute BOTH hashes — never trust declared integrity values.
                    $contentHash = Canonicalizer::contentHash($e['payload']);
                    $chainHash = Canonicalizer::chainHash($prev, $contentHash);

                    if ($contentHash !== $e['content_hash']) {
                        throw new \RuntimeException("content_mismatch:{$e['seq']}");
                    }
                    if ($chainHash !== $e['chain_hash']) {
                        throw new \RuntimeException("chain_mismatch:{$e['seq']}");
                    }

                    $replaces = $e['replaces_entry_id'] ?? null;
                    if ($replaces !== null && ! isset($idMap[$replaces])) {
                        throw new \RuntimeException("dangling_supersession:{$e['seq']}");
                    }

                    (new VaultEntry)->forceFill([
                        'id' => $idMap[$e['id']],
                        'vault_id' => $locked->id,
                        'seq' => $e['seq'],
                        'resource_type' => $e['resource_type'],
                        'payload' => EnvelopeCrypto::encrypt($e['payload'], $dek),
                        'verification_tier' => $e['verification_tier'],
                        'sensitive_category' => $e['sensitive_category'] ?? null,
                        'replaces_entry_id' => $replaces === null ? null : $idMap[$replaces],
                        'content_hash' => $contentHash,
                        'chain_hash' => $chainHash,
                        'contributor_user_id' => $request->user()->id,
                        'provenance' => $e['provenance'],
                        'created_at' => $e['created_at'],
                    ])->save();

                    $prev = $chainHash;
                }

                // §7.3 anchoring: the replayed chain MUST land on the source's head.
                if ($prev !== $data['chain_head_hash']) {
                    throw new \RuntimeException('anchor_mismatch');
                }

                $locked->forceFill([
                    'entry_count' => $entries->count(),
                    'chain_head_hash' => $prev,
                ])->save();

                $this->audit->record($locked, 'vault.imported', actor: $request->user(), context: [
                    'source_chain_head' => $data['chain_head_hash'],
                    'entries' => $entries->count(),
                ]);
            });
        } catch (\RuntimeException $e) {
            $status = $e->getMessage() === 'vault_not_empty' ? 409 : 422;

            return response()->json(['error' => 'invalid_import', 'message' => $e->getMessage()], $status);
        }

        return response()->json([
            'imported' => $entries->count(),
            'chain_head_hash' => $data['chain_head_hash'],
            'anchored' => true,
        ], 201);
    }
}
