<?php

namespace App\Providers;

use App\Contracts\AlumniSystemClientInterface;
use App\Contracts\CashierServiceInterface;
use App\Contracts\DocumentRequestServiceInterface;
use App\Contracts\NotificationServiceInterface;
use App\Contracts\UndergradEnrollmentLookupClientInterface;
use App\Contracts\UndergradRequestorRegistrationServiceInterface;
use App\Contracts\UndergradRequestorVerificationServiceInterface;
use App\Models\NotificationType;
use App\Models\SecurityEvent;
use App\Observers\NotificationTypeObserver;
use App\Services\AuditLogger;
use App\Services\SecurityEventLogger;
use App\Services\Alumni\AlumniSystemClient;
use App\Services\Alumni\FakeAlumniSystemClient;
use App\Services\CashierService;
use App\Services\DocumentRequestService;
use App\Services\NotificationService;
use App\Services\Ogos\OgosEnrollmentLookupClient;
use App\Services\UndergradRequestorRegistrationService;
use App\Services\UndergradRequestorVerificationService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register interface → concrete-class bindings.
     *
     * Controllers and services depend on the interfaces (left-hand side).
     * Laravel's container resolves them to the concrete classes (right-hand side).
     * To swap an implementation — e.g. a fake notification service for tests —
     * change only this file; no controller or service needs to change.
     */
    public function register(): void
    {
        $this->app->bind(
            DocumentRequestServiceInterface::class,
            DocumentRequestService::class,
        );

        $this->app->bind(
            NotificationServiceInterface::class,
            NotificationService::class,
        );

        // CashierService — no swap condition today (unlike the alumni
        // client's mock/real split above), but every consumer now depends
        // on the interface rather than the concrete class, so a future
        // provider change, a different transport, or a local-dev fake
        // becomes a one-line change here instead of a hunt through every
        // constructor/type-hint that references CashierService directly.
        $this->app->bind(
            CashierServiceInterface::class,
            CashierService::class,
        );

        // Alumni System client — swap between real HTTP and fake based on env.
        // Set ALUMNI_MOCK=true in .env (or docker-compose environment) to use
        // hardcoded dummy data instead of calling PUPTAPS.
        // Production: ALUMNI_MOCK=false (or omit entirely — defaults to false).
        $this->app->bind(
            AlumniSystemClientInterface::class,
            env('ALUMNI_MOCK', false)
                ? FakeAlumniSystemClient::class
                : AlumniSystemClient::class,
        );

        // Undergrad Requestor Registration — Phase 2.
        $this->app->bind(
            UndergradRequestorRegistrationServiceInterface::class,
            UndergradRequestorRegistrationService::class,
        );

        // Undergrad Requestor Registration — Phase 4. The Admin
        // verification workflow.
        $this->app->bind(
            UndergradRequestorVerificationServiceInterface::class,
            UndergradRequestorVerificationService::class,
        );

        // Undergrad Requestor Registration — Phase 4 (D6). The advisory,
        // never-throws OGOS enrollment lookup. Bound to its interface
        // (rather than injected as a concrete class) for the same reason
        // the alumni client is: tests and any future OGOS-unavailable
        // simulation swap the implementation here and nowhere else. See
        // UndergradEnrollmentLookupClientInterface for why this is a
        // separate, narrow contract rather than a method on
        // OgosStudentService.
        $this->app->bind(
            UndergradEnrollmentLookupClientInterface::class,
            OgosEnrollmentLookupClient::class,
        );

        // AuditLogger is a concrete class — no interface needed.
        // Singleton so the same instance is reused within a request.
        $this->app->singleton(AuditLogger::class);

        // SecurityEventLogger (Phase 3) — same reasoning as AuditLogger
        // above: concrete class, no interface needed, singleton so the
        // same instance (and any request-scoped state it may accrue) is
        // reused within a request.
        $this->app->singleton(SecurityEventLogger::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Invalidate the NotificationType cache whenever a type is saved or
        // deleted. Without this, admin edits to notification templates would
        // have no effect for up to 6 hours (the cache TTL in NotificationService).
        NotificationType::observe(NotificationTypeObserver::class);

        $this->registerRateLimiters();
    }

    /**
     * Undergrad Requestor Registration — Phase 6.
     *
     * Named rate limiters for the feature's public, unauthenticated
     * endpoints. This is the first use of named limiters in this
     * codebase — every other throttled route uses the anonymous
     * 'throttle:N,M[,prefix]' form, which is entirely adequate for the
     * authenticated routes it guards but cannot express what this
     * surface needs:
     *
     *   • A bucket keyed on the SUBMITTED EMAIL, not just the caller's
     *     IP. The abuse case that per-IP limiting cannot touch is
     *     someone repeatedly submitting a third party's address from a
     *     pool of IPs, turning our confirmation mail into a mail-bomb
     *     aimed at a person who never came near this form.
     *
     *   • Several buckets with different decay periods on one route. A
     *     per-minute ceiling stops scripted flooding; a per-day ceiling
     *     stops the patient version that stays under it all afternoon.
     *
     *   • A ->response() hook. Without it a tripped limit is a 429 and
     *     nothing else — no record that this endpoint is under pressure
     *     survives past the web server's access log. With it, the event
     *     lands in security_events (deduplicated — see
     *     SecurityEventLogger::recordOnboardingThrottled(), which
     *     explains why that matters more than it sounds).
     *
     * All ceilings live in config/undergrad_requestor.php so they can be
     * tuned per-environment without a deploy. Defaults are set for a
     * university, not a consumer signup page: a computer lab full of
     * students registering from one NAT address must not lock itself
     * out, so the per-IP daily figure is deliberately generous and the
     * per-email one is deliberately tight. If one of these bites in
     * production, raise the per-IP limits — do not relax the per-email
     * one, which is the bucket protecting someone who is not even using
     * the system.
     */
    private function registerRateLimiters(): void
    {
        $limits = fn (string $key, int $default): int => max(1, (int) config("undergrad_requestor.rate_limits.{$key}", $default));

        // ── Onboarding submission ────────────────────────────────────
        RateLimiter::for('undergrad-requestor-register', function (Request $request) use ($limits) {
            $ip    = $this->callerKey($request);
            $email = $this->submittedEmail($request);

            $deny = fn (string $bucket) => $this->throttledResponse(
                SecurityEvent::REASON_THROTTLED_REGISTER,
                $bucket,
                $email,
            );

            return [
                Limit::perMinute($limits('register_per_ip_per_minute', 5))
                    ->by("ur-register:ip-min:{$ip}")
                    ->response($deny("ip:{$ip}")),

                Limit::perDay($limits('register_per_ip_per_day', 60))
                    ->by("ur-register:ip-day:{$ip}")
                    ->response($deny("ip:{$ip}")),

                // Falls back to the IP when no email was supplied, so a
                // malformed flood still meets a ceiling rather than
                // sharing one unlimited anonymous bucket with everyone
                // else who also sent nothing.
                Limit::perDay($limits('register_per_email_per_day', 5))
                    ->by('ur-register:email-day:' . ($email ?? $ip))
                    ->response($deny($email !== null ? "email:{$email}" : "ip:{$ip}")),
            ];
        });

        // ── Email confirmation ───────────────────────────────────────
        // Tighter per-email, looser per-IP than registration: this
        // endpoint writes far less, but it is the one an attacker would
        // grind against to guess a confirmation token. The token is 40
        // random characters compared in constant time, so guessing is
        // not a realistic threat — but a limiter is how we find out
        // someone is TRYING, which the constant-time comparison on its
        // own would never tell us.
        RateLimiter::for('undergrad-requestor-confirm-email', function (Request $request) use ($limits) {
            $ip    = $this->callerKey($request);
            $email = $this->submittedEmail($request);

            $deny = fn (string $bucket) => $this->throttledResponse(
                SecurityEvent::REASON_THROTTLED_CONFIRM,
                $bucket,
                $email,
            );

            return [
                Limit::perMinute($limits('confirm_per_ip_per_minute', 10))
                    ->by("ur-confirm:ip-min:{$ip}")
                    ->response($deny("ip:{$ip}")),

                Limit::perHour($limits('confirm_per_email_per_hour', 20))
                    ->by('ur-confirm:email-hour:' . ($email ?? $ip))
                    ->response($deny($email !== null ? "email:{$email}" : "ip:{$ip}")),
            ];
        });

        // ── Data Privacy notice (read-only) ──────────────────────────
        // No security event on trip: this endpoint exposes no personal
        // data, creates nothing, and is fetched once per page load, so a
        // tripped limit here is a bored script rather than a signal
        // worth storing. Plain per-IP ceiling, no ->response() hook.
        RateLimiter::for('undergrad-requestor-notice', function (Request $request) use ($limits) {
            return Limit::perMinute($limits('notice_per_ip_per_minute', 30))
                ->by('ur-notice:ip:' . $this->callerKey($request));
        });
    }

    /**
     * Build the 429 responder for one bucket.
     *
     * Returns a closure because that is the shape Limit::response()
     * expects; it receives the request and the Retry-After/X-RateLimit
     * headers Laravel has already computed, which are passed straight
     * through so standard client back-off behaviour still works.
     *
     * The body says nothing about WHICH bucket tripped. A caller who
     * learns "you hit the per-email limit" has learned that the address
     * they guessed is one we care about; the useful detail belongs in
     * security_events, where only we can read it.
     */
    private function throttledResponse(string $reason, string $bucket, ?string $email): \Closure
    {
        return function (Request $request, array $headers) use ($reason, $bucket, $email) {
            // Resolved per-call rather than captured at boot: the
            // container is not necessarily ready to build this service
            // while providers are still booting, and a limiter closure
            // may live for the whole request lifecycle.
            app(SecurityEventLogger::class)->recordOnboardingThrottled(
                reason:  $reason,
                bucket:  $bucket,
                email:   $email,
                request: $request,
            );

            return response()->json([
                'message' => 'Too many attempts. Please wait a few minutes and try again.',
            ], 429, $headers);
        };
    }

    /**
     * The caller's IP, or a stable placeholder.
     *
     * $request->ip() can be null behind a misconfigured proxy. Keying a
     * limiter on null would silently collapse every such caller into one
     * shared bucket AND produce a confusing cache key; naming the case
     * explicitly keeps both the limiting and the security event honest
     * about what is and is not known.
     */
    private function callerKey(Request $request): string
    {
        return $request->ip() ?: 'unknown-ip';
    }

    /**
     * The submitted email, normalised exactly the way
     * StoreUndergradRequestorRegistrationRequest::prepareForValidation()
     * normalises it.
     *
     * This has to match, or the limiter and the uniqueness check would
     * disagree about what counts as "the same address" and the per-email
     * bucket could be walked straight past with alternating
     * capitalisation.
     *
     * Runs BEFORE validation (limiters are middleware), so the value is
     * raw user input: it may be absent, a non-string, or 10KB of junk.
     * Hence the type guard and the length cap.
     */
    private function submittedEmail(Request $request): ?string
    {
        $email = $request->input('email');

        if (!is_string($email)) {
            return null;
        }

        $email = Str::lower(trim($email));

        if ($email === '') {
            return null;
        }

        // users.email is varchar(100) and security_events.email likewise;
        // anything longer cannot correspond to a real account, and
        // truncating here keeps an oversized value from becoming an
        // oversized cache key.
        return Str::limit($email, 100, '');
    }
}