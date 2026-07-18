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
