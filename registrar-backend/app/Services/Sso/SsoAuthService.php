<?php

namespace App\Services\Sso;

use App\Exceptions\AccountDeactivatedException;
use App\Exceptions\AccountRejectedException;
use App\Exceptions\IdpException;
use App\Exceptions\IdpUnavailableException;
use App\Exceptions\UnregisteredAccountException;
use App\Models\AuditLog;
use App\Models\SystemUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates all SSO authentication flows.
 *
 * - loginWithCode: OAuth redirect flow — exchanges an authorization code
 *   for a token, provisions the user, issues a Sanctum token.
 * - loginWithCredentials: authenticates against the IdP with email + password,
 *   provisions the local user, issues a Sanctum token, and writes an audit log.
 * - logout: revokes the IdP token, deletes Sanctum tokens, writes
 *   an audit log, and returns the IdP logout URL for the frontend.
 *
 * AuthController stays a thin HTTP adapter — no IdP calls or
 * audit logs happen there.
 */
class SsoAuthService
{
    public function __construct(
        private IdpClient               $idpClient,
        private UserProvisioningService $provisioner,
        private \App\Services\AuditLogger $auditLogger,
    ) {}

    /**
     * Authenticate via OAuth authorization code (SSO redirect flow).
     *
     * Rejection is handled by UserProvisioningService, which throws
     * UnregisteredAccountException if the user has no registered role in
     * RIS. SsoCallbackController catches that specific type and returns a
     * 403 — any other exception (e.g. a QueryException from a provisioning
     * bug) is deliberately NOT caught here, so it falls through to the
     * generic 500 handler instead of being misread as "not registered."
     *
     * @return array{token: string, user: SystemUser}
     * @throws IdpException|UnregisteredAccountException
     */
    public function loginWithCode(string $code, Request $request): array
    {
        $accessToken = $this->idpClient->exchangeCode($code);
        $profile     = $this->idpClient->fetchUserProfile($accessToken);

        try {
            $result = $this->provisioner->provision(
                array_merge($profile, ['access_token' => $accessToken]),
                $request,
            );
        } catch (UnregisteredAccountException|AccountDeactivatedException|AccountRejectedException $e) {
            $this->revokeOnRejection($accessToken, $profile);
            throw $e;
        }

        /** @var SystemUser $user */
        $user = $result->user;

        $user->update([
            'idp_access_token' => $accessToken,
            'idp_user_id'      => $profile['id'] ?? $user->idp_user_id,
        ]);

        $token = $user->createToken('sanctum')->plainTextToken;

        $this->auditLogger->log($request, $user, AuditLog::ACTION_LOGIN);

        return ['token' => $token, 'user' => $user];
    }

    /**
     * Authenticate with email + password via the IdP.
     *
     * @return array{token: string, user: SystemUser}
     * @throws IdpException|UnregisteredAccountException
     */
    public function loginWithCredentials(
        string  $email,
        string  $password,
        Request $request,
    ): array {
        try {
            $code        = $this->idpClient->loginAndGetCode($email, $password);
            $accessToken = $this->idpClient->exchangeCode($code);
            $profile     = $this->idpClient->fetchUserProfile($accessToken);
        } catch (IdpException $e) {
            // Re-throw connectivity errors as a distinct type so the
            // caller can fall back to local auth without treating a genuine
            // "wrong password" from the IDP as a connectivity issue.
            if ($this->idpClient->lastErrorWasConnectivity()) {
                throw new IdpUnavailableException($e->getMessage(), $e->getCode(), $e);
            }
            throw $e;
        }

        $idpResponse = array_merge($profile, [
            'access_token' => $accessToken,
            'user_id'      => $profile['id'] ?? null,
        ]);

        try {
            $result = $this->provisioner->provision($idpResponse, $request);
        } catch (UnregisteredAccountException|AccountDeactivatedException|AccountRejectedException $e) {
            $this->revokeOnRejection($accessToken, $profile);
            throw $e;
        }

        $user = $result->user;

        $user->update([
            'idp_access_token' => $accessToken,
            'idp_user_id'      => $profile['id'] ?? $user->idp_user_id,
        ]);

        $token = $user->createToken('sanctum')->plainTextToken;
        $this->auditLogger->log($request, $user, AuditLog::ACTION_LOGIN);

        return ['token' => $token, 'user' => $user];
    }

    /**
     * Revoke the just-issued IdP token when RIS rejects the login (e.g. the
     * user has no registered role — see UserProvisioningService::provision).
     *
     * Without this, the IdP session/token stays valid even though RIS
     * refused the login. The frontend's "Back to Login" button only fires a
     * passive browser redirect to the IdP's /logout page — if that alone
     * doesn't fully clear the session, the next "Log in with IDP" click
     * silently reuses it and immediately fails again, looping forever.
     * Revoking server-side here closes that gap regardless of what the
     * browser-side redirect does or doesn't clear.
     *
     * Best-effort: a failed revoke must never mask the original rejection
     * (UnregisteredAccountException) or block the user from seeing why they
     * were rejected, so failures are logged and swallowed.
     */
    private function revokeOnRejection(string $accessToken, array $profile): void
    {
        try {
            $this->idpClient->logout($accessToken, $profile['id'] ?? null);
        } catch (\Exception $e) {
            $this->safeLog('warning', 'SSO: token revoke failed during rejection', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Log without ever letting a logging failure escape.
     *
     * Log::*() writes to storage/logs/laravel.log — if that file was
     * recreated by a root-owned process while php-fpm runs as www-data
     * (see start.sh), the log call itself throws. Calls here happen
     * inside catch blocks (e.g. revokeOnRejection, logout), so an
     * uncaught throw from Log:: replaces the intended
     * UnregisteredAccountException/response with a generic 500 — which is
     * exactly the bug this guards against. Mirrors
     * SsoCallbackController::safeLog().
     */
    private function safeLog(string $level, string $message, array $context = []): void
    {
        try {
            Log::{$level}($message, $context);
        } catch (\Throwable $loggingFailure) {
            // Intentionally swallowed — see docblock above.
        }
    }

    /**
     * Log the user out.
     *
     * @param  string $authMethod  'idp' or 'local'.  When 'local', the IdP
     *                             revocation call is skipped (the IDP never
     *                             issued a session for this login) and null
     *                             is returned so the frontend redirects to "/"
     *                             instead of the IdP logout page.
     * @return string|null  IdP logout URL, or null for local-auth sessions.
     */
    public function logout(SystemUser $user, Request $request, string $authMethod = 'idp'): ?string
    {
        $this->auditLogger->log($request, $user, AuditLog::ACTION_LOGOUT);

        $isLocal = $authMethod === 'local';

        // Only call the IdP when this session was established through it.
        // Local-auth sessions have no IdP token to revoke.
        if (!$isLocal && $user->idp_access_token) {
            try {
                $this->idpClient->logout($user->idp_access_token, $user->idp_user_id);
            } catch (\Exception $e) {
                $this->safeLog('warning', 'SSO: logout call failed', ['error' => $e->getMessage()]);
            }
        }

        $user->tokens()->delete();

        if ($isLocal) {
            $this->safeLog('info', 'SSO: local-auth logout — skipping IdP redirect', [
                'user_id' => $user->user_id,
            ]);
            return null;
        }

        // post_logout_redirect_uri tells the IdP where to send the browser after
        // it clears its own session.  Without it the IdP logs:
        //   "Skipping logout API call because logout query params are incomplete."
        // and may not fully clear the IdP browser session, causing subsequent
        // SSO logins to silently reuse the old session.
        return config('sso.base_url') . '/logout?' . http_build_query([
            'client_id'                => config('sso.client_id'),
            'post_logout_redirect_uri' => config('app.url'),
        ]);
    }
}