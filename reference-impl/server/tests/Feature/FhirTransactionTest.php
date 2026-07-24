<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsVaults;
use Tests\TestCase;

/**
 * F1: transaction and batch Bundles — POST /api/fhir/{vault}.
 *
 * transaction = all-or-nothing: every entry validates and commits, or the vault
 * is untouched (the chain must never carry half a transaction). batch =
 * independent: each entry succeeds or fails on its own, statuses reported
 * per entry. Only POST entries are meaningful here — committed content is
 * append-only, so PUT/DELETE entries are refused outright (spec §4.1).
 */
final class FhirTransactionTest extends TestCase
{
    use BuildsVaults;
    use RefreshDatabase;

    /** @param list<array{method: string, url: string, resource: array<string, mixed>}> $entries */
    private function bundle(string $type, array $entries): array
    {
        return [
            'resourceType' => 'Bundle',
            'type' => $type,
            'entry' => array_map(static fn (array $e): array => [
                'resource' => $e['resource'],
                'request' => ['method' => $e['method'], 'url' => $e['url']],
            ], $entries),
        ];
    }

    public function test_transaction_commits_all_entries_in_order(): void
    {
        $s = $this->subjectWithVault();

        $response = $this->withToken($s['token'])
            ->postJson("/api/fhir/{$s['vault_id']}", $this->bundle('transaction', [
                ['method' => 'POST', 'url' => 'Condition', 'resource' => [
                    'resourceType' => 'Condition',
                    'subject' => ['reference' => "Patient/{$s['vault_id']}"],
                    'code' => ['text' => 'Hypertension'],
                ]],
                ['method' => 'POST', 'url' => 'Observation', 'resource' => [
                    'resourceType' => 'Observation',
                    'status' => 'final',
                    'code' => ['text' => 'BP'],
                ]],
            ]))
            ->assertOk()
            ->assertJsonPath('resourceType', 'Bundle')
            ->assertJsonPath('type', 'transaction-response');

        $this->assertSame('201 Created', $response->json('entry.0.response.status'));
        $this->assertSame('201 Created', $response->json('entry.1.response.status'));
        $this->assertSame('Condition', $response->json('entry.0.resource.resourceType'));
        $this->assertSame('Observation', $response->json('entry.1.resource.resourceType'));

        // Both on the chain, in bundle order, chain valid.
        $this->withToken($s['token'])
            ->getJson("/api/vaults/{$s['vault_id']}/verify")
            ->assertOk()
            ->assertJsonPath('valid', true)
            ->assertJsonPath('entries', 2);
        $this->assertSame('1', $response->json('entry.0.resource.meta.versionId'));
        $this->assertSame('2', $response->json('entry.1.resource.meta.versionId'));
    }

    public function test_transaction_is_all_or_nothing(): void
    {
        $s = $this->subjectWithVault();

        $this->withToken($s['token'])
            ->postJson("/api/fhir/{$s['vault_id']}", $this->bundle('transaction', [
                ['method' => 'POST', 'url' => 'Condition', 'resource' => [
                    'resourceType' => 'Condition',
                    'subject' => ['reference' => "Patient/{$s['vault_id']}"],
                ]],
                ['method' => 'POST', 'url' => 'Observation', 'resource' => [
                    'resourceType' => 'Observation', // missing status + code
                ]],
            ]))
            ->assertStatus(422)
            ->assertJsonPath('resourceType', 'OperationOutcome');

        // NOTHING landed — the first (valid) entry must not survive.
        $this->withToken($s['token'])
            ->getJson("/api/vaults/{$s['vault_id']}/verify")
            ->assertOk()
            ->assertJsonPath('entries', 0);
    }

    public function test_batch_processes_entries_independently(): void
    {
        $s = $this->subjectWithVault();

        $response = $this->withToken($s['token'])
            ->postJson("/api/fhir/{$s['vault_id']}", $this->bundle('batch', [
                ['method' => 'POST', 'url' => 'Condition', 'resource' => [
                    'resourceType' => 'Condition',
                    'subject' => ['reference' => "Patient/{$s['vault_id']}"],
                ]],
                ['method' => 'POST', 'url' => 'Observation', 'resource' => [
                    'resourceType' => 'Observation', // invalid
                ]],
            ]))
            ->assertOk()
            ->assertJsonPath('type', 'batch-response');

        $this->assertSame('201 Created', $response->json('entry.0.response.status'));
        $this->assertStringStartsWith('422', $response->json('entry.1.response.status'));
        $this->assertSame('OperationOutcome', $response->json('entry.1.resource.resourceType'));

        // Exactly the valid entry landed.
        $this->withToken($s['token'])
            ->getJson("/api/vaults/{$s['vault_id']}/verify")
            ->assertOk()
            ->assertJsonPath('entries', 1);
    }

    public function test_put_entries_are_refused_and_a_transaction_containing_one_commits_nothing(): void
    {
        $s = $this->subjectWithVault();

        $this->withToken($s['token'])
            ->postJson("/api/fhir/{$s['vault_id']}", $this->bundle('transaction', [
                ['method' => 'POST', 'url' => 'Condition', 'resource' => [
                    'resourceType' => 'Condition',
                    'subject' => ['reference' => "Patient/{$s['vault_id']}"],
                ]],
                ['method' => 'PUT', 'url' => 'Condition/some-id', 'resource' => [
                    'resourceType' => 'Condition',
                    'subject' => ['reference' => "Patient/{$s['vault_id']}"],
                ]],
            ]))
            ->assertStatus(405)
            ->assertJsonPath('resourceType', 'OperationOutcome');

        $this->withToken($s['token'])
            ->getJson("/api/vaults/{$s['vault_id']}/verify")
            ->assertOk()
            ->assertJsonPath('entries', 0);
    }

    public function test_non_bundle_body_is_rejected(): void
    {
        $s = $this->subjectWithVault();

        $this->withToken($s['token'])
            ->postJson("/api/fhir/{$s['vault_id']}", ['resourceType' => 'Condition'])
            ->assertStatus(400)
            ->assertJsonPath('resourceType', 'OperationOutcome');
    }

    public function test_grant_scope_is_enforced_across_the_whole_transaction(): void
    {
        $s = $this->subjectWithVault();
        $mint = $this->mintGrant($s['token'], $s['vault_id'], [
            'scope' => ['MedicationStatement'],
            'permissions' => ['read', 'write'],
        ])->assertCreated();
        $redeem = $this->postJson('/api/grants/redeem', [
            'pseudo_id' => $mint->json('pseudo_id'),
            'otp' => $mint->json('otp'),
        ])->assertOk();

        $this->withToken($redeem->json('token'))
            ->postJson("/api/fhir/{$s['vault_id']}", $this->bundle('transaction', [
                ['method' => 'POST', 'url' => 'MedicationStatement', 'resource' => [
                    'resourceType' => 'MedicationStatement',
                    'status' => 'active',
                    'subject' => ['reference' => "Patient/{$s['vault_id']}"],
                    'medicationCodeableConcept' => ['text' => 'Metformin'],
                ]],
                ['method' => 'POST', 'url' => 'Condition', 'resource' => [ // outside scope
                    'resourceType' => 'Condition',
                    'subject' => ['reference' => "Patient/{$s['vault_id']}"],
                ]],
            ]))
            ->assertStatus(403);

        $this->withToken($s['token'])
            ->getJson("/api/vaults/{$s['vault_id']}/verify")
            ->assertOk()
            ->assertJsonPath('entries', 0);
    }
}
