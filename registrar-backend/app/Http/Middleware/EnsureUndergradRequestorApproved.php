<?php

namespace App\Http\Middleware;

use App\Models\SystemUser;
use Closure;
use Illuminate\Http\Request;

/**
 * Undergrad Requestor Registration — Phase 5.
 *
 * The request-flow gate: blocks document-request-filing endpoints unless
 * the authenticated Undergrad Requestor's verification is Approved
 * (§3.9 of the original policy).
 *
 * ── A no-op for every other role ───────────────────────────────────────
 * This middleware only ever inspects Undergrad Requestor accounts. Every
 * other authenticated role — Student, Alumni, Admin, Super Admin —
 * passes straight through with no query, no check, nothing. It exists
 * to be stacked onto the exact same routes RoleMiddleware already widens
 * to include role 5 (see routes/api.php), never as a general-purpose
 * gate.
 *
 * ── Why this is defense-in-depth, not the primary control ─────────────
 * Walk the account lifecycle and this condition should already be
 * unreachable in the happy path:
 *
 *   1. An Undergrad Requestor cannot hold a live session at all unless
 *      status = 'Activated' — EnsureAccountActive re-checks that on
 *      EVERY request, ahead of this middleware in the shared
 *      ['auth:sanctum', 'active', ...] group (see routes/api.php).
 *   2. status only ever becomes 'Activated' for this role through
 *      UserProvisioningService::provision()'s auto-activation branch,
 *      which is itself gated on undergradRequestorVerification->isApproved()
 *      (see that method's Phase 3/D4 comments).
 *
 * So an authenticated, Activated Undergrad Requestor should, by
 * construction, always be Approved. This middleware exists anyway,
 * exactly like EnsureAccountActive exists anyway on top of
 * AdminUserService's immediate token revocation: a single point of
 * enforcement is one refactor away from a gap (a future admin tool that
 * flips `status` directly, a data-repair script, a later change to the
 * activation branch above) and re-deriving the "may this account
 * actually file a request" answer here, on the live DB row, on every
 * write, is cheap insurance against all of them at once.
 *
 * ── Why middleware, not only the policy/service layer ──────────────────
 * DocumentRequestPolicy::create() and RequestDocumentController::store()
 * answer "is this a kind of account that may ever create a request" —
 * structural, role-shaped, rarely-changing, exactly like every other
 * 'role:...' gate in this codebase. "Has THIS account's application
 * actually been approved" is a business-state check that can flip
 * without the account's role ever changing, which is precisely the
 * distinction EnsureModuleAccess's own docblock draws between itself and
 * RoleMiddleware. This middleware is that same layer, scoped to one
 * role. DocumentRequestService::buildRequestData() ALSO re-checks
 * isApproved() immediately before writing (Phase 5) — belt-and-suspenders
 * with this middleware, not a substitute for it, matching how this
 * codebase treats every other status check that guards money or access
 * (see CashierOrOverrideController's docblock, EnsureAccountActive's
 * "backstop" framing).
 */
class EnsureUndergradRequestorApproved
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (!$user instanceof SystemUser || !$user->isUndergradRequestor()) {
            return $next($request);
        }

        $verification = $user->undergradRequestorVerification;

        if ($verification?->isApproved()) {
            return $next($request);
        }

        // Distinguishes "still under review" from "not approved" in the
        // response even though, per the docblock above, only the
        // 'rejected'/'unknown' cases should ever actually be reachable
        // here — a still-Pending account cannot hold a live session at
        // all. Kept explicit rather than collapsed into one generic
        // message so a future gap in the activation guard fails loudly
        // and specifically instead of behind a vague 403.
        $status = match (true) {
            $verification === null            => 'unknown',
            $verification->isRejected()       => 'rejected',
            default                            => 'pending',
        };

        return response()->json([
            'message' => match ($status) {
                'rejected' => 'Your Undergrad Requestor registration was not approved, so you cannot file '
                    . 'document requests. Please contact the registrar for more information.',
                default => 'Your Undergrad Requestor registration is still under review by the Registrar\'s '
                    . 'Office. You\'ll be able to file document requests once a decision has been made.',
            },
            'undergrad_requestor_status' => $status,
        ], 403);
    }
}
