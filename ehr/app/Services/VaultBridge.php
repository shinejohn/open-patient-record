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

        // 3. Mint a treatment grant (subject-only operation), then redeem it.
        $mint = $this->post("/api/vaults/{$vaultId}/grants", [
            'purpose' => 'treatment',
            'scope' => ['*'],
            'permissions' => ['read', 'write'],
            'max_uses' => 100000,
        ], $subjectToken);

        $grantToken = (string) $this->post('/api/grants/redeem', [
            'pseudo_id' => $mint->json('pseudo_id'),
            'otp' => $mint->json('otp'),
        ])->json('token');

        // The subject token and password go out of scope here — deliberately.
        return [
            'vault_user_id' => $vaultUserId,
            'vault_id' => $vaultId,
            'grant_pseudo_id' => (string) $mint->json('pseudo_id'),
            'grant_token' => $grantToken,
        ];
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
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            throw new VaultUnreachable("Vault connection failed: {$e->getMessage()}", previous: $e);
        }

        if ($response->failed()) {
            throw new VaultUnreachable(
                "Vault call {$method} {$path} failed with HTTP {$response->status()}.",
            );
        }

        return $response;
    }
}
