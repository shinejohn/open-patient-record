<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsVaults;
use Tests\TestCase;

/**
 * SMART Backend Services (system-to-system, unattended). A registered backend
 * client authenticates with a private-key JWT (client_assertion) instead of a
 * shared secret, and receives a token scoped to the ONE vault it is registered
 * against — same custody model as every other credential here, an access token
 * derives from a real AccessGrant.
 */
final class SmartBackendServicesTest extends TestCase
{
    use BuildsVaults;
    use RefreshDatabase;

    private const TOKEN_ENDPOINT_PATH = '/oauth/token';

    /** @return array{private: \OpenSSLAsymmetricKey, jwk: array<string, string>} */
    private function generateKeyPair(): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $details = openssl_pkey_get_details($key);
        $b64url = fn (string $d) => rtrim(strtr(base64_encode($d), '+/', '-_'), '=');

        return [
            'private' => $key,
            'jwk' => [
                'kty' => 'RSA',
                'n' => $b64url($details['rsa']['n']),
                'e' => $b64url($details['rsa']['e']),
            ],
        ];
    }

    private function signAssertion(\OpenSSLAsymmetricKey $key, string $clientId, array $overrides = []): string
    {
        $b64url = fn (string $d) => rtrim(strtr(base64_encode($d), '+/', '-_'), '=');
        $now = time();
        $claims = array_merge([
            'iss' => $clientId,
            'sub' => $clientId,
            'aud' => url(self::TOKEN_ENDPOINT_PATH),
            'exp' => $now + 120,
            'iat' => $now,
            'jti' => bin2hex(random_bytes(16)),
        ], $overrides);

        $header = $b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $payload = $b64url(json_encode($claims));
        $signingInput = "{$header}.{$payload}";
        openssl_sign($signingInput, $signature, $key, OPENSSL_ALGO_SHA256);

        return "{$signingInput}.".$b64url($signature);
    }

    /** @return array{client_id: string, private: \OpenSSLAsymmetricKey} */
    private function registerBackendClient(string $token, string $vaultId, array $scope = ['*']): array
    {
        $pair = $this->generateKeyPair();
        $created = $this->withToken($token)
            ->postJson("/api/vaults/{$vaultId}/backend-clients", [
                'name' => 'Payer Data Exchange Bot',
                'jwk' => ['kty' => 'RSA'] + $pair['jwk'],
                'scope' => $scope,
            ])
            ->assertCreated();

        return ['client_id' => $created->json('id'), 'private' => $pair['private']];
    }

    public function test_full_client_credentials_flow_yields_a_scoped_system_token(): void
    {
        $s = $this->subjectWithVault();
        $this->commitEntry($s['token'], $s['vault_id'])->assertCreated(); // Condition
        $this->commitEntry($s['token'], $s['vault_id'], [
            'resource_type' => 'Observation',
            'payload' => ['resourceType' => 'Observation', 'code' => ['text' => 'BP']],
        ])->assertCreated();

        $client = $this->registerBackendClient($s['token'], $s['vault_id'], ['Condition']);
        $assertion = $this->signAssertion($client['private'], $client['client_id']);

        $token = $this->postJson('/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
            'client_assertion' => $assertion,
            'scope' => 'system/*.read',
        ])->assertOk();

        $this->assertSame('system/Condition.read', $token->json('scope'));
        $this->assertNull($token->json('refresh_token'));
        $this->assertNull($token->json('id_token'));

        $bundle = $this->withToken($token->json('access_token'))
            ->getJson("/api/fhir/{$s['vault_id']}/Patient/\$everything")
            ->assertOk();
        $types = collect($bundle->json('entry'))->pluck('resource.resourceType');
        $this->assertContains('Condition', $types);
        $this->assertNotContains('Observation', $types); // out of registered scope
    }

    public function test_assertion_signed_by_the_wrong_key_is_rejected(): void
    {
        $s = $this->subjectWithVault();
        $client = $this->registerBackendClient($s['token'], $s['vault_id']);
        $wrongPair = $this->generateKeyPair();
        $assertion = $this->signAssertion($wrongPair['private'], $client['client_id']);

        $this->postJson('/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
            'client_assertion' => $assertion,
            'scope' => 'system/*.read',
        ])->assertStatus(400)->assertJsonPath('error', 'invalid_client');
    }

    public function test_assertion_is_single_use(): void
    {
        $s = $this->subjectWithVault();
        $client = $this->registerBackendClient($s['token'], $s['vault_id']);
        $assertion = $this->signAssertion($client['private'], $client['client_id']);

        $body = [
            'grant_type' => 'client_credentials',
            'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
            'client_assertion' => $assertion,
            'scope' => 'system/*.read',
        ];
        $this->postJson('/oauth/token', $body)->assertOk();
        $this->postJson('/oauth/token', $body)->assertStatus(400); // jti replay rejected
    }

    public function test_expired_assertion_is_rejected(): void
    {
        $s = $this->subjectWithVault();
        $client = $this->registerBackendClient($s['token'], $s['vault_id']);
        $assertion = $this->signAssertion($client['private'], $client['client_id'], [
            'exp' => time() - 10,
        ]);

        $this->postJson('/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
            'client_assertion' => $assertion,
            'scope' => 'system/*.read',
        ])->assertStatus(400);
    }

    public function test_assertion_with_wrong_audience_is_rejected(): void
    {
        $s = $this->subjectWithVault();
        $client = $this->registerBackendClient($s['token'], $s['vault_id']);
        $assertion = $this->signAssertion($client['private'], $client['client_id'], [
            'aud' => 'https://not-this-server.example.test/oauth/token',
        ]);

        $this->postJson('/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
            'client_assertion' => $assertion,
            'scope' => 'system/*.read',
        ])->assertStatus(400);
    }

    public function test_revoked_backend_client_is_rejected(): void
    {
        $s = $this->subjectWithVault();
        $client = $this->registerBackendClient($s['token'], $s['vault_id']);

        $this->withToken($s['token'])
            ->postJson("/api/vaults/{$s['vault_id']}/backend-clients/{$client['client_id']}/revoke")
            ->assertOk();

        $assertion = $this->signAssertion($client['private'], $client['client_id']);
        $this->postJson('/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
            'client_assertion' => $assertion,
            'scope' => 'system/*.read',
        ])->assertStatus(400);
    }

    public function test_scope_beyond_registration_is_narrowed_not_rejected(): void
    {
        $s = $this->subjectWithVault();
        $this->commitEntry($s['token'], $s['vault_id'])->assertCreated(); // Condition
        $client = $this->registerBackendClient($s['token'], $s['vault_id'], ['Condition']);
        $assertion = $this->signAssertion($client['private'], $client['client_id']);

        $token = $this->postJson('/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
            'client_assertion' => $assertion,
            'scope' => 'system/Condition.read system/Observation.read',
        ])->assertOk();

        $this->assertSame('system/Condition.read', $token->json('scope'));
    }

    public function test_write_scope_is_rejected(): void
    {
        $s = $this->subjectWithVault();
        $client = $this->registerBackendClient($s['token'], $s['vault_id']);
        $assertion = $this->signAssertion($client['private'], $client['client_id']);

        $this->postJson('/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
            'client_assertion' => $assertion,
            'scope' => 'system/Condition.write',
        ])->assertStatus(400);
    }

    public function test_only_the_vault_owner_can_register_a_backend_client(): void
    {
        $s = $this->subjectWithVault();
        $stranger = $this->registerUser('stranger@example.test');
        $pair = $this->generateKeyPair();

        $this->withToken($stranger['token'])
            ->postJson("/api/vaults/{$s['vault_id']}/backend-clients", [
                'name' => 'Attacker Bot',
                'jwk' => ['kty' => 'RSA'] + $pair['jwk'],
                'scope' => ['*'],
            ])
            ->assertStatus(403);
    }

    public function test_discovery_advertises_backend_services(): void
    {
        $s = $this->subjectWithVault();

        $this->getJson("/api/fhir/{$s['vault_id']}/.well-known/smart-configuration")
            ->assertOk()
            ->assertJsonFragment(['permission-backend-services'])
            ->assertJsonPath('grant_types_supported', ['authorization_code', 'client_credentials']);
    }
}
