<?php

namespace App\Http\Controllers;

use App\Contracts\NotificationServiceInterface;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\SetLocalPasswordRequest;
use App\Http\Resources\UserResource;
use App\Models\AuditLog;
use App\Models\SystemUser;
use App\Services\AuditLogger;
use App\Services\LocalAuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * LocalAuthController
 * ===================
 * Handles the two new local-auth endpoints:
 *
 *   POST /api/auth/local-login
 *       Always authenticates against the local bcrypt hash.
 *       Used by staff when they know the IDP is down and don't want
 *       the IDP-first round-trip delay. Since local auth is now
 *       restricted to a small set of Super Admin break-glass accounts
 *       (see LocalAuthService docblock), every successful use is rare
 *       by design — so a success here also fires an immediate
 *       admin-facing notification (see login() below), not just a log
 *       line, so the rest of the team can verify it was expected.
 *
 *   POST /api/auth/local-password      (superadmin only)
 *       Set or update the local password for any user.
 *       Body: { user_id: int, password: string, password_confirmation: string }
 *       SetLocalPasswordRequest additionally rejects any target whose
 *       role_id isn't Super Admin — see that class for details.
 *
 *   GET  /api/auth/local-auth-status   (superadmin only)
 *       Returns which users have local_auth_enabled = 1.
 *       Useful for the admin panel to show coverage at a glance.
 *
 * login()'s validation lives in App\Http\Requests\Auth\LoginRequest
 * (shared with AuthController::login()). setPassword()'s validation +
 * authorization live in App\Http\Requests\Auth\SetLocalPasswordRequest.
 */
class LocalAuthController extends Controller
{
    public function __construct(
        private LocalAuthService           $localAuth,
        private AuditLogger                $auditLogger,
        private NotificationServiceInterface $notificationService,
    ) {}

    // -----------------------------------------------------------------------
    // POST /api/auth/local-login
    // -----------------------------------------------------------------------
    public function login(LoginRequest $request)
    {
        try {
            $user = $this->localAuth->attempt(
                $request->input('email'),
                $request->input('password'),
                $request,
            );
        } catch (\RuntimeException $e) {
            $statusCode = $e->getCode() ?: 401;
            return response()->json(['message' => $e->getMessage()], $statusCode);
        }

        $user->loadIdentityRelations();
        // Stamp 'sanctum-local' so logout() knows to skip the IdP redirect.
        $token = $user->createToken('sanctum-local')->plainTextToken;

        $this->auditLogger->log($request, $user, AuditLog::ACTION_LOGIN);

        Log::info('LocalAuth: login success', [
            'user_id' => $user->user_id,
            'email'   => $user->email,
        ]);

        // Break-glass logins should be rare and always verified. This is
        // meant to reach the people who can actually act on it — the
        // Super Admins who own break-glass accounts (see
        // SetLocalPasswordRequest, which now restricts local auth to
        // that role). NotificationService::sendToAdmins() intentionally
        // targets ONLY role_id = Admin (see its docblock) and would
        // silently never reach a Super Admin, so we target Admin +
        // Super Admin explicitly here via sendToAllExcept() instead —
        // excluding every requestor role (student, alumni, undergrad
        // requestor): none of them can be break-glass accounts or act on
        // this alert, and it discloses an account email + source IP.
        // Matches the exclusion list SecurityEventLogger uses for the
        // failed-login-burst alert.
        $this->notificationService->sendToAllExcept(
            excludedRoleIds: [
                SystemUser::ROLE_STUDENT,
                SystemUser::ROLE_ALUMNI,
                SystemUser::ROLE_UNDERGRAD_REQUESTOR,
            ],
            triggerEvent:    'local_auth_login_used',
            data: [
                'user_id' => $user->user_id,
                'email'   => $user->email,
                'ip'      => $request->ip(),
            ],
        );

        // Failed-attempt alerting (Phase 3e — CLOSED, previously a
        // deferred follow-up here): every failed branch inside
        // LocalAuthService::attempt() now writes a security_events row
        // via SecurityEventLogger, and that same call checks whether N
        // failures have landed against this email within the configured
        // window (config/security_events.php), firing a
        // 'security_alert_failed_login_burst' notification to Admin +
        // Super Admin the first time the threshold is crossed. Route-level
        // throttling (throttle:10,1 on this endpoint) remains a separate,
        // complementary brute-force mitigation — it slows an attacker
        // down; SecurityEventLogger is what makes a burst visible.

        return response()
            ->json(['user' => new UserResource($user)])
            ->cookie(
                    'token',
                    $token,
                    60 * 24 * 7,
                    '/',
                    config('session.domain'),
                    config('session.secure_cookie'),
                    true,
                    false,
                    config('session.same_site'),
            );
    }

    // -----------------------------------------------------------------------
    // POST /api/auth/local-password  (superadmin only)
    // -----------------------------------------------------------------------
    public function setPassword(SetLocalPasswordRequest $request)
    {
        $validated = $request->validated();

        /** @var SystemUser $target */
        $target = SystemUser::findOrFail($validated['user_id']);

        $this->localAuth->setPassword($target, $validated['password']);

        $this->auditLogger->log($request, $request->user(), 'local_password_set', [
            'target_user_id' => $target->user_id,
            'target_email'   => $target->email,
        ]);

        return response()->json([
            'message' => 'Local password set successfully.',
            'user_id' => $target->user_id,
            'email'   => $target->email,
        ]);
    }

    // -----------------------------------------------------------------------
    // GET /api/auth/local-auth-status   (superadmin only)
    // -----------------------------------------------------------------------
    public function status(Request $request)
    {
        $users = SystemUser::select('user_id', 'email', 'role_id', 'status', 'local_auth_enabled')
            ->orderBy('role_id')
            ->orderBy('email')
            ->get()
            ->map(fn ($u) => [
                'user_id'            => $u->user_id,
                'email'              => $u->email,
                'role_id'            => $u->role_id,
                'status'             => $u->status,
                'local_auth_enabled' => (bool) $u->local_auth_enabled,
            ]);

        return response()->json(['users' => $users]);
    }
}