<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsVaults;
use Tests\TestCase;

/** Spec §3.6 (ShareSession / QR flow) and §3.7 (break-glass). */
final class ShareSessionAndBreakGlassTest extends TestCase
{
    use BuildsVaults;
    use RefreshDatabase;

    // ------------------------------ ShareSession ------------------------------

    public function test_share_code_redeems_once_for_read_only_access(): void
    {
        $s = $this->subjectWithVault();
        $this->commitEntry($s['token'], $s['vault_id'])->assertCreated();

        $session = $this->withToken($s['token'])
            ->postJson("/api/vaults/{$s['vault_id']}/share-sessions")
            ->assertCreated();

        // Parse the QR payload: opr-share:v1:{pseudo_id}:{otp}
        [$scheme, $version, $pseudoId, $otp] = explode(':', $session->json('share_code'));
        $this->assertSame(['opr-share', 'v1'], [$scheme, $version]);

        $redeem = $this->postJson('/api/grants/redeem', ['pseudo_id' => $pseudoId, 'otp' => $otp])
            ->assertOk();

        // Read works; write is structurally impossible; second redemption fails.
        $this->withToken($redeem->json('token'))
            ->getJson("/api/vaults/{$s['vault_id']}/entries")
            ->assertOk();
        $this->commitEntry($redeem->json('token'), $s['vault_id'])->assertForbidden();
        $this->postJson('/api/grants/redeem', ['pseudo_id' => $pseudoId, 'otp' => $otp])
            ->assertStatus(403);
    }

    public function test_share_session_lifetime_is_capped_at_sixty_minutes(): void
    {
        $s = $this->subjectWithVault();

        $session = $this->withToken($s['token'])
            ->postJson("/api/vaults/{$s['vault_id']}/share-sessions", ['expires_in_minutes' => 100000])
            ->assertCreated();

        $this->assertLessThanOrEqual(
            now()->addMinutes(61)->getTimestamp(),
            \Carbon\Carbon::parse($session->json('expires_at'))->getTimestamp(),
        );
    }

    public function test_only_the_subject_can_open_a_share_session(): void
    {
        $s = $this->subjectWithVault();
        $other = $this->registerUser('other@example.test');

        $this->withToken($other['token'])
            ->postJson("/api/vaults/{$s['vault_id']}/share-sessions")
            ->assertForbidden();
    }

    // ------------------------------ Break-glass -------------------------------

    public function test_break_glass_grants_immediate_read_excluding_sensitive(): void
    {
        $s = $this->subjectWithVault();
        $this->commitEntry($s['token'], $s['vault_id'])->assertCreated();
        $this->commitEntry($s['token'], $s['vault_id'], [
            'payload' => ['resourceType' => 'Condition', 'code' => ['text' => 'Opioid use disorder']],
            'sensitive_category' => '42_cfr_part_2',
        ])->assertCreated();

        $er = $this->registerUser('er-doc@example.test');
        $access = $this->withToken($er['token'])
            ->postJson("/api/vaults/{$s['vault_id']}/break-glass", [
                'reason' => 'Patient unconscious in ED, need medication and allergy history',
            ])
            ->assertCreated();

        $entries = $this->withToken($access->json('token'))
            ->getJson("/api/vaults/{$s['vault_id']}/entries")
            ->assertOk()
            ->json('entries');

        // Emergency access sees the record — but sensitive categories stay out (§3.7).
        $this->assertCount(1, $entries);
        $this->assertNull($entries[0]['sensitive_category']);
    }

    public function test_break_glass_requires_a_substantive_reason(): void
    {
        $s = $this->subjectWithVault();
        $er = $this->registerUser('er2@example.test');

        $this->withToken($er['token'])
            ->postJson("/api/vaults/{$s['vault_id']}/break-glass", ['reason' => 'x'])
            ->assertStatus(422);
        $this->withToken($er['token'])
            ->postJson("/api/vaults/{$s['vault_id']}/break-glass", [])
            ->assertStatus(422);
    }

    public function test_break_glass_is_flagged_in_the_audit_trail_the_subject_reads(): void
    {
        $s = $this->subjectWithVault();
        $this->commitEntry($s['token'], $s['vault_id'])->assertCreated();

        $er = $this->registerUser('er3@example.test');
        $access = $this->withToken($er['token'])
            ->postJson("/api/vaults/{$s['vault_id']}/break-glass", [
                'reason' => 'Unresponsive patient, checking current medications',
            ])->assertCreated();
        $this->withToken($access->json('token'))
            ->getJson("/api/vaults/{$s['vault_id']}/entries")
            ->assertOk();

        $events = collect($this->withToken($s['token'])
            ->getJson("/api/vaults/{$s['vault_id']}/audit")
            ->assertOk()
            ->json('events'));

        $emergency = $events->firstWhere('action', 'grant.emergency_access');
        $this->assertNotNull($emergency);
        $this->assertTrue($emergency['is_emergency']);
        $this->assertStringContainsString('Unresponsive patient', $emergency['reason']);
        $this->assertSame($er['user_id'], $emergency['context']['accessor_user_id']); // identity recorded

        // The subsequent read under the emergency grant is ALSO flagged.
        $read = $events->firstWhere(fn ($e) => $e['action'] === 'entries.read' && $e['is_emergency']);
        $this->assertNotNull($read);
    }
}
