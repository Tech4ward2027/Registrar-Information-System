<?php

namespace App\Providers;

use App\Models\Ticket;
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
