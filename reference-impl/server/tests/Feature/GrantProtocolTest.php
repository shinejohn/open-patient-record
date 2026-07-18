<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsVaults;
use Tests\TestCase;

/** Spec §3: minting, redemption, scoping, purpose, revocation — all fail-closed. */
final class GrantProtocolTest extends TestCase
{
    use BuildsVaults;
    use RefreshDatabase;

    public function test_subject_mints_and_holder_redeems_for_scoped_read(): void
    {
        $s = $this->subjectWithVault();
        $this->commitEntry($s['token'], $s['vault_id'])->assertCreated(); // Condition
        $this->commitEntry($s['token'], $s['vault_id'], [
            'resource_type' => 'MedicationStatement',
            'payload' => ['resourceType' => 'MedicationStatement', 'medication' => ['text' => 'Metformin']],
        ])->assertCreated();

        $mint = $this->mintGrant($s['token'], $s['vault_id'], ['scope' => ['Condition']])->assertCreated();
        $this->assertNotNull($mint->json('otp')); // plaintext shown exactly once

        // Redemption is unauthenticated by design.
        $redeem = $this->postJson('/api/grants/redeem', [
            'pseudo_id' => $mint->json('pseudo_id'),
            'otp' => $mint->json('otp'),
        ])->assertOk();

        $entries = $this->withToken($redeem->json('token'))
            ->getJson("/api/vaults/{$s['vault_id']}/entries")
            ->assertOk();

        // Scope filtering: only Condition is visible.
        $this->assertCount(1, $entries->json('entries'));
        $this->assertSame('Condition', $entries->json('entries.0.resource_type'));
        // Sync responses carry the chain head (spec §4.5).
        $this->assertNotNull($entries->json('chain_head_hash'));
    }

    public function test_only_the_subject_can_mint(): void
    {
        $s = $this->subjectWithVault();
        $other = $this->registerUser('other@example.test');

        $this->mintGrant($other['token'], $s['vault_id'])->assertForbidden();
    }

    public function test_all_redemption_failures_return_the_identical_payload(): void
    {
        $s = $this->subjectWithVault();
        $mint = $this->mintGrant($s['token'], $s['vault_id'])->assertCreated();

        $wrongOtp = $this->postJson('/api/grants/redeem', [
            'pseudo_id' => $mint->json('pseudo_id'),
            'otp' => '00000000',
        ])->assertStatus(403);

        $unknownHandle = $this->postJson('/api/grants/redeem', [
            'pseudo_id' => str_repeat('ab', 16),
            'otp' => '00000000',
        ])->assertStatus(403);

        // No oracle: unknown handle and wrong secret are indistinguishable.
        $this->assertSame($wrongOtp->json(), $unknownHandle->json());
    }

    public function test_max_uses_is_enforced(): void
    {
        $s = $this->subjectWithVault();
        $mint = $this->mintGrant($s['token'], $s['vault_id'], ['max_uses' => 1])->assertCreated();
        $credentials = ['pseudo_id' => $mint->json('pseudo_id'), 'otp' => $mint->json('otp')];

        $this->postJson('/api/grants/redeem', $credentials)->assertOk();
        $this->postJson('/api/grants/redeem', $credentials)->assertStatus(403);
    }

    public function test_expired_grants_do_not_redeem(): void
    {
        $s = $this->subjectWithVault();
        $mint = $this->mintGrant($s['token'], $s['vault_id'], ['expires_in_minutes' => 1])->assertCreated();

        $this->travel(2)->minutes();

        $this->postJson('/api/grants/redeem', [
            'pseudo_id' => $mint->json('pseudo_id'),
            'otp' => $mint->json('otp'),
        ])->assertStatus(403);
    }

    public function test_revocation_kills_outstanding_tokens_immediately(): void
    {
        $s = $this->subjectWithVault();
        $this->commitEntry($s['token'], $s['vault_id'])->assertCreated();

        $mint = $this->mintGrant($s['token'], $s['vault_id'])->assertCreated();
        $redeem = $this->postJson('/api/grants/redeem', [
            'pseudo_id' => $mint->json('pseudo_id'),
            'otp' => $mint->json('otp'),
        ])->assertOk();

        $grantToken = $redeem->json('token');
        $this->withToken($grantToken)->getJson("/api/vaults/{$s['vault_id']}/entries")->assertOk();

        $this->withToken($s['token'])
            ->postJson("/api/vaults/{$s['vault_id']}/grants/{$mint->json('grant_id')}/revoke")
            ->assertOk();

        // Derived token is dead NOW — not within some grace window.
        $this->withToken($grantToken)
            ->getJson("/api/vaults/{$s['vault_id']}/entries")
            ->assertUnauthorized();
    }

    public function test_read_only_grant_cannot_write(): void
    {
        $s = $this->subjectWithVault();
        $mint = $this->mintGrant($s['token'], $s['vault_id'], ['permissions' => ['read']])->assertCreated();
        $redeem = $this->postJson('/api/grants/redeem', [
            'pseudo_id' => $mint->json('pseudo_id'),
            'otp' => $mint->json('otp'),
        ])->assertOk();

        $this->commitEntry($redeem->json('token'), $s['vault_id'])->assertForbidden();
    }

    public function test_write_grant_commits_within_scope_only(): void
    {
        $s = $this->subjectWithVault();
        $mint = $this->mintGrant($s['token'], $s['vault_id'], [
            'permissions' => ['read', 'write'],
            'scope' => ['MedicationStatement'],
        ])->assertCreated();
        $redeem = $this->postJson('/api/grants/redeem', [
            'pseudo_id' => $mint->json('pseudo_id'),
            'otp' => $mint->json('otp'),
        ])->assertOk();

        $this->commitEntry($redeem->json('token'), $s['vault_id'], [
            'resource_type' => 'MedicationStatement',
            'payload' => ['resourceType' => 'MedicationStatement', 'medication' => ['text' => 'Atorvastatin']],
        ])->assertCreated();

        $this->commitEntry($redeem->json('token'), $s['vault_id'])->assertForbidden(); // Condition: out of scope
    }

    public function test_grant_tokens_cannot_mint_grants_or_export(): void
    {
        $s = $this->subjectWithVault();
        $mint = $this->mintGrant($s['token'], $s['vault_id'])->assertCreated();
        $redeem = $this->postJson('/api/grants/redeem', [
            'pseudo_id' => $mint->json('pseudo_id'),
            'otp' => $mint->json('otp'),
        ])->assertOk();

        $grantToken = $redeem->json('token');
        // Privilege containment: a redeemed token must never escalate to subject powers.
        $this->mintGrant($grantToken, $s['vault_id'])->assertForbidden();
        $this->withToken($grantToken)->getJson("/api/vaults/{$s['vault_id']}/export")->assertForbidden();
        $this->withToken($grantToken)->getJson("/api/vaults/{$s['vault_id']}/audit")->assertForbidden();
    }

    public function test_invalid_purpose_is_rejected(): void
    {
        $s = $this->subjectWithVault();
        $this->mintGrant($s['token'], $s['vault_id'], ['purpose' => 'marketing'])->assertStatus(422);
    }
}
