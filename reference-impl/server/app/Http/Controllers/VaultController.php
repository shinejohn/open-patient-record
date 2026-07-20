<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesGrantTokens;
use App\Models\Vault;
use App\Services\AuditLogger;
use App\Services\VaultService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class VaultController
{
    use ResolvesGrantTokens;

    public function __construct(
        private readonly VaultService $vaults,
        private readonly AuditLogger $audit,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        if ($this->isGrantToken($request)) {
            abort(403, 'forbidden');
        }

        $user = $request->user();
        if ($user->vault()->exists()) {
            return response()->json(['error' => 'vault_exists'], 409);
        }

        $vault = Vault::query()->create(['subject_user_id' => $user->id]);

        return response()->json([
            'id' => $vault->id,
            'subject_user_id' => $vault->subject_user_id,
            'chain_head_hash' => $vault->chain_head_hash,
        ], 201);
    }

    public function show(Request $request, Vault $vault): JsonResponse
    {
        $this->assertSubject($request, $vault);

        return response()->json([
            'id' => $vault->id,
            'subject_user_id' => $vault->subject_user_id,
            'chain_head_hash' => $vault->chain_head_hash,
            'entry_count' => $vault->entry_count,
        ]);
    }

    /** Complete export incl. chain head and audit history (spec §7.1). Free, subject-only. */
    public function export(Request $request, Vault $vault): JsonResponse
    {
        $this->assertSubject($request, $vault);

        $payload = $this->vaults->export($vault);
        $this->audit->record($vault, 'export', actor: $request->user());

        return response()->json($payload);
    }

    public function verify(Request $request, Vault $vault): JsonResponse
    {
        $this->assertSubject($request, $vault);

        $result = $this->vaults->verifyChain($vault);
        $this->audit->record($vault, 'verify', actor: $request->user(), context: $result);

        return response()->json($result);
    }

    /**
     * Merkle inclusion proof for this vault against the latest published witness
     * digest (spec §4.5): lets the subject verify — with only the PUBLIC log —
     * that their vault's history is committed to by the custodian's signature.
     */
    public function witnessProof(Request $request, Vault $vault): JsonResponse
    {
        $this->assertSubject($request, $vault);

        $proof = app(\App\Services\WitnessService::class)->proof($vault->id);
        if ($proof === null) {
            return response()->json(['error' => 'no_digest_covers_this_vault_yet'], 404);
        }

        return response()->json($proof);
    }

    /** The subject's right to review all access, at no charge (spec §6). */
    public function audit(Request $request, Vault $vault): JsonResponse
    {
        $this->assertSubject($request, $vault);

        return response()->json([
            'events' => $vault->auditEvents()->get()->map(fn ($e) => [
                'action' => $e->action,
                'purpose' => $e->purpose,
                'grant_id' => $e->grant_id,
                'is_emergency' => $e->is_emergency,
                'reason' => $e->reason,
                'context' => $e->context,
                'created_at' => $e->created_at?->toIso8601String(),
            ])->all(),
        ]);
    }
}
