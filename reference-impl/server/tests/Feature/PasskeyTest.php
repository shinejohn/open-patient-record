<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Feature\Concerns\Authenticator;
use Tests\Feature\Concerns\BuildsVaults;
use Tests\TestCase;

/** Passkeys (WebAuthn) + no-oracle account recovery — driven by a real software authenticator. */
final class PasskeyTest extends TestCase
{
    use BuildsVaults;
    use RefreshDatabase;

    /** @return array{s: array, auth: Authenticator} registered passkey for a subject */
    private function subjectWithPasskey(): array
    {
        $s = $this->subjectWithVault();
        $auth = new Authenticator();

        $options = $this->withToken($s['token'])
            ->postJson('/api/webauthn/register/options')
            ->assertOk();

        $this->withToken($s['token'])
            ->postJson('/api/webauthn/register', [
                'credential' => $auth->createAttestation($options->json('options.challenge')),
                'nickname' => 'test-key',
            ])->assertCreated();

        return ['s' => $s, 'auth' => $auth];
    }

    private function passkeyLogin(array $s, Authenticator $auth, int $counter): \Illuminate\Testing\TestResponse
    {
        $options = $this->postJson('/api/webauthn/login/options', ['email' => 'subject@example.test'])
            ->assertOk();

        return $this->postJson('/api/webauthn/login', [
            'email' => 'subject@example.test',
            'credential' => $auth->createAssertion(
                $options->json('options.challenge'),
                $counter,
                $s['user_id'],
            ),
        ]);
    }

    public function test_register_and_login_round_trip_yields_a_working_token(): void
    {
        ['s' => $s, 'auth' => $auth] = $this->subjectWithPasskey();

        $login = $this->passkeyLogin($s, $auth, counter: 1)->assertOk();

        $this->withToken($login->json('token'))
            ->getJson("/api/vaults/{$s['vault_id']}")
            ->assertOk();

        // Registration was audited on the subject's vault.
        $events = collect($this->withToken($s['token'])
            ->getJson("/api/vaults/{$s['vault_id']}/audit")->json('events'))->pluck('action');
        $this->assertContains('credential.added', $events);
    }

    public function test_sign_count_regression_is_denied(): void
    {
        ['s' => $s, 'auth' => $auth] = $this->subjectWithPasskey();

        $this->passkeyLogin($s, $auth, counter: 5)->assertOk();
        // A cloned authenticator replays an older counter → generic denial.
        $this->passkeyLogin($s, $auth, counter: 2)->assertStatus(403);
    }

    public function test_challenges_are_single_use(): void
    {
        ['s' => $s, 'auth' => $auth] = $this->subjectWithPasskey();

        $options = $this->postJson('/api/webauthn/login/options', ['email' => 'subject@example.test'])->assertOk();
        $assertion = $auth->createAssertion($options->json('options.challenge'), 1, $s['user_id']);

        $this->postJson('/api/webauthn/login', ['email' => 'subject@example.test', 'credential' => $assertion])
            ->assertOk();
        // Same assertion again: the challenge was consumed.
        $this->postJson('/api/webauthn/login', ['email' => 'subject@example.test', 'credential' => $assertion])
            ->assertStatus(403);
    }

    public function test_revoked_credentials_cannot_authenticate(): void
    {
        ['s' => $s, 'auth' => $auth] = $this->subjectWithPasskey();

        $credentialRowId = DB::table('user_credentials')->value('id');
        $this->withToken($s['token'])
            ->postJson("/api/webauthn/credentials/{$credentialRowId}/revoke")
            ->assertOk();

        $this->passkeyLogin($s, $auth, counter: 1)->assertStatus(403);
    }

    public function test_login_options_reveal_nothing_about_account_existence(): void
    {
        $this->subjectWithPasskey();

        $known = $this->postJson('/api/webauthn/login/options', ['email' => 'subject@example.test'])->assertOk();
        $unknown = $this->postJson('/api/webauthn/login/options', ['email' => 'ghost@example.test'])->assertOk();

        // Same shape, same credential count — a phantom descriptor stands in.
        $this->assertSame(
            count($known->json('options.allowCredentials')),
            count($unknown->json('options.allowCredentials')),
        );
        $this->assertNotNull($unknown->json('options.challenge'));
    }

    public function test_account_recovery_is_no_oracle_single_use_and_audited(): void
    {
        ['s' => $s] = $this->subjectWithPasskey();

        $captured = null;
        Log::listen(function ($event) use (&$captured): void {
            if ($event->message === 'account recovery link issued') {
                $captured = $event->context['token'];
            }
        });

        $known = $this->postJson('/api/account/recover', ['email' => 'subject@example.test'])->assertOk();
        $unknown = $this->postJson('/api/account/recover', ['email' => 'ghost@example.test'])->assertOk();
        $this->assertSame($known->json(), $unknown->json()); // identical, no oracle
        $this->assertNotNull($captured);

        $this->postJson('/api/account/recover/complete', [
            'email' => 'subject@example.test',
            'token' => $captured,
            'password' => 'brand-new-password-123',
        ])->assertOk();

        // New password works; the token is spent.
        $this->postJson('/api/token', ['email' => 'subject@example.test', 'password' => 'brand-new-password-123'])
            ->assertOk();
        $this->postJson('/api/account/recover/complete', [
            'email' => 'subject@example.test',
            'token' => $captured,
            'password' => 'yet-another-password-99',
        ])->assertStatus(403);

        // Audited on the vault, and a 24h cooldown blocks immediate re-issuance.
        $events = collect($this->withToken($s['token'])
            ->getJson("/api/vaults/{$s['vault_id']}/audit")->json('events'))->pluck('action');
        $this->assertContains('account.recovered', $events);

        $this->postJson('/api/account/recover', ['email' => 'subject@example.test'])->assertOk();
        $this->assertSame(0, DB::table('recovery_tokens')->whereNull('used_at')->count());
    }
}
