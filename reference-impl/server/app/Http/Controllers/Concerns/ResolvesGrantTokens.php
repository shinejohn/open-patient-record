<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\AccessGrant;
use App\Models\Vault;
use Illuminate\Http\Request;

/**
 * Distinguishes full subject tokens from grant-derived tokens, fail-closed.
 * Grant-derived Sanctum tokens carry abilities: grant:{id}, vault:{id},
 * purpose:{p}, read[, write]. A grant token is NEVER a subject token, even though
 * (in this bearer-model M1) it is technically issued against the subject's user.
 */
trait ResolvesGrantTokens
{
    /** Active grant for this request's token, or null if this is not a grant token. */
    protected function currentGrant(Request $request): ?AccessGrant
    {
        $token = $request->user()?->currentAccessToken();
        if ($token === null) {
            return null;
        }

        foreach ($token->abilities ?? [] as $ability) {
            if (str_starts_with((string) $ability, 'grant:')) {
                $grant = AccessGrant::query()->find(substr((string) $ability, 6));

                // Fail-closed: a grant token whose grant is missing/revoked/expired
                // authorizes nothing (spec §3.4, §3.5).
                if ($grant === null || $grant->revoked_at !== null || ! $grant->expires_at->isFuture()) {
                    abort(403, 'invalid_grant');
                }

                return $grant;
            }
        }

        return null;
    }

    protected function isGrantToken(Request $request): bool
    {
        $token = $request->user()?->currentAccessToken();
        if ($token === null) {
            return false;
        }
        foreach ($token->abilities ?? [] as $ability) {
            if (str_starts_with((string) $ability, 'grant:')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Subject-equivalent access: the subject or an active delegate (guardian/
     * proxy), on a full (non-grant) token. Audit rows still record the ACTUAL
     * actor, so a delegate's actions are always attributable.
     */
    protected function assertSubject(Request $request, Vault $vault): void
    {
        if ($this->isGrantToken($request) || ! $vault->actsFor($request->user())) {
            abort(403, 'forbidden');
        }
    }

    /**
     * Strictly the subject — NOT delegates. Reserved for appointing/removing
     * delegates: authority over who acts for the patient never delegates itself.
     */
    protected function assertOwner(Request $request, Vault $vault): void
    {
        if ($this->isGrantToken($request) || ! $vault->isSubject($request->user())) {
            abort(403, 'forbidden');
        }
    }

    /** Grant access to a specific vault, with a required permission. */
    protected function assertGrantCovers(Request $request, AccessGrant $grant, Vault $vault, string $permission): void
    {
        if ($grant->vault_id !== $vault->id
            || ! $request->user()->tokenCan("vault:{$vault->id}")
            || ! $request->user()->tokenCan($permission)
            || ! in_array($permission, $grant->permissions, true)) {
            abort(403, 'forbidden');
        }
    }
}
