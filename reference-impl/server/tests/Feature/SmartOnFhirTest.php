<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\RsaKeyStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\BuildsVaults;
use Tests\TestCase;

/**
 * SMART on FHIR standalone launch. The load-bearing property under test: a SMART
 * authorization IS an AccessGrant — so scope, sensitive-default-off, revocation,
 * and audit all reuse the existing, already-tested grant machinery.
 */
final class SmartOnFhirTest extends TestCase
{
    use BuildsVaults;
    use RefreshDatabase;

    private function registerClient(bool $confidential = false): array
    {
        $id = (string) Str::uuid7();
        $secret = $confidential ? 'test-secret-'.bin2hex(random_bytes(8)) : null;
        DB::table('oauth_clients')->insert([
            'id' => $id,
            'name' => 'Health App',
            'redirect_uri' => 'https://app.example.test/callback',
            'confidential' => $confidential,
            'secret_hash' => $secret === null ? null : bcrypt($secret),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['id' => $id, 'secret' => $secret, 'redirect' => 'https://app.example.test/callback'];
    }

    /** @return array{verifier: string, challenge: string} */
    private function pkce(): array
    {
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');

        return ['verifier' => $verifier, 'challenge' => RsaKeyStore::b64url(hash('sha256', $verifier, true))];
    }

    /**
     * Drives the full browser flow: authorize → login → consent decision →
     * returns the authorization code from the redirect.
     */
    private function authorize(array $s, array $client, array $pkce, string $scope, array $sensitive = []): string
    {
        $params = [
            'response_type' => 'code',
            'client_id' => $client['id'],
            'redirect_uri' => $client['redirect'],
            'scope' => $scope,
            'state' => 'xyz-state',
            'aud' => url("/api/fhir/{$s['vault_id']}"),
            'code_challenge' => $pkce['challenge'],
            'code_challenge_method' => 'S256',
        ];

        $this->get('/oauth/authorize?'.http_build_query($params))->assertOk();
        $this->post('/oauth/authorize/login', [
            'email' => 'subject@example.test', 'password' => 'correct-horse-battery',
        ])->assertOk();

        $decision = $this->post('/oauth/authorize/decision', [
            'approve' => '1',
            'sensitive_categories' => $sensitive,
        ])->assertRedirect();

        parse_str(parse_url($decision->headers->get('Location'), PHP_URL_QUERY), $q);
        $this->assertSame('xyz-state', $q['state']);

        // A real SMART client is a separate HTTP agent with no browser session.
        // Sanctum checks the web guard before the bearer token, so we must drop the
        // consent-flow session or it would authenticate later bearer calls as the
        // full subject and mask grant-scope filtering.
        $this->app['auth']->guard('web')->logout();
        $this->flushSession();

        return $q['code'];
    }

    public function test_full_authorization_code_pkce_flow_yields_scoped_fhir_access(): void
    {
        $s = $this->subjectWithVault();
        $this->commitEntry($s['token'], $s['vault_id'])->assertCreated(); // Condition
        $this->commitEntry($s['token'], $s['vault_id'], [
            'resource_type' => 'Observation',
            'payload' => ['resourceType' => 'Observation', 'code' => ['text' => 'BP']],
        ])->assertCreated();

        $client = $this->registerClient();
        $pkce = $this->pkce();
        $code = $this->authorize($s, $client, $pkce, 'openid fhirUser offline_access patient/Condition.read');

        $token = $this->postJson('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $client['id'],
            'code' => $code,
            'code_verifier' => $pkce['verifier'],
            'redirect_uri' => $client['redirect'],
        ])->assertOk();

        $this->assertSame($s['vault_id'], $token->json('patient'));
        $this->assertNotEmpty($token->json('id_token'));
        $this->assertNotEmpty($token->json('refresh_token'));

        // The access token reads FHIR — filtered to the granted scope.
        $bundle = $this->withToken($token->json('access_token'))
            ->getJson("/api/fhir/{$s['vault_id']}/Patient/\$everything")
            ->assertOk();
        $types = collect($bundle->json('entry'))->pluck('resource.resourceType');
        $this->assertContains('Condition', $types);
        $this->assertNotContains('Observation', $types); // out of granted scope
    }

    public function test_pkce_verifier_is_required_and_checked(): void
    {
        $s = $this->subjectWithVault();
        $client = $this->registerClient();
        $pkce = $this->pkce();
        $code = $this->authorize($s, $client, $pkce, 'patient/*.read');

        // Wrong verifier → invalid_grant (code interception without the verifier fails).
        $this->postJson('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $client['id'],
            'code' => $code,
            'code_verifier' => 'wrong-verifier-'.str_repeat('a', 30),
            'redirect_uri' => $client['redirect'],
        ])->assertStatus(400);
    }

    public function test_authorization_code_is_single_use(): void
    {
        $s = $this->subjectWithVault();
        $client = $this->registerClient();
        $pkce = $this->pkce();
        $code = $this->authorize($s, $client, $pkce, 'patient/*.read');

        $body = [
            'grant_type' => 'authorization_code', 'client_id' => $client['id'],
            'code' => $code, 'code_verifier' => $pkce['verifier'], 'redirect_uri' => $client['redirect'],
        ];
        $this->postJson('/oauth/token', $body)->assertOk();
        $this->postJson('/oauth/token', $body)->assertStatus(400); // replay rejected
    }

    public function test_sensitive_categories_are_off_unless_ticked_on_consent(): void
    {
        $s = $this->subjectWithVault();
        $this->commitEntry($s['token'], $s['vault_id'])->assertCreated();
        $this->commitEntry($s['token'], $s['vault_id'], [
            'resource_type' => 'Condition',
            'payload' => ['resourceType' => 'Condition', 'code' => ['text' => 'SUD']],
            'sensitive_category' => '42_cfr_part_2',
        ])->assertCreated();

        $client = $this->registerClient();

        // Not ticked → sensitive entry excluded even under patient/*.read.
        $pkce = $this->pkce();
        $code = $this->authorize($s, $client, $pkce, 'patient/*.read');
        $t = $this->postJson('/oauth/token', [
            'grant_type' => 'authorization_code', 'client_id' => $client['id'],
            'code' => $code, 'code_verifier' => $pkce['verifier'], 'redirect_uri' => $client['redirect'],
        ])->json('access_token');
        $cats = collect($this->withToken($t)->getJson("/api/fhir/{$s['vault_id']}/Patient/\$everything")->json('entry'))
            ->pluck('resource.meta.tag')->flatten(1)->pluck('code');
        $this->assertNotContains('42_cfr_part_2', $cats);
    }

    public function test_revoking_the_grant_kills_the_app_immediately(): void
    {
        $s = $this->subjectWithVault();
        $this->commitEntry($s['token'], $s['vault_id'])->assertCreated();
        $client = $this->registerClient();
        $pkce = $this->pkce();
        $code = $this->authorize($s, $client, $pkce, 'offline_access patient/*.read');
        $token = $this->postJson('/oauth/token', [
            'grant_type' => 'authorization_code', 'client_id' => $client['id'],
            'code' => $code, 'code_verifier' => $pkce['verifier'], 'redirect_uri' => $client['redirect'],
        ])->assertOk();

        $this->withToken($token->json('access_token'))
            ->getJson("/api/fhir/{$s['vault_id']}/Patient/\$everything")->assertOk();

        // Patient revokes via the ONE existing grant-revocation surface.
        $grantId = DB::table('access_grants')->where('purpose', 'personal-share')->value('id');
        $this->withToken($s['token'])
            ->postJson("/api/vaults/{$s['vault_id']}/grants/{$grantId}/revoke")->assertOk();

        // Access token dead now; refresh dies with the grant too.
        $this->withToken($token->json('access_token'))
            ->getJson("/api/fhir/{$s['vault_id']}/Patient/\$everything")->assertUnauthorized();
        $this->postJson('/oauth/token', [
            'grant_type' => 'refresh_token', 'client_id' => $client['id'],
            'refresh_token' => $token->json('refresh_token'),
        ])->assertStatus(400);
    }

    public function test_refresh_rotates_and_old_refresh_is_dead(): void
    {
        $s = $this->subjectWithVault();
        $this->commitEntry($s['token'], $s['vault_id'])->assertCreated();
        $client = $this->registerClient();
        $pkce = $this->pkce();
        $code = $this->authorize($s, $client, $pkce, 'offline_access patient/*.read');
        $first = $this->postJson('/oauth/token', [
            'grant_type' => 'authorization_code', 'client_id' => $client['id'],
            'code' => $code, 'code_verifier' => $pkce['verifier'], 'redirect_uri' => $client['redirect'],
        ])->json();

        $second = $this->postJson('/oauth/token', [
            'grant_type' => 'refresh_token', 'client_id' => $client['id'], 'refresh_token' => $first['refresh_token'],
        ])->assertOk();
        $this->assertNotSame($first['refresh_token'], $second->json('refresh_token'));

        // Old refresh token is now revoked (rotation).
        $this->postJson('/oauth/token', [
            'grant_type' => 'refresh_token', 'client_id' => $client['id'], 'refresh_token' => $first['refresh_token'],
        ])->assertStatus(400);
    }

    public function test_confidential_client_must_present_its_secret(): void
    {
        $s = $this->subjectWithVault();
        $client = $this->registerClient(confidential: true);
        $pkce = $this->pkce();
        $code = $this->authorize($s, $client, $pkce, 'patient/*.read');

        $base = [
            'grant_type' => 'authorization_code', 'client_id' => $client['id'],
            'code' => $code, 'code_verifier' => $pkce['verifier'], 'redirect_uri' => $client['redirect'],
        ];
        $this->postJson('/oauth/token', $base)->assertStatus(400);                       // no secret
        $this->postJson('/oauth/token', $base + ['client_secret' => $client['secret']])->assertOk();
    }

    public function test_redirect_uri_must_match_exactly(): void
    {
        $s = $this->subjectWithVault();
        $client = $this->registerClient();
        $pkce = $this->pkce();

        $this->get('/oauth/authorize?'.http_build_query([
            'response_type' => 'code', 'client_id' => $client['id'],
            'redirect_uri' => 'https://evil.example.test/callback', // not the registered URI
            'scope' => 'patient/*.read', 'state' => 'x', 'aud' => url("/api/fhir/{$s['vault_id']}"),
            'code_challenge' => $pkce['challenge'], 'code_challenge_method' => 'S256',
        ]))->assertStatus(400);
    }

    public function test_discovery_and_jwks_are_public(): void
    {
        $s = $this->subjectWithVault();

        $this->getJson("/api/fhir/{$s['vault_id']}/.well-known/smart-configuration")
            ->assertOk()
            ->assertJsonPath('code_challenge_methods_supported', ['S256'])
            ->assertJsonFragment(['launch-standalone']);

        $this->getJson('/api/oauth/jwks')
            ->assertOk()
            ->assertJsonPath('keys.0.kty', 'RSA')
            ->assertJsonPath('keys.0.alg', 'RS256');
    }

    public function test_write_scopes_are_rejected(): void
    {
        $s = $this->subjectWithVault();
        $client = $this->registerClient();
        $pkce = $this->pkce();

        $this->get('/oauth/authorize?'.http_build_query([
            'response_type' => 'code', 'client_id' => $client['id'], 'redirect_uri' => $client['redirect'],
            'scope' => 'patient/Condition.write', 'state' => 'x', 'aud' => url("/api/fhir/{$s['vault_id']}"),
            'code_challenge' => $pkce['challenge'], 'code_challenge_method' => 'S256',
        ]))->assertStatus(400);
    }
}
