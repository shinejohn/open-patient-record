<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesGrantTokens;
use App\Models\AccessGrant;
use App\Models\Vault;
use App\Services\GrantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class GrantController
{
    use ResolvesGrantTokens;

    public function __construct(private readonly GrantService $grants)
    {
    }

    /** Only the subject may mint (spec §3.1) — and never via a grant-derived token. */
    public function store(Request $request, Vault $vault): JsonResponse
    {
        $this->assertSubject($request, $vault);

        $data = $request->validate([
            'purpose' => ['required', 'string', 'in:'.implode(',', AccessGrant::PURPOSES)],
            'scope' => ['required', 'array', 'min:1'],
            'scope.*' => ['string'],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['string'],
            'sensitive_categories' => ['sometimes', 'array'],
            'sensitive_categories.*' => ['string'],
            'expires_in_minutes' => ['sometimes', 'integer', 'min:1'],
            'max_uses' => ['sometimes', 'integer', 'min:1'],
            'emergency_reason' => ['sometimes', 'nullable', 'string'],
        ]);

        $result = $this->grants->mint($vault, $request->user(), $data);

        return response()->json([
            'grant_id' => $result['grant']->id,
            'pseudo_id' => $result['grant']->pseudo_id,
            // Plaintext secret: returned exactly once, never retrievable again.
            'otp' => $result['otp'],
            'expires_at' => $result['grant']->expires_at->toIso8601String(),
        ], 201);
    }

    /** ShareSession (spec §3.6): the QR flow. Subject-only; constraints server-enforced. */
    public function shareSession(Request $request, Vault $vault): JsonResponse
    {
        $this->assertSubject($request, $vault);

        $data = $request->validate([
            'scope' => ['sometimes', 'array', 'min:1'],
            'scope.*' => ['string'],
            'sensitive_categories' => ['sometimes', 'array'],
            'sensitive_categories.*' => ['string'],
            'expires_in_minutes' => ['sometimes', 'integer', 'min:1'],
        ]);

        $result = $this->grants->mintShareSession($vault, $request->user(), $data);

        return response()->json([
            'grant_id' => $result['grant']->id,
            'share_code' => $result['share_code'], // QR content; shown exactly once
            'expires_at' => $result['grant']->expires_at->toIso8601String(),
        ], 201);
    }

    /**
     * Break-glass (spec §3.7). Deliberately available to any AUTHENTICATED user
     * with a recorded reason — that is how clinical break-glass works: the barrier
     * is accountability (identity + reason + subject-visible audit), not a lock
     * that also stops the ER at 3am.
     */
    public function breakGlass(Request $request, Vault $vault): JsonResponse
    {
        if ($this->isGrantToken($request)) {
            abort(403, 'forbidden');
        }

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        $result = $this->grants->breakGlass($vault, $request->user(), $data['reason']);

        return response()->json($result, 201);
    }

    public function revoke(Request $request, Vault $vault, AccessGrant $grant): JsonResponse
    {
        $this->assertSubject($request, $vault);

        if ($grant->vault_id !== $vault->id) {
            abort(404);
        }

        $this->grants->revoke($grant, $request->user());

        return response()->json(['revoked' => true]);
    }
}
