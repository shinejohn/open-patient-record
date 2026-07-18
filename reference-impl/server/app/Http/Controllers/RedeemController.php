<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\GrantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RedeemController
{
    public function __construct(private readonly GrantService $grants)
    {
    }

    /**
     * Unauthenticated by design, rate-limited at the route (spec §3.3).
     * EVERY failure mode returns this one identical payload — no oracle
     * distinguishing unknown handle, wrong secret, revoked, expired, or exhausted.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pseudo_id' => ['required', 'string', 'max:40'],
            'otp' => ['required', 'string', 'max:16'],
        ]);

        $result = $this->grants->redeem($data['pseudo_id'], $data['otp']);

        if ($result === null) {
            return response()->json(['error' => 'invalid_grant'], 403);
        }

        return response()->json($result);
    }
}
