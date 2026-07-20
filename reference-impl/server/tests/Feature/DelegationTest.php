<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsVaults;
use Tests\TestCase;

/**
 * Delegation: guardians and proxies act for the subject — with full attribution —
 * but authority over WHO acts for the patient never delegates itself.
 */
final class DelegationTest extends TestCase
{
    use BuildsVaults;
    use RefreshDatabase;

    /** @return array{s: array, g: array, delegationId: string} */
    private function vaultWithGuardian(): array
    {
        $s = $this->subjectWithVault('parentless-teen@example.test');
        $g = $this->registerUser('guardian@example.test');

        $added = $this->withToken($s['token'])
            ->postJson("/api/vaults/{$s['vault_id']}/delegates", [
                'email' => 'guardian@example.test',
                'role' => 'guardian',
            ])->assertCreated();

        return ['s' => $s, 'g' => $g, 'delegationId' => $added->json('id')];
    }

    public function test_a_guardian_acts_for_the_subject(): void
    {
        ['s' => $s, 'g' => $g] = $this->vaultWithGuardian();
        $this->commitEntry($s['token'], $s['vault_id'])->assertCreated();

        // Read, mint a grant, export — subject-equivalent authority.
        $this->withToken($g['token'])
            ->getJson("/api/vaults/{$s['vault_id']}/entries")
            ->assertOk();
        $this->mintGrant($g['token'], $s['vault_id'])->assertCreated();
        $this->withToken($g['token'])
            ->getJson("/api/vaults/{$s['vault_id']}/export")
            ->assertOk();
    }

    public function test_delegate_management_never_delegates(): void
    {
        ['s' => $s, 'g' => $g] = $this->vaultWithGuardian();
        $this->registerUser('accomplice@example.test');

        // The guardian cannot appoint further delegates...
        $this->withToken($g['token'])
            ->postJson("/api/vaults/{$s['vault_id']}/delegates", [
                'email' => 'accomplice@example.test',
                'role' => 'proxy',
            ])->assertForbidden();

        // ...nor revoke themselves or others.
        $delegation = $this->withToken($s['token'])
            ->getJson("/api/vaults/{$s['vault_id']}/delegates")
            ->assertOk()
            ->json('delegates.0.id');
        $this->withToken($g['token'])
            ->postJson("/api/vaults/{$s['vault_id']}/delegates/{$delegation}/revoke")
            ->assertForbidden();
    }

    public function test_revocation_ends_delegate_access_and_regrant_works(): void
    {
        ['s' => $s, 'g' => $g, 'delegationId' => $id] = $this->vaultWithGuardian();
        $this->commitEntry($s['token'], $s['vault_id'])->assertCreated();

        $this->withToken($s['token'])
            ->postJson("/api/vaults/{$s['vault_id']}/delegates/{$id}/revoke")
            ->assertOk();

        $this->withToken($g['token'])
            ->getJson("/api/vaults/{$s['vault_id']}/entries")
            ->assertForbidden();

        // Re-delegation after revocation is allowed (partial unique index).
        $this->withToken($s['token'])
            ->postJson("/api/vaults/{$s['vault_id']}/delegates", [
                'email' => 'guardian@example.test',
                'role' => 'proxy',
            ])->assertCreated();
    }

    public function test_delegation_guardrails(): void
    {
        $s = $this->subjectWithVault();

        // Unknown user, self-delegation, duplicates — all rejected.
        $this->withToken($s['token'])
            ->postJson("/api/vaults/{$s['vault_id']}/delegates", [
                'email' => 'nobody@example.test', 'role' => 'proxy',
            ])->assertStatus(422);
        $this->withToken($s['token'])
            ->postJson("/api/vaults/{$s['vault_id']}/delegates", [
                'email' => 'subject@example.test', 'role' => 'proxy',
            ])->assertStatus(422);

        $this->registerUser('helper@example.test');
        $this->withToken($s['token'])
            ->postJson("/api/vaults/{$s['vault_id']}/delegates", [
                'email' => 'helper@example.test', 'role' => 'proxy',
            ])->assertCreated();
        $this->withToken($s['token'])
            ->postJson("/api/vaults/{$s['vault_id']}/delegates", [
                'email' => 'helper@example.test', 'role' => 'proxy',
            ])->assertStatus(409);
    }

    public function test_delegate_actions_are_audited_under_the_delegates_own_identity(): void
    {
        ['s' => $s, 'g' => $g] = $this->vaultWithGuardian();
        $this->commitEntry($s['token'], $s['vault_id'])->assertCreated();

        $this->withToken($g['token'])
            ->getJson("/api/vaults/{$s['vault_id']}/entries")
            ->assertOk();

        $events = collect($this->withToken($s['token'])
            ->getJson("/api/vaults/{$s['vault_id']}/audit")
            ->assertOk()
            ->json('events'));

        $this->assertNotNull($events->firstWhere('action', 'delegate.added'));
        // Attribution: the read shows the GUARDIAN as actor context, not the subject.
        $added = $events->firstWhere('action', 'delegate.added');
        $this->assertSame($g['user_id'], $added['context']['delegate_user_id']);
    }
}
