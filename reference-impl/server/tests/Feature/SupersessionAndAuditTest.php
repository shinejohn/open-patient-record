<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsVaults;
use Tests\TestCase;

/** Spec §2.4 (supersession, never mutation) and §6 (complete, subject-visible audit). */
final class SupersessionAndAuditTest extends TestCase
{
    use BuildsVaults;
    use RefreshDatabase;

    public function test_corrections_supersede_and_both_versions_remain(): void
    {
        $s = $this->subjectWithVault();

        $original = $this->commitEntry($s['token'], $s['vault_id'], [
            'payload' => ['resourceType' => 'Condition', 'code' => ['text' => 'Hypertension (typo: Hypertensoin)']],
        ])->assertCreated();

        $correction = $this->commitEntry($s['token'], $s['vault_id'], [
            'payload' => ['resourceType' => 'Condition', 'code' => ['text' => 'Hypertension']],
            'replaces_entry_id' => $original->json('id'),
        ])->assertCreated();

        $entries = $this->withToken($s['token'])
            ->getJson("/api/vaults/{$s['vault_id']}/entries")
            ->assertOk()
            ->json('entries');

        $this->assertCount(2, $entries); // history is never erased
        $this->assertSame($original->json('id'), $entries[1]['replaces_entry_id']);
        $this->assertSame($correction->json('id'), $entries[1]['id']);
    }

    public function test_supersession_cannot_cross_vaults(): void
    {
        $a = $this->subjectWithVault('alice@example.test');
        $b = $this->subjectWithVault('bob@example.test');

        $aliceEntry = $this->commitEntry($a['token'], $a['vault_id'])->assertCreated();

        $this->commitEntry($b['token'], $b['vault_id'], [
            'replaces_entry_id' => $aliceEntry->json('id'),
        ])->assertStatus(422);
    }

    public function test_audit_trail_records_the_full_grant_lifecycle_for_the_subject(): void
    {
        $s = $this->subjectWithVault();
        $this->commitEntry($s['token'], $s['vault_id'])->assertCreated();

        $mint = $this->mintGrant($s['token'], $s['vault_id'])->assertCreated();
        $redeem = $this->postJson('/api/grants/redeem', [
            'pseudo_id' => $mint->json('pseudo_id'),
            'otp' => $mint->json('otp'),
        ])->assertOk();
        $this->withToken($redeem->json('token'))
            ->getJson("/api/vaults/{$s['vault_id']}/entries")
            ->assertOk();
        $this->withToken($s['token'])
            ->postJson("/api/vaults/{$s['vault_id']}/grants/{$mint->json('grant_id')}/revoke")
            ->assertOk();

        $actions = collect($this->withToken($s['token'])
            ->getJson("/api/vaults/{$s['vault_id']}/audit")
            ->assertOk()
            ->json('events'))->pluck('action');

        foreach (['entry.committed', 'grant.minted', 'grant.redeemed', 'entries.read', 'grant.revoked'] as $expected) {
            $this->assertContains($expected, $actions, "Missing audit action: {$expected}");
        }
    }

    public function test_denied_redemptions_are_audited_with_the_true_reason(): void
    {
        $s = $this->subjectWithVault();
        $mint = $this->mintGrant($s['token'], $s['vault_id'])->assertCreated();

        $this->postJson('/api/grants/redeem', [
            'pseudo_id' => $mint->json('pseudo_id'),
            'otp' => '00000000',
        ])->assertStatus(403);

        $events = collect($this->withToken($s['token'])
            ->getJson("/api/vaults/{$s['vault_id']}/audit")
            ->assertOk()
            ->json('events'));

        $denied = $events->firstWhere('action', 'grant.denied');
        $this->assertNotNull($denied);
        $this->assertSame('bad_otp', $denied['reason']); // true reason lives ONLY in the audit log
    }
}
