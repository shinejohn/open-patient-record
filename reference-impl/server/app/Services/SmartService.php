<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AccessGrant;
use App\Models\User;
use App\Models\Vault;
use App\Support\RsaKeyStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * SMART-on-FHIR standalone launch, built on the one load-bearing decision:
 * a SMART app authorization IS an AccessGrant. Approving an app mints a grant;
 * access tokens derive from the grant exactly like OTP-redeemed tokens; revoking
 * the grant revokes the app. One consent model, one revocation model, one audit
 * trail.
 *
 * Deviation from the original plan (league/oauth2-server): the auth-code + PKCE
 * machinery is implemented natively, because league would mint its own JWT access
 * tokens disconnected from the grant/Sanctum model this whole design hinges on.
 * PKCE is one SHA-256; the security-critical parsing (WebAuthn) stayed a library.
 */
final class SmartService
{
    private const CODE_TTL_MINUTES = 5;

    private const ACCESS_TTL_MINUTES = 30;   // same cap as every grant-derived token

    private const REFRESH_TTL_DAYS = 90;

    public function __construct(private readonly AuditLogger $audit)
    {
    }

    /**
     * Map SMART scopes → OPR grant shape. v1 (.read) and v2 (.rs) accepted;
     * anything requesting write is rejected (read-only v1 surface).
     *
     * @return array{scope: array<string>, offline: bool, openid: bool}
     */
    public function parseScopes(string $scopeString): array
    {
        $resourceTypes = [];
        $offline = false;
        $openid = false;

        foreach (preg_split('/\s+/', trim($scopeString), flags: PREG_SPLIT_NO_EMPTY) as $scope) {
            if ($scope === 'offline_access') {
                $offline = true;
            } elseif ($scope === 'openid' || $scope === 'fhirUser') {
                $openid = true;
            } elseif (preg_match('#\Apatient/(\*|[A-Z][A-Za-z0-9]*)\.(read|rs)\z#', $scope, $m) === 1) {
                $resourceTypes[] = $m[1];
            } else {
                throw new InvalidArgumentException("unsupported scope: {$scope}");
            }
        }

        if ($resourceTypes === []) {
            throw new InvalidArgumentException('no clinical scopes requested');
        }

        return [
            'scope' => in_array('*', $resourceTypes, true) ? ['*'] : array_values(array_unique($resourceTypes)),
            'offline' => $offline,
            'openid' => $openid,
        ];
    }

    /**
     * Consent approval: mint the grant and a single-use auth code bound to the
     * client, redirect URI, and PKCE challenge.
     *
     * @param array<string> $resourceScopes
     * @param array<string> $sensitiveCategories
     * @return array{code: string, grant: AccessGrant}
     */
    public function approve(
        Vault $vault,
        User $actor,
        object $client,
        array $resourceScopes,
        array $sensitiveCategories,
        string $redirectUri,
        string $codeChallenge,
        bool $offline,
    ): array {
        $grant = AccessGrant::query()->create([
            'vault_id' => $vault->id,
            'pseudo_id' => bin2hex(random_bytes(16)),
            'otp_hash' => Hash::make(bin2hex(random_bytes(32))), // never OTP-redeemable
            'purpose' => 'personal-share',
            'scope' => $resourceScopes,
            'permissions' => ['read'],
            'sensitive_categories' => $sensitiveCategories,
            'expires_at' => $offline ? now()->addDays(self::REFRESH_TTL_DAYS) : now()->addHours(1),
            // Effectively unlimited: an app re-reads under one authorization. The
            // real lifetime bound is expires_at + revocation, not a use counter.
            'max_uses' => 2_000_000_000, // < PG integer max; not PHP_INT_MAX (overflows int4)
        ]);

        $code = bin2hex(random_bytes(32));
        DB::table('oauth_auth_codes')->insert([
            'id' => (string) Str::uuid7(),
            'client_id' => $client->id,
            'grant_id' => $grant->id,
            'code_hash' => hash('sha256', $code),
            'code_challenge' => $codeChallenge,
            'redirect_uri' => $redirectUri,
            'expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->audit->record($vault, 'grant.minted', actor: $actor, grant: $grant, context: [
            'surface' => 'smart',
            'client_id' => $client->id,
            'client_name' => $client->name,
            'scope' => $resourceScopes,
        ]);

        return ['code' => $code, 'grant' => $grant];
    }

    /**
     * Exchange authorization code + PKCE verifier for tokens. Null on ANY failure
     * (fail-closed; controller maps to one generic error).
     *
     * @return array<string, mixed>|null
     */
    public function exchangeCode(object $client, string $code, string $verifier, string $redirectUri, bool $wantsIdToken): ?array
    {
        return DB::transaction(function () use ($client, $code, $verifier, $redirectUri, $wantsIdToken): ?array {
            $row = DB::table('oauth_auth_codes')
                ->where('client_id', $client->id)
                ->where('code_hash', hash('sha256', $code))
                ->lockForUpdate()
                ->first();

            $challengeOk = $row !== null && hash_equals(
                $row->code_challenge,
                RsaKeyStore::b64url(hash('sha256', $verifier, true)),
            );

            if ($row === null || $row->used_at !== null || $row->expires_at <= now()->toDateTimeString()
                || ! $challengeOk || ! hash_equals($row->redirect_uri, $redirectUri)) {
                return null;
            }

            DB::table('oauth_auth_codes')->where('id', $row->id)->update(['used_at' => now(), 'updated_at' => now()]);

            /** @var AccessGrant|null $grant */
            $grant = AccessGrant::query()->find($row->grant_id);
            if ($grant === null || $grant->revoked_at !== null || ! $grant->expires_at->isFuture()) {
                return null;
            }

            return $this->issueTokens($grant, $client, $wantsIdToken);
        });
    }

    /** Rotate a refresh token; null on any failure. @return array<string, mixed>|null */
    public function refresh(object $client, string $refreshToken): ?array
    {
        return DB::transaction(function () use ($client, $refreshToken): ?array {
            $row = DB::table('oauth_refresh_tokens')
                ->where('client_id', $client->id)
                ->where('token_hash', hash('sha256', $refreshToken))
                ->lockForUpdate()
                ->first();

            if ($row === null || $row->revoked_at !== null || $row->expires_at <= now()->toDateTimeString()) {
                return null;
            }

            /** @var AccessGrant|null $grant */
            $grant = AccessGrant::query()->find($row->grant_id);
            if ($grant === null || $grant->revoked_at !== null || ! $grant->expires_at->isFuture()) {
                return null; // the patient revoked the app: refresh dies with the grant
            }

            DB::table('oauth_refresh_tokens')->where('id', $row->id)->update(['revoked_at' => now(), 'updated_at' => now()]);

            return $this->issueTokens($grant, $client, wantsIdToken: false);
        });
    }

    /** @return array<string, mixed> */
    private function issueTokens(AccessGrant $grant, object $client, bool $wantsIdToken): array
    {
        $vault = $grant->vault;
        $subject = $vault->subject;

        $expiresAt = now()->addMinutes(self::ACCESS_TTL_MINUTES);
        // Named grant:{id} — the existing revocation path (GrantService::revoke)
        // deletes these tokens too. One revocation model.
        $access = $subject->createToken(
            "grant:{$grant->id}",
            array_merge(["grant:{$grant->id}", "vault:{$grant->vault_id}", "purpose:{$grant->purpose}"], $grant->permissions),
            $expiresAt,
        );

        $refresh = bin2hex(random_bytes(32));
        DB::table('oauth_refresh_tokens')->insert([
            'id' => (string) Str::uuid7(),
            'client_id' => $client->id,
            'grant_id' => $grant->id,
            'token_hash' => hash('sha256', $refresh),
            'expires_at' => min(now()->addDays(self::REFRESH_TTL_DAYS), $grant->expires_at),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = [
            'access_token' => $access->plainTextToken,
            'token_type' => 'Bearer',
            'expires_in' => self::ACCESS_TTL_MINUTES * 60,
            'refresh_token' => $refresh,
            'scope' => implode(' ', array_map(
                fn (string $t) => "patient/{$t}.read",
                $grant->scope,
            )),
            'patient' => $vault->id,
        ];

        if ($wantsIdToken) {
            $now = now()->getTimestamp();
            $response['id_token'] = RsaKeyStore::signJwt([
                'iss' => url('/'),
                'sub' => $subject->id,
                'aud' => $client->id,
                'iat' => $now,
                'exp' => $now + self::ACCESS_TTL_MINUTES * 60,
                'fhirUser' => url("/api/fhir/{$vault->id}/Patient/{$vault->id}"),
            ]);
        }

        return $response;
    }
}
