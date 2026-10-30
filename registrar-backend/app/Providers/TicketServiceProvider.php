<?php

namespace App\Providers;

use App\Models\Ticket;
use App\Services\Tickets\Captcha\CaptchaVerifier;
use App\Services\Tickets\Captcha\NullCaptchaVerifier;
use App\Services\Tickets\Captcha\TurnstileCaptchaVerifier;
use App\Services\Tickets\PublicSubmission\TicketAccessTokenService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

/**
 * Support Ticketing — named rate limiters.
 *
 * Kept out of AppServiceProvider so the feature stays in one place. Named
 * limiters (not 'throttle:N,M,prefix') because the replies limit is keyed on
 * the TICKET, which the anonymous form cannot express, and so every ceiling is
 * read from config/tickets.php at request time (changing a value or overriding
 * it in a test needs no re-registration).
 *
 * Phase 3 registers the authenticated limiters. Phase 4 adds the public ones
 * (verification emails, public tickets per email / IP, replies per magic link)
 * here, in this same class.
 *
 * Every limiter returns the standard 429 with a Retry-After header, which the
 * frontend reads (see the api.js interceptor and config/cors.php exposing the
 * header).
 */
class TicketServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Decided when first resolved (not at boot) so the setting is read at request time.
        $this->app->bind(CaptchaVerifier::class, fn () => config('tickets.public.captcha_enabled')
            ? new TurnstileCaptchaVerifier()
            : new NullCaptchaVerifier());
    }

    public function boot(): void
    {
        // New tickets: N per window per signed-in user (policy: 3 per 10 min).
        RateLimiter::for('tickets-create', function (Request $request) {
            $limits = config('tickets.rate_limits.authenticated');

            return Limit::perMinutes(
                max(1, (int) $limits['tickets_window_minutes']),
                max(1, (int) $limits['tickets_per_window']),
            )
                ->by('tickets-create:user:' . $this->callerKey($request))
                ->response($this->tooMany('You are creating tickets too quickly. Please wait before filing another.'));
        });

        // Requester replies: N per TICKET per hour (policy: 10), shared by
        // everyone replying as that ticket's requester. Staff replies are not
        // limited here: they are bounded by the module permission and the
        // group's general limit.
        RateLimiter::for('tickets-reply', function (Request $request) {
            return Limit::perHour(max(1, (int) config('tickets.rate_limits.replies_per_ticket_per_hour')))
                ->by('tickets-reply:ticket:' . $this->ticketKey($request))
                ->response($this->tooMany('This ticket has received too many replies. Please try again later.'));
        });

        $this->registerPublicLimiters();
    }

    /**
     * Phase 4: the public (unauthenticated) limiters. All keyed by IP except where noted.
     *
     * The per-ADDRESS hourly ceiling on verification emails is not here: it is
     * counted inside PublicSubmissionService after the anti-bot checks, so that
     * junk requests naming someone else's address cannot use up their allowance.
     */
    private function registerPublicLimiters(): void
    {
        RateLimiter::for('tickets-public-form', fn (Request $r) => Limit::perMinute(
            max(1, (int) config('tickets.rate_limits.public.form_per_ip_per_minute'))
        )->by('tickets-public-form:' . $this->callerKey($r))
            ->response($this->tooMany('Too many requests. Please slow down.')));

        // A burst ceiling and the policy's hourly one, both per IP.
        RateLimiter::for('tickets-public-start', function (Request $r) {
            $limits = config('tickets.rate_limits.public');
            $ip     = $this->callerKey($r);

            return [
                Limit::perMinute(max(1, (int) $limits['start_per_ip_per_minute']))
                    ->by('tickets-public-start-min:' . $ip)
                    ->response($this->tooMany('Too many attempts. Please wait a moment and try again.')),
                Limit::perHour(max(1, (int) $limits['verification_emails_per_ip_per_hour']))
                    ->by('tickets-public-start-hour:' . $ip)
                    ->response($this->tooMany('Too many requests from your network. Please try again later.')),
            ];
        });

        RateLimiter::for('tickets-public-confirm', fn (Request $r) => Limit::perMinute(
            max(1, (int) config('tickets.rate_limits.public.confirm_per_ip_per_minute'))
        )->by('tickets-public-confirm:' . $this->callerKey($r))
            ->response($this->tooMany('Too many attempts. Please wait a moment and try again.')));

        // Counts EVERY request, matched or not, so the limit itself reveals nothing
        // about which tickets exist. The per-address bucket is separate from the
        // verification one (see the config note on resend_per_email_per_hour).
        RateLimiter::for('tickets-public-resend', function (Request $r) {
            $limits = config('tickets.rate_limits.public');
            $email  = mb_strtolower(trim((string) $r->input('email')));

            return [
                Limit::perHour(max(1, (int) $limits['resend_per_ip_per_hour']))
                    ->by('tickets-public-resend-ip:' . $this->callerKey($r))
                    ->response($this->tooMany('Too many requests from your network. Please try again later.')),
                Limit::perHour(max(1, (int) $limits['resend_per_email_per_hour']))
                    ->by('tickets-public-resend-email:' . sha1($email))
                    ->response($this->tooMany('Too many link requests for this address. Please try again later.')),
            ];
        });

        RateLimiter::for('tickets-public-track', fn (Request $r) => Limit::perMinute(
            max(1, (int) config('tickets.rate_limits.public.track_per_ip_per_minute'))
        )->by('tickets-public-track:' . $this->callerKey($r))
            ->response($this->tooMany('Too many requests. Please slow down.')));

        // Replies per TICKET per hour (policy: 10). The ticket comes from the token,
        // not the URL; an invalid token falls back to the IP so it cannot dodge the limit.
        RateLimiter::for('tickets-public-reply', function (Request $r) {
            $ticket = app(TicketAccessTokenService::class)->ticketFor($r);

            return Limit::perHour(max(1, (int) config('tickets.rate_limits.replies_per_ticket_per_hour')))
                ->by('tickets-public-reply:' . ($ticket ? 'ticket:' . $ticket->public_id : 'ip:' . $this->callerKey($r)))
                ->response($this->tooMany('This ticket has received too many replies. Please try again later.'));
        });
    }

    /**
     * The route parameter is still the raw public_id string when throttling
     * runs (route-model binding happens later), but accept a bound model too
     * so the limiter does not depend on middleware order.
     */
    private function ticketKey(Request $request): string
    {
        $ticket = $request->route('ticket');
        $id     = $ticket instanceof Ticket ? $ticket->public_id : (string) $ticket;

        return Str::limit($id !== '' ? $id : 'none', 26, '');
    }

    private function callerKey(Request $request): string
    {
        return (string) ($request->user()?->getAuthIdentifier() ?? $request->ip() ?? 'unknown');
    }

    private function tooMany(string $message): \Closure
    {
        return static fn (Request $request, array $headers) => response()->json([
            'message'     => $message,
            'code'        => 'rate_limited',
            'retry_after' => isset($headers['Retry-After']) ? (int) $headers['Retry-After'] : null,
        ], 429, $headers);
    }
}