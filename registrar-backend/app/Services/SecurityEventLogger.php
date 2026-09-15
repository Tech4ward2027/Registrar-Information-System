<?php

namespace App\Services;

use App\Contracts\NotificationServiceInterface;
use App\Models\SecurityEvent;
use App\Models\SystemUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Records rows to security_events and — for failed local-auth logins —
 * turns a burst of them into a live SuperAdmin notification.
 *
 * This is the class that closes the gap explicitly deferred in
 * LocalAuthController's original NOTE comment: LocalAuthService::attempt()
 * already Log::warning()'d every failed attempt, but nothing turned a
 * burst of those warnings into an alert. This does both — persists the
 * event (queryable, survives container recreation, unlike storage/logs)
 * and, once a threshold is crossed, notifies.
 *
 * Deliberately NOT hash-chained like AuditLogger — see SecurityEvent's
 * docblock. No transaction/row-lock dance is needed here either: unlike
 * AuditLogger's hash chain (where two concurrent writes reading the same
 * prev_hash would corrupt the chain), each security_events row is
 * independent, so ordinary auto-increment inserts are safe under
 * concurrency.
 *
 * Registered as a singleton in AppServiceProvider, same as AuditLogger.
 *
 * ── Phase 6 (Undergrad Requestor Registration) ────────────────────────
 * Three recorders are added for this feature's public surface:
 * recordSsoLoginDenied(), recordOnboardingEmailConfirmationFailure() and
 * recordOnboardingThrottled(). They share one hard rule with everything
 * else in this class: WRITING A SECURITY EVENT MUST NEVER BREAK THE
 * REQUEST IT DESCRIBES. Every new recorder is wrapped so that a database
 * hiccup degrades to a log line rather than converting a clean 403 or
 * 429 into an uncaught 500 — the same failure mode SsoCallbackController
 * ::safeLog() exists to prevent, for the same reason.
 */
class SecurityEventLogger
{
    public function __construct(
        private NotificationServiceInterface $notificationService,
    ) {}

    // -------------------------------------------------------
    // Record a failed local-auth login attempt.
    //
    // Called from LocalAuthService::attempt() on every failure branch
    // (user not found, local auth disabled, bad password, inactive
    // account) — see that method for call sites. $email is the raw
    // attempted value, which may not correspond to any real account;
    // that's fine and expected (see the migration's docblock on why
    // this table isn't FK'd to users).
    // -------------------------------------------------------
    public function recordLoginFailure(
        string  $email,
        string  $reason,
        Request $request,
        array   $metadata = [],
    ): ?SecurityEvent {
        $event = $this->write(
            eventType: SecurityEvent::EVENT_TYPE_LOGIN_FAILED,
            email:     $email,
            reason:    $reason,
            request:   $request,
            metadata:  $metadata,
        );

        $this->maybeAlertOnBurst($email);

        return $event;
    }

    // -------------------------------------------------------
    // Record an IDP-unreachable fallback event.
    //
    // Called from AuthController's IdpUnavailableException catch block —
    // this can only ever be known RIS-side, since the IDP has no way to
    // log "I was down" (see plan doc Phase 3d). Not run through the
    // burst-alert check: a string of these reflects the IDP's own
    // availability, not a brute-force signal against one account, so
    // alerting on it is a separate ops concern outside this method's job.
    // -------------------------------------------------------
    public function recordIdpUnreachable(
        string  $email,
        Request $request,
        string  $exceptionMessage,
    ): ?SecurityEvent {
        return $this->write(
            eventType: SecurityEvent::EVENT_TYPE_IDP_UNREACHABLE,
            email:     $email,
            reason:    null,
            request:   $request,
            metadata:  ['exception_message' => $exceptionMessage],
        );
    }

    // -------------------------------------------------------
    // Phase 6 — an SSO login RIS refused AFTER the IdP authenticated
    // the person successfully.
    //
    // Called from SsoAuthService, which is the one place that holds
    // both the IdP profile (so the email is known) and the rejection
    // exception. Not burst-alerted: these are overwhelmingly ordinary —
    // a deactivated ex-employee's bookmark, an Undergrad Requestor
    // trying again a day after being refused — and paging on them would
    // train people to ignore the alert that matters.
    //
    // Worth having anyway, because "which accounts are being refused at
    // the door, and why" is a question the Registrar WILL be asked
    // during an incident, and grepping a container's log file is not an
    // answer.
    // -------------------------------------------------------
    public function recordSsoLoginDenied(
        ?string $email,
        string  $reason,
        Request $request,
        array   $metadata = [],
    ): ?SecurityEvent {
        return $this->write(
            eventType: SecurityEvent::EVENT_TYPE_SSO_LOGIN_DENIED,
            email:     $email,
            reason:    $reason,
            request:   $request,
            metadata:  $metadata,
        );
    }

    // -------------------------------------------------------
    // Phase 6 — a failed onboarding email-confirmation attempt.
    //
    // The response the caller gets is deliberately uninformative, so
    // that this endpoint cannot be used to enumerate which onboarding
    // emails exist. That makes this row the ONLY place the actual cause
    // is recorded. See UndergradRequestorRegistrationService::confirmEmail().
    // -------------------------------------------------------
    public function recordOnboardingEmailConfirmationFailure(
        ?string $email,
        string  $reason,
        Request $request,
    ): ?SecurityEvent {
        return $this->write(
            eventType: SecurityEvent::EVENT_TYPE_ONBOARDING_CONFIRM_FAILED,
            email:     $email,
            reason:    $reason,
            request:   $request,
        );
    }

    // -------------------------------------------------------
    // Phase 6 — a request refused by one of the public onboarding
    // endpoints' rate limiters.
    //
    // Called from the named limiters' response callbacks (see
    // AppServiceProvider::boot()), which is the only hook Laravel gives
    // us at the moment a limit is actually exceeded.
    //
    // DEDUPLICATED, and that is not a nicety. Without it, the defence
    // becomes the attack: a flood that trips the limiter 10,000 times
    // would write 10,000 rows, and we would have built a rate limiter
    // whose job is to turn cheap requests into expensive database
    // writes. One row per (bucket, window) preserves the whole signal —
    // "this bucket was being hammered, starting at this time" — at a
    // bounded cost. The attempt COUNT is not preserved, deliberately:
    // the limiter's own headers and the web server's access log already
    // hold volume; this table holds the fact.
    //
    // $bucket identifies which limit tripped (e.g. 'ip:203.0.113.7' or
    // 'email:juan@example.com') and is what the dedupe key is built on,
    // so a flood against one address never suppresses the record of a
    // genuine, separate flood against another.
    // -------------------------------------------------------
    public function recordOnboardingThrottled(
        string  $reason,
        string  $bucket,
        ?string $email,
        Request $request,
    ): ?SecurityEvent {
        $dedupeMinutes = max(1, (int) config('undergrad_requestor.rate_limits.throttle_event_dedupe_minutes', 10));

        // Cache::add() is atomic (SET NX on Redis) — the same primitive
        // maybeAlertOnBurst() below relies on, for the same reason:
        // under concurrency exactly one caller may win.
        $lockKey = 'security_events:onboarding_throttled:' . sha1($reason . '|' . $bucket);

        try {
            if (!Cache::add($lockKey, true, now()->addMinutes($dedupeMinutes))) {
                return null;
            }
        } catch (\Throwable $e) {
            // Cache unavailable (Redis down). Fall through and write the
            // row: losing deduplication is far better than losing the
            // signal entirely, and a Redis outage is itself rare enough
            // that the unbounded-writes concern does not apply.
            $this->safeLog('warning', '[SecurityEventLogger] throttle-event dedupe cache unavailable', [
                'error' => $e->getMessage(),
            ]);
        }

        return $this->write(
            eventType: SecurityEvent::EVENT_TYPE_ONBOARDING_THROTTLED,
            email:     $email,
            reason:    $reason,
            request:   $request,
            metadata:  [
                'bucket'          => $bucket,
                'dedupe_minutes'  => $dedupeMinutes,
            ],
        );
    }

    // -------------------------------------------------------
    // Shared insert path.
    //
    // Phase 6 hardening, both changes defensive rather than functional:
    //
    //  - Values are truncated to their column widths before insert.
    //    email in particular can now originate from an unauthenticated
    //    public form, where "at most 100 characters" is an assumption
    //    rather than a fact; a StringDataRightTruncation on MySQL in
    //    strict mode would otherwise throw from inside a catch block.
    //
    //  - The insert itself never propagates. A security-event write is
    //    a side effect of describing what just happened; it must not be
    //    able to change what happens. Callers get null and carry on.
    // -------------------------------------------------------
    private function write(
        string  $eventType,
        ?string $email,
        ?string $reason,
        Request $request,
        array   $metadata = [],
    ): ?SecurityEvent {
        try {
            return SecurityEvent::create([
                'event_type'  => mb_substr($eventType, 0, 50),
                'reason'      => $reason === null ? null : mb_substr($reason, 0, 50),
                'email'       => ($email === null || $email === '') ? null : mb_substr($email, 0, 100),
                'ip_address'  => mb_substr((string) $request->ip(), 0, 45) ?: null,
                'user_agent'  => mb_substr((string) $request->userAgent(), 0, 255),
                'metadata'    => !empty($metadata) ? $metadata : null,
                'created_at'  => now(),
            ]);
        } catch (\Throwable $e) {
            $this->safeLog('warning', '[SecurityEventLogger] failed to persist security event', [
                'event_type' => $eventType,
                'reason'     => $reason,
                'error'      => $e->getMessage(),
            ]);

            return null;
        }
    }

    // -------------------------------------------------------
    // Burst detection — "N failed local-auth attempts in a window" per
    // Phase 3e. Scoped by email (not IP): local-auth accounts are a
    // small, known set of break-glass Super Admin accounts, so a burst
    // against one specific email is the meaningful signal here, per the
    // plan doc's open question #3e answer.
    //
    // Cache::add() is atomic (SET NX under the hood on Redis) — this is
    // what guarantees only ONE notification fires per burst instead of
    // one per attempt once the threshold is crossed. The lock's TTL
    // equals the alert window, so a fresh burst starting after the
    // window has fully elapsed can alert again.
    // -------------------------------------------------------
    private function maybeAlertOnBurst(string $email): void
    {
        $threshold     = (int) config('security_events.alert_threshold', 5);
        $windowMinutes = (int) config('security_events.alert_window_minutes', 10);

        $recentFailures = SecurityEvent::query()
            ->where('event_type', SecurityEvent::EVENT_TYPE_LOGIN_FAILED)
            ->where('email', $email)
            ->where('created_at', '>=', now()->subMinutes($windowMinutes))
            ->count();

        if ($recentFailures < $threshold) {
            return;
        }

        $lockKey = "security_events:alerted:{$email}";

        // add() only succeeds if the key does NOT already exist — the
        // second and later calls within the same window are no-ops, so
        // exactly one notification goes out per burst, not one per
        // attempt past the threshold.
        $alertIsNew = Cache::add($lockKey, true, now()->addMinutes($windowMinutes));

        if (!$alertIsNew) {
            return;
        }

        // Same audience as the local_auth_login_used alert (Admin + Super
        // Admin, excluding student/alumni/undergrad-requestor) — a burst
        // of failed break-glass attempts is exactly as relevant to that
        // audience as a successful one. See LocalAuthController::login()
        // for the same reasoning on why sendToAdmins() alone would be
        // wrong here.
        //
        // Undergrad Requestor Registration — Phase 5: ROLE_UNDERGRAD_REQUESTOR
        // added to the exclusion list. Without this, sendToAllExcept()
        // — which notifies every role NOT named here — would start
        // notifying every Undergrad Requestor about OTHER people's
        // failed-login bursts the moment role 5 existed, since a new
        // role is included by default unless explicitly excluded. A
        // regular self-service account has no operational reason to see
        // this alert, exactly like Student/Alumni already don't.
        $this->notificationService->sendToAllExcept(
            excludedRoleIds: [
                SystemUser::ROLE_STUDENT,
                SystemUser::ROLE_ALUMNI,
                SystemUser::ROLE_UNDERGRAD_REQUESTOR,
            ],
            triggerEvent:    'security_alert_failed_login_burst',
            data: [
                'email'           => $email,
                'attempt_count'   => $recentFailures,
                'window_minutes'  => $windowMinutes,
            ],
        );
    }

    /**
     * Log without ever letting a logging failure escape — same wrapper,
     * same reasoning, as SsoAuthService::safeLog() and
     * SsoCallbackController::safeLog(). Calls here happen while already
     * handling a failure; a throw from the logger would replace the
     * caller's intended response with a generic 500.
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