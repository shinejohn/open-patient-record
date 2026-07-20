<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Vault;
use App\Services\SmartService;
use App\Support\SensitiveCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * SMART standalone launch: /oauth/authorize (session web flow).
 * GET validates the request and shows login+consent; POST /login authenticates
 * the session; POST /decision mints the AccessGrant and redirects with the code.
 * Every validation failure BEFORE a trusted redirect_uri is known renders an
 * error page — never an open redirect.
 */
final class SmartAuthorizeController
{
    public function __construct(private readonly SmartService $smart)
    {
    }

    public function show(Request $request)
    {
        $params = $request->validate([
            'response_type' => ['required', 'in:code'],
            'client_id' => ['required', 'uuid'],
            'redirect_uri' => ['required', 'url'],
            'scope' => ['required', 'string'],
            'state' => ['required', 'string', 'max:512'],
            'aud' => ['required', 'string'],
            'code_challenge' => ['required', 'string', 'size:43'],
            'code_challenge_method' => ['required', 'in:S256'],
        ]);

        $client = DB::table('oauth_clients')->where('id', $params['client_id'])->first();
        abort_if($client === null, 400, 'unknown client');
        // Exact-match redirect URI — never partial, never open.
        abort_unless(hash_equals($client->redirect_uri, $params['redirect_uri']), 400, 'redirect_uri mismatch');

        // aud must be one of OUR per-vault FHIR bases; the vault comes from it.
        $vaultId = null;
        if (preg_match('#/api/fhir/([0-9a-f-]{36})/?\z#', $params['aud'], $m) === 1) {
            $vaultId = $m[1];
        }
        abort_if($vaultId === null || Vault::query()->whereKey($vaultId)->doesntExist(), 400, 'invalid aud');

        try {
            $scopes = $this->smart->parseScopes($params['scope']);
        } catch (\InvalidArgumentException $e) {
            abort(400, $e->getMessage());
        }

        $request->session()->put('smart.pending', $params + ['vault_id' => $vaultId]);

        return view('oauth.authorize', [
            'client' => $client,
            'scopes' => $scopes,
            'categories' => SensitiveCategory::KNOWN,
            'authenticated' => Auth::guard('web')->check(),
        ]);
    }

    public function login(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        abort_unless($request->session()->has('smart.pending'), 400, 'no pending authorization');

        if (! Auth::guard('web')->attempt($request->only('email', 'password'))) {
            return back()->withErrors(['email' => 'Invalid credentials.']);
        }
        $request->session()->regenerate();

        $pending = $request->session()->get('smart.pending');
        $client = DB::table('oauth_clients')->where('id', $pending['client_id'])->first();

        return view('oauth.authorize', [
            'client' => $client,
            'scopes' => $this->smart->parseScopes($pending['scope']),
            'categories' => SensitiveCategory::KNOWN,
            'authenticated' => true,
        ]);
    }

    public function decision(Request $request): RedirectResponse
    {
        $pending = $request->session()->pull('smart.pending');
        abort_if($pending === null, 400, 'no pending authorization');

        /** @var User|null $user */
        $user = Auth::guard('web')->user();
        abort_if($user === null, 403);

        $redirect = $pending['redirect_uri'];
        $state = $pending['state'];

        if (! $request->boolean('approve')) {
            return redirect()->away($redirect.'?'.http_build_query(['error' => 'access_denied', 'state' => $state]));
        }

        $vault = Vault::query()->findOrFail($pending['vault_id']);
        // The signed-in patient (or their delegate) must control this vault.
        abort_unless($vault->actsFor($user), 403);

        $scopes = $this->smart->parseScopes($pending['scope']);
        $client = DB::table('oauth_clients')->where('id', $pending['client_id'])->first();

        // Sensitive categories: ONLY those explicitly ticked on the consent form —
        // scopes cannot request them (spec §3.2 default-off, per grant).
        $sensitive = array_values(array_intersect(
            (array) $request->input('sensitive_categories', []),
            SensitiveCategory::KNOWN,
        ));

        $result = $this->smart->approve(
            $vault,
            $user,
            $client,
            $scopes['scope'],
            $sensitive,
            $redirect,
            $pending['code_challenge'],
            $scopes['offline'],
        );

        return redirect()->away($redirect.'?'.http_build_query(['code' => $result['code'], 'state' => $state]));
    }
}
