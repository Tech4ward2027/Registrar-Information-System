<?php

namespace App\Services;

use App\Contracts\NotificationServiceInterface;
use App\Models\SecurityEvent;
use App\Models\SystemUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

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
    ): SecurityEvent {
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
    ): SecurityEvent {
        return $this->write(
            eventType: SecurityEvent::EVENT_TYPE_IDP_UNREACHABLE,
            email:     $email,
            reason:    null,
            request:   $request,
            metadata:  ['exception_message' => $exceptionMessage],
        );
    }

    // -------------------------------------------------------
    // Shared insert path.
    // -------------------------------------------------------
    private function write(
        string  $eventType,
        ?string $email,
        ?string $reason,
        Request $request,
        array   $metadata = [],
    ): SecurityEvent {
        return SecurityEvent::create([
            'event_type'  => $eventType,
            'reason'      => $reason,
            'email'       => $email,
            'ip_address'  => $request->ip(),
            'user_agent'  => substr((string) $request->userAgent(), 0, 255),
            'metadata'    => !empty($metadata) ? $metadata : null,
            'created_at'  => now(),
        ]);
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
}