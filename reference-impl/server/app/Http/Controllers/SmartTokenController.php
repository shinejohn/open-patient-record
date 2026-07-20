<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\SmartService;
use App\Support\RsaKeyStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class SmartTokenController
{
    public function __construct(private readonly SmartService $smart)
    {
    }

    /** POST /oauth/token — one generic error for every failure mode (no oracle). */
    public function token(Request $request): JsonResponse
    {
        $data = $request->validate([
            'grant_type' => ['required', 'in:authorization_code,refresh_token'],
            'client_id' => ['required', 'uuid'],
            'client_secret' => ['sometimes', 'string'],
            'code' => ['required_if:grant_type,authorization_code', 'string', 'max:128'],
            'code_verifier' => ['required_if:grant_type,authorization_code', 'string', 'max:128'],
            'redirect_uri' => ['required_if:grant_type,authorization_code', 'string', 'max:2048'],
            'refresh_token' => ['required_if:grant_type,refresh_token', 'string', 'max:128'],
        ]);

        $client = DB::table('oauth_clients')->where('id', $data['client_id'])->first();
        $secretOk = $client !== null && (
            ! $client->confidential
            || Hash::check($data['client_secret'] ?? '', $client->secret_hash ?? Hash::make(bin2hex(random_bytes(8))))
        );

        if ($client === null || ! $secretOk) {
            return response()->json(['error' => 'invalid_grant'], 400);
        }

        $result = $data['grant_type'] === 'authorization_code'
            ? $this->smart->exchangeCode(
                $client,
                $data['code'],
                $data['code_verifier'],
                $data['redirect_uri'],
                wantsIdToken: true,
            )
            : $this->smart->refresh($client, $data['refresh_token']);

        if ($result === null) {
            return response()->json(['error' => 'invalid_grant'], 400);
        }

        return response()->json($result);
    }

    /** GET /api/oauth/jwks — public keys for id_token verification. */
    public function jwks(): JsonResponse
    {
        return response()->json(['keys' => [RsaKeyStore::jwk()]]);
    }

    /** GET /api/fhir/{vault}/.well-known/smart-configuration */
    public function smartConfiguration(): JsonResponse
    {
        return response()->json([
            'issuer' => url('/'),
            'jwks_uri' => url('/api/oauth/jwks'),
            'authorization_endpoint' => url('/oauth/authorize'),
            'token_endpoint' => url('/oauth/token'),
            'grant_types_supported' => ['authorization_code'],
            'code_challenge_methods_supported' => ['S256'],
            'scopes_supported' => ['openid', 'fhirUser', 'offline_access', 'patient/*.read'],
            'response_types_supported' => ['code'],
            'capabilities' => [
                'launch-standalone', 'client-public', 'client-confidential-symmetric',
                'permission-patient', 'permission-offline',
            ],
        ]);
    }
}
