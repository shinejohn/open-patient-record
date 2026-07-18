<?php

declare(strict_types=1);

namespace Tests\Feature\Concerns;

/**
 * Drives the real HTTP API (no model shortcuts) so these tests double as the seed of
 * the black-box conformance suite.
 */
trait BuildsVaults
{
    /** @return array{token: string, user_id: string} */
    protected function registerUser(string $email): array
    {
        $response = $this->postJson('/api/users', [
            'name' => 'Test '.$email,
            'email' => $email,
            'password' => 'correct-horse-battery',
        ])->assertCreated();

        return ['token' => $response->json('token'), 'user_id' => $response->json('user.id')];
    }

    /** @return array{token: string, user_id: string, vault_id: string} */
    protected function subjectWithVault(string $email = 'subject@example.test'): array
    {
        $subject = $this->registerUser($email);

        $vault = $this->withToken($subject['token'])
            ->postJson('/api/vaults')
            ->assertCreated();

        return $subject + ['vault_id' => $vault->json('id')];
    }

    /** @param array<string, mixed> $overrides */
    protected function commitEntry(string $token, string $vaultId, array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->withToken($token)->postJson("/api/vaults/{$vaultId}/entries", array_replace([
            'resource_type' => 'Condition',
            'payload' => ['resourceType' => 'Condition', 'code' => ['text' => 'Hypertension']],
            'verification_tier' => 'verified-source',
            'provenance' => ['organization' => 'Test Clinic', 'source_system' => 'conformance-suite'],
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    protected function mintGrant(string $token, string $vaultId, array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->withToken($token)->postJson("/api/vaults/{$vaultId}/grants", array_replace([
            'purpose' => 'treatment',
            'scope' => ['*'],
            'permissions' => ['read'],
            'max_uses' => 5,
        ], $overrides));
    }
}
