<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\BackendClientService;
use App\Services\SmartService;
use App\Support\RsaKeyStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;

final class SmartTokenController
{
    public function __construct(
        private readonly SmartService $smart,
        private readonly BackendClientService $backend,
    ) {
    }

    /** POST /oauth/token — one generic error for every failure mode (no oracle). */
    public function token(Request $request): JsonResponse
    {
        $data = $request->validate([
            'grant_type' => ['required', 'in:authorization_code,refresh_token,client_credentials'],
            'client_id' => ['required_unless:grant_type,client_credentials', 'uuid'],
            'client_secret' => ['sometimes', 'string'],
            'code' => ['required_if:grant_type,authorization_code', 'string', 'max:128'],
            'code_verifier' => ['required_if:grant_type,authorization_code', 'string', 'max:128'],
            'redirect_uri' => ['required_if:grant_type,authorization_code', 'string', 'max:2048'],
            'refresh_token' => ['required_if:grant_type,refresh_token', 'string', 'max:128'],
            'client_assertion_type' => [
                'required_if:grant_type,client_credentials',
                'in:urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
            ],
            'client_assertion' => ['required_if:grant_type,client_credentials', 'string'],
            'scope' => ['required_if:grant_type,client_credentials', 'string'],
        ]);

        if ($data['grant_type'] === 'client_credentials') {
            return $this->clientCredentials($data);
        }

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

    /**
     * SMART Backend Services (HL7 Bulk Data auth): client_credentials + a
     * private-key JWT client_assertion instead of a shared secret. One generic
     * error for every failure mode — same no-oracle discipline as the rest of
     * this endpoint.
     *
     * @param array<string, mixed> $data
     */
    private function clientCredentials(array $data): JsonResponse
    {
        $client = $this->backend->authenticate($data['client_assertion'], url('/oauth/token'));
        if ($client === null) {
            return response()->json(['error' => 'invalid_client'], 400);
        }

        try {
            $requested = $this->smart->parseSystemScopes($data['scope']);
        } catch (InvalidArgumentException) {
            return response()->json(['error' => 'invalid_scope'], 400);
        }

        $effective = match (true) {
            in_array('*', $requested, true) => $client->scope,
            in_array('*', $client->scope, true) => $requested,
            default => array_values(array_intersect($requested, $client->scope)),
        };

        if ($effective === []) {
            return response()->json(['error' => 'invalid_scope'], 400);
        }

        $grant = $this->smart->mintSystemGrant($client->vault, $client, $effective);

        return response()->json($this->smart->issueSystemToken($grant));
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
            'grant_types_supported' => ['authorization_code', 'client_credentials'],
            'code_challenge_methods_supported' => ['S256'],
            'scopes_supported' => ['openid', 'fhirUser', 'offline_access', 'patient/*.read', 'system/*.read'],
            'response_types_supported' => ['code'],
            'token_endpoint_auth_methods_supported' => ['client_secret_post', 'private_key_jwt'],
            'token_endpoint_auth_signing_alg_values_supported' => ['RS256'],
            'capabilities' => [
                'launch-standalone', 'client-public', 'client-confidential-symmetric',
                'client-confidential-asymmetric', 'permission-patient', 'permission-offline',
                'permission-v2', 'permission-backend-services',
            ],
        ]);
    }
}
