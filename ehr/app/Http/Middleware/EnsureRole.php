<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * E2 role policy, minimal and deliberate: `role:owner` or `role:owner,clinician`
 * on a route. Staff (the remaining role) get scheduling and the roster list —
 * clinical surfaces (chart, patient creation, encounters) require clinician or
 * owner. Fail-closed: no user or unknown role denies.
 */
final class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $role = $request->user()?->role;
        if ($role === null || ! in_array($role, $roles, true)) {
            abort(403, 'forbidden');
        }

        return $next($request);
    }
}
