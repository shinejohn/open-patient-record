<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BackendClient;
use App\Support\JwtVerifier;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * SMART Backend Services (HL7 "Bulk Data" system-to-system auth): a registered
 * client authenticates with a private-key JWT (`client_assertion`) instead of a
 * shared secret. This service verifies that assertion against the client's
 * registered public JWK and enforces one-time use of its `jti` — the whole
 * point of asymmetric client auth is that nothing here is a bearer secret that
 * could leak; the only thing that can go wrong is a captured assertion being
 * replayed, which the `jti` table forecloses.
 *
 * Null return = fail-closed, one generic error for every failure mode (same
 * no-oracle discipline as the rest of the token endpoint).
 */
final class BackendClientService
{
    private const MAX_ASSERTION_LIFETIME_SECONDS = 300; // HL7 Backend Services: <= 5 minutes

    public function authenticate(string $clientAssertion, string $tokenEndpoint): ?BackendClient
    {
        try {
            ['header' => $header, 'payload' => $payload, 'signingInput' => $signingInput, 'signature' => $signature]
                = JwtVerifier::decodeUnsafe($clientAssertion);
        } catch (\Throwable) {
            return null;
        }

        if (($header['alg'] ?? null) !== 'RS256') {
            return null;
        }

        $iss = $payload['iss'] ?? null;
        $sub = $payload['sub'] ?? null;
        $aud = $payload['aud'] ?? null;
        $exp = $payload['exp'] ?? null;
        $iat = $payload['iat'] ?? null;
        $jti = $payload['jti'] ?? null;

        if (! is_string($iss) || ! is_string($sub) || $iss !== $sub || ! is_string($jti) || $jti === ''
            || ! is_int($exp) || ! is_int($iat)) {
            return null;
        }

        // aud may be a single string or an array of audiences per JWT RFC 7519 §4.1.3.
        $audiences = is_array($aud) ? $aud : [$aud];
        if (! in_array($tokenEndpoint, $audiences, true)) {
            return null;
        }

        $now = now()->getTimestamp();
        if ($exp <= $now || $exp - $iat > self::MAX_ASSERTION_LIFETIME_SECONDS) {
            return null;
        }

        /** @var BackendClient|null $client */
        $client = BackendClient::query()->find($iss);
        if ($client === null || ! $client->isActive()) {
            return null;
        }

        if (! JwtVerifier::verifyRs256($signingInput, $signature, $client->jwk)) {
            return null;
        }

        if (! $this->consumeJti($client->id, $jti, $exp)) {
            return null; // replay
        }

        return $client;
    }

    /** True if this (client, jti) pair had not been used before. */
    private function consumeJti(string $clientId, string $jti, int $expiresAtTimestamp): bool
    {
        try {
            DB::table('backend_client_assertions')->insert([
                'id' => (string) Str::uuid7(),
                'client_id' => $clientId,
                'jti' => $jti,
                'expires_at' => now()->setTimestamp($expiresAtTimestamp),
                'created_at' => now(),
            ]);

            return true;
        } catch (QueryException) {
            return false; // unique(client_id, jti) violated — replay
        }
    }
}
