<?php

namespace App\Http\Controllers;

use App\Exceptions\AccountDeactivatedException;
use App\Exceptions\AccountExpiredException;
use App\Exceptions\AccountPendingVerificationException;
use App\Exceptions\AccountRejectedException;
use App\Exceptions\IdpException;
use App\Exceptions\UnregisteredAccountException;
use App\Http\Resources\UserResource;
use App\Services\Sso\SsoAuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Log;

class SsoCallbackController extends Controller
{
    public function __construct(private SsoAuthService $ssoAuthService) {}

    public function handle(Request $request)
    {
        $code = $request->input('code');

        if (!$code) {
            return response()->json(['message' => 'Authorization code is required.'], 422);
        }

        try {
            $result = $this->ssoAuthService->loginWithCode($code, $request);
            $token  = $result['token'];
            $user   = $result['user'];

            $user->loadIdentityRelations();

            return response()
                ->json(['user' => new UserResource($user)])
                ->withCookie(Cookie::make(
                    name:     'token',
                    value:    $token,
                    minutes:  60 * 24 * 7,
                    path:     '/',
                    domain:   config('session.domain'),
                    secure:   config('session.secure_cookie'),
                    httpOnly: true,
                    sameSite: config('session.same_site'),
                ));
        } catch (IdpException $e) {
            $this->safeLog('warning', 'SSO: IdP error', ['message' => $e->getMessage()]);
            return response()->json(['message' => $e->getMessage()], 401);
        } catch (UnregisteredAccountException $e) {
            $this->safeLog('warning', 'SSO: role error', ['message' => $e->getMessage()]);

            $logoutUrl = config('sso.base_url') . '/logout?' . http_build_query([
                'client_id'                => config('sso.client_id'),
                'post_logout_redirect_uri' => config('app.url'),
            ]);

            return response()->json([
                'message'    => $e->getMessage(),
                'logout_url' => $logoutUrl,
            ], 403);
        } catch (AccountDeactivatedException $e) {
            $this->safeLog('warning', 'SSO: deactivated account attempted login', ['message' => $e->getMessage()]);

            $logoutUrl = config('sso.base_url') . '/logout?' . http_build_query([
                'client_id'                => config('sso.client_id'),
                'post_logout_redirect_uri' => config('app.url'),
            ]);

            return response()->json([
                'message'    => $e->getMessage(),
                'logout_url' => $logoutUrl,
            ], 403);
        } catch (AccountExpiredException $e) {
            // BUG FIX (QA #11) — same shape as AccountDeactivatedException
            // above (403 + logout_url so the frontend can clear the IdP
            // session too), kept as a separate catch block so the log
            // line and any future handling can tell "deactivated" and
            // "invite expired" apart without parsing $e->getMessage().
            $this->safeLog('warning', 'SSO: expired invite attempted login', ['message' => $e->getMessage()]);

            $logoutUrl = config('sso.base_url') . '/logout?' . http_build_query([
                'client_id'                => config('sso.client_id'),
                'post_logout_redirect_uri' => config('app.url'),
            ]);

            return response()->json([
                'message'    => $e->getMessage(),
                'logout_url' => $logoutUrl,
            ], 403);
        } catch (AccountRejectedException $e) {
            // Undergrad Requestor Registration — Phase 3 (D8). Same
            // 403 + logout_url shape as AccountDeactivatedException —
            // the IdP token was already revoked by
            // SsoAuthService::revokeOnRejection() before this exception
            // reached here, but the browser's own IdP session still
            // needs the same client-side logout redirect to fully clear.
            $this->safeLog('warning', 'SSO: rejected Undergrad Requestor attempted login', ['message' => $e->getMessage()]);

            $logoutUrl = config('sso.base_url') . '/logout?' . http_build_query([
                'client_id'                => config('sso.client_id'),
                'post_logout_redirect_uri' => config('app.url'),
            ]);

            return response()->json([
                'message'    => $e->getMessage(),
                'logout_url' => $logoutUrl,
                'rejected'   => true,
            ], 403);
        } catch (AccountPendingVerificationException $e) {
            // Undergrad Requestor Registration — Phase 3. Deliberately
            // NOT logged at 'warning' level and NOT paired with a
            // logout_url the frontend needs to treat as an error state —
            // this is an expected, calm status for anyone who registered
            // and hasn't been reviewed yet, not an incident. The
            // `pending_review` flag lets the frontend render this
            // distinctly from `rejected`/deactivated 403s (e.g. no red
            // error banner) without parsing $e->getMessage().
            $this->safeLog('info', 'SSO: Undergrad Requestor login attempted while still pending review', ['message' => $e->getMessage()]);

            return response()->json([
                'message'        => $e->getMessage(),
                'pending_review' => true,
            ], 403);
        } catch (\Exception $e) {
            $this->safeLog('error', 'SSO: unexpected error', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to process SSO login.'], 500);
        }
    }

    /**
     * Log without ever letting a logging failure escape.
     *
     * Log::warning()/error() write to storage/logs/laravel.log — if that
     * file isn't writable (e.g. it got recreated by a root-owned process
     * and php-fpm runs as www-data — see start.sh), the log call itself
     * throws. Because these calls live inside catch blocks, that new
     * exception is NOT caught by a sibling catch on the same try
     * statement, so it used to escape as an uncaught 500 in place of the
     * intended, more specific response (401/403). This wrapper guarantees
     * a bad logging backend degrades to "no log line" instead of hijacking
     * the response the caller already decided on.
     */
    private function safeLog(string $level, string $message, array $context = []): void
    {
        try {
            Log::{$level}($message, $context);
        } catch (\Throwable $loggingFailure) {
            // Intentionally swallowed — see docblock above.
        }
    }
}