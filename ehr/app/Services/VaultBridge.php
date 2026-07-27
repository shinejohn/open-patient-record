<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * The EHR's ONLY road to a patient's record: the vault server's public HTTP
 * API. No shared database, no private surface — the same-rails commitment as
 * code. Every method either returns the vault's answer or throws
 * VaultUnreachable; the bridge never fabricates a response.
 *
 * Custody in provision(): the subject is registered with a random password
 * that is thrown away in this method's stack frame. The practice walks away
 * holding a treatment GRANT token — scoped, revocable, patient-visible —
 * never the subject credential. The patient claims the vault later through
 * account recovery on the vault server.
 */
final class VaultBridge
{
    /**
     * @param array{name: string, email?: string|null} $demographics
     * @return array{vault_user_id: string, vault_id: string, grant_pseudo_id: string, grant_token: string}
     */
    public function provision(array $demographics, string $organization): array
    {
        // 1. Register the subject. The password is random and never persisted.
        $password = Str::random(40);
        $email = $demographics['email'] ?? null;
        $registered = $this->post('/api/users', [
            'name' => $demographics['name'],
            'email' => $email !== null && $email !== '' ? $email : Str::uuid().'@unclaimed.opr.invalid',
            'password' => $password,
        ]);
        $subjectToken = (string) $registered->json('token');
        $vaultUserId = (string) $registered->json('user.id');

        // 2. Create the vault as the subject.
        $vaultId = (string) $this->post('/api/vaults', [], $subjectToken)->json('id');

        // 3. Mint a LONG-LIVED treatment grant (subject-only operation), then
        // redeem it. Derived tokens expire in ~30 minutes on the vault, so the
        // grant itself must outlive them by a clinical margin — one year — and
        // the redemption secret is returned to the caller so expiry means
        // re-redemption, not a bricked chart. The patient can revoke the grant
        // at any time; that is the kill switch, not token death.
        $mint = $this->post("/api/vaults/{$vaultId}/grants", [
            'purpose' => 'treatment',
            'scope' => ['*'],
            'permissions' => ['read', 'write'],
            'max_uses' => 100000,
            'expires_in_minutes' => 525600,
        ], $subjectToken);

        $pseudoId = (string) $mint->json('pseudo_id');
        $otp = (string) $mint->json('otp');
        $grantToken = $this->redeemGrant($pseudoId, $otp);

        // The subject token and password go out of scope here — deliberately.
        return [
            'vault_user_id' => $vaultUserId,
            'vault_id' => $vaultId,
            'grant_pseudo_id' => $pseudoId,
            'grant_otp' => $otp,
            'grant_token' => $grantToken,
        ];
    }

    /** Redeem (or re-redeem) a grant's secret for a fresh derived token. */
    public function redeemGrant(string $pseudoId, string $otp): string
    {
        return (string) $this->post('/api/grants/redeem', [
            'pseudo_id' => $pseudoId,
            'otp' => $otp,
        ])->json('token');
    }

    /** @param array<string, mixed> $resource */
    public function commitFhir(string $grantToken, string $vaultId, string $type, array $resource, string $organization): void
    {
        $this->send('post', "/api/fhir/{$vaultId}/{$type}", $resource, $grantToken, [
            'X-OPR-Organization' => $organization,
        ]);
    }

    /** @return array<string, mixed> */
    public function everything(string $grantToken, string $vaultId): array
    {
        return (array) $this->send('get', "/api/fhir/{$vaultId}/Patient/\$everything", [], $grantToken)->json();
    }

    /**
     * Commit several resources as ONE transaction Bundle — all-or-nothing on
     * the vault chain. Used by encounter signing (Encounter + note) so a
     * half-signed visit cannot exist.
     *
     * @param list<array<string, mixed>> $resources
     * @return array<string, mixed> the transaction-response Bundle
     */
    public function commitBundle(string $grantToken, string $vaultId, array $resources, string $organization): array
    {
        $bundle = [
            'resourceType' => 'Bundle',
            'type' => 'transaction',
            'entry' => array_map(static fn (array $r): array => [
                'resource' => $r,
                'request' => ['method' => 'POST', 'url' => $r['resourceType']],
            ], $resources),
        ];

        return (array) $this->send('post', "/api/fhir/{$vaultId}", $bundle, $grantToken, [
            'X-OPR-Organization' => $organization,
        ])->json();
    }

    // ---------------------------------------------------------------

    /** @param array<string, mixed> $body */
    private function post(string $path, array $body, ?string $token = null): Response
    {
        return $this->send('post', $path, $body, $token);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    private function send(string $method, string $path, array $body, ?string $token = null, array $headers = []): Response
    {
        $request = Http::baseUrl((string) config('opr.vault_base_url'))
            ->timeout((int) config('opr.http_timeout_seconds'))
            ->acceptJson()
            ->withHeaders($headers);

        if ($token !== null) {
            $request = $request->withToken($token);
        }

        try {
            $response = $method === 'get' ? $request->get($path, $body) : $request->post($path, $body);
        } catch (\Illuminate\Http\Client\HttpClientException $e) {
            // Covers connection failures AND read timeouts across client versions.
            throw new VaultUnreachable("Vault connection failed: {$e->getMessage()}", previous: $e);
        }

        if ($response->clientError()) {
            throw new VaultRejected(
                $response->status(),
                $path,
                "Vault refused {$method} {$path} with HTTP {$response->status()}.",
            );
        }

        if ($response->failed()) {
            throw new VaultUnreachable(
                "Vault call {$method} {$path} failed with HTTP {$response->status()}.",
            );
        }

        return $response;
    }
}
