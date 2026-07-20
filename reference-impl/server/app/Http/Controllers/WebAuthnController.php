<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserCredential;
use App\Services\AuditLogger;
use App\Services\PasskeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WebAuthnController
{
    public function __construct(
        private readonly PasskeyService $passkeys,
        private readonly AuditLogger $audit,
    ) {
    }

    public function registerOptions(Request $request): JsonResponse
    {
        $result = $this->passkeys->registrationOptions($request->user(), $request->getHost());

        return response()->json([
            'challenge_id' => $result['challenge_id'],
            'options' => json_decode($result['options'], true),
        ]);
    }

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'credential' => ['required', 'array'],
            'nickname' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        try {
            $credential = $this->passkeys->verifyRegistration(
                $request->user(),
                json_encode($data['credential'], JSON_THROW_ON_ERROR),
                $request->getHost(),
                $data['nickname'] ?? null,
            );
        } catch (\Throwable) {
            return response()->json(['error' => 'registration_failed'], 422); // fail-closed, no detail
        }

        if (($vault = $request->user()->vault) !== null) {
            $this->audit->record($vault, 'credential.added', actor: $request->user(), context: [
                'credential_id' => $credential->credential_id,
            ]);
        }

        return response()->json(['id' => $credential->id, 'credential_id' => $credential->credential_id], 201);
    }

    public function loginOptions(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);

        $user = User::query()->where('email', $data['email'])->first();
        $result = $this->passkeys->loginOptions($user, $request->getHost());

        // Identical shape whether or not the account exists (no oracle).
        return response()->json([
            'challenge_id' => $result['challenge_id'],
            'options' => json_decode($result['options'], true),
        ]);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'credential' => ['required', 'array'],
        ]);

        $user = User::query()->where('email', $data['email'])->first();
        $authenticated = $user === null ? null : $this->passkeys->verifyLogin(
            $user,
            json_encode($data['credential'], JSON_THROW_ON_ERROR),
            $request->getHost(),
        );

        if ($authenticated === null) {
            // One generic failure for unknown email, bad assertion, replayed
            // challenge, revoked credential, or counter regression.
            return response()->json(['error' => 'authentication_failed'], 403);
        }

        return response()->json(['token' => $authenticated->createToken('full')->plainTextToken]);
    }

    public function revoke(Request $request, UserCredential $credential): JsonResponse
    {
        if ($credential->user_id !== $request->user()->id) {
            abort(404);
        }

        $credential->delete();

        if (($vault = $request->user()->vault) !== null) {
            $this->audit->record($vault, 'credential.revoked', actor: $request->user(), context: [
                'credential_id' => $credential->credential_id,
            ]);
        }

        return response()->json(['revoked' => true]);
    }
}
