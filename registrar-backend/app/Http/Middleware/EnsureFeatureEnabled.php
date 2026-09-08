<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Route-layer gate for a config/features.php flag.
 *
 * Usage (routes/api.php):
 *   Route::middleware(['role:3,4', 'module:free_requests,View', 'feature:free_request_page'])
 *
 * Deliberately separate from 'module' (EnsureModuleAccess):
 *   - 'feature' answers "is this feature live in this environment at
 *     all" — one flag, config-driven, the same for every account.
 *   - 'module' answers "does THIS account's policy grant it" —
 *     per-account, independent of whether the feature is live.
 * Both are applied together on a flagged route; either one failing
 * denies the request. See config/features.php's own docblock for the
 * full rationale and the safe-default (fail closed) rule every flag in
 * that file follows.
 *
 * Returns 404, not 403: a disabled feature should look like it doesn't
 * exist yet, not like an authorization failure the caller could
 * request access to. This also avoids leaking "the route exists but is
 * gated" to an unauthenticated or unauthorized caller — 'auth:sanctum'
 * and 'role'/'module' still run in the same middleware group and
 * enforce their own responses when applicable; ordering in the route
 * group determines which check a given caller actually hits first.
 */
class EnsureFeatureEnabled
{
    public function handle(Request $request, Closure $next, string $flag)
    {
        if (!config("features.{$flag}", false)) {
            return response()->json([
                'message' => 'Not found.',
            ], 404);
        }

        return $next($request);
    }
}
