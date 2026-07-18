<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesGrantTokens;
use App\Models\Vault;
use App\Models\VaultEntry;
use App\Services\AuditLogger;
use App\Services\VaultService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EntryController
{
    use ResolvesGrantTokens;

    public function __construct(
        private readonly VaultService $vaults,
        private readonly AuditLogger $audit,
    ) {
    }

    public function index(Request $request, Vault $vault): JsonResponse
    {
        $grant = $this->currentGrant($request);

        if ($grant === null) {
            $this->assertSubject($request, $vault);
        } else {
            $this->assertGrantCovers($request, $grant, $vault, 'read');
        }

        $entries = $this->vaults->visibleEntries($vault, $grant)->get();

        $this->audit->record($vault, 'entries.read', actor: $request->user(), grant: $grant, context: [
            'returned' => $entries->count(),
        ]);

        return response()->json([
            'chain_head_hash' => $vault->chain_head_hash, // sync responses carry the head (spec §4.5)
            'entries' => $entries->map(fn (VaultEntry $e) => [
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
        ]);
    }

    public function store(Request $request, Vault $vault): JsonResponse
    {
        $data = $request->validate([
            'resource_type' => ['required', 'string', 'max:64'],
            'payload' => ['required', 'array'],
            'verification_tier' => ['required', 'in:verified-source,clinician-verified,unverified-import'],
            'sensitive_category' => ['nullable', 'string', 'max:64'],
            'replaces_entry_id' => ['nullable', 'uuid'],
            'provenance' => ['required', 'array'],
            'provenance.organization' => ['required', 'string'],
        ]);

        $grant = $this->currentGrant($request);

        if ($grant === null) {
            $this->assertSubject($request, $vault);
        } else {
            $this->assertGrantCovers($request, $grant, $vault, 'write');
            if (! $grant->coversResourceType($data['resource_type'])) {
                abort(403, 'forbidden');
            }
        }

        try {
            $entry = $this->vaults->commitEntry($vault, $request->user(), $data, $grant);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => 'invalid_entry', 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'id' => $entry->id,
            'seq' => $entry->seq,
            'content_hash' => $entry->content_hash,
            'chain_hash' => $entry->chain_hash,
        ], 201);
    }

    /** Spec §4.1: committed entries reject mutation with a defined OperationOutcome. */
    public function reject(): JsonResponse
    {
        return response()->json([
            'resourceType' => 'OperationOutcome',
            'issue' => [[
                'severity' => 'error',
                'code' => 'business-rule',
                'diagnostics' => 'Committed entries are append-only (OPR spec §4.1). Commit a superseding entry (§2.4) instead of modifying or deleting.',
            ]],
        ], 405);
    }
}
