<?php

namespace App\Services\Tickets\PublicSubmission;

use App\Models\Ticket;
use App\Models\TicketAccessToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Magic-link tokens: how a public submitter (no account) gets back into their
 * ticket.
 *
 * - The token is `token_bytes` (>= 32) random bytes, URL-safe base64. Only its
 *   SHA-256 is stored, so a database read cannot be turned into working links.
 *   A SHA-256 lookup is the right tool here (not a slow password hash): the
 *   token is already 256 bits of entropy, so there is nothing to brute-force.
 * - It is valid until the ticket is Closed plus `token_grace_days` (about 7),
 *   and never for an account-holder's ticket or an anonymized one. That is
 *   decided here from the TICKET, so closing a ticket needs no write to the
 *   token rows.
 * - Every failure looks the same to the caller (null): unknown, malformed,
 *   expired and tampered tokens are indistinguishable.
 */
class TicketAccessTokenService
{
    public const HEADER = 'X-Ticket-Token';

    private const REQUEST_ATTRIBUTE = 'ticket_access.resolved';

    /** Issue a new link token for the ticket. Returns the PLAIN token (shown once, in the email). */
    public function issue(Ticket $ticket): string
    {
        $bytes = max(32, (int) config('tickets.public.token_bytes'));
        $plain = rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');

        TicketAccessToken::query()->create([
            'ticket_id'  => $ticket->getKey(),
            'token_hash' => $this->hash($plain),
        ]);

        $this->retireOldest($ticket);

        return $plain;
    }

    /** The ticket a plain token opens, or null. */
    public function resolve(?string $plain): ?Ticket
    {
        // Cheap shape check first: junk never reaches the database.
        if ($plain === null || ! preg_match('/^[A-Za-z0-9_-]{40,200}$/', $plain)) {
            return null;
        }

        $token = TicketAccessToken::query()->with('ticket')->where('token_hash', $this->hash($plain))->first();
        $ticket = $token?->ticket;

        if ($ticket === null || ! $this->linkStillValid($ticket)) {
            return null;
        }

        // A write per page view would be wasteful; this only says "recently used".
        if ($token->last_used_at === null || $token->last_used_at->lt(now()->subMinutes(10))) {
            $token->forceFill(['last_used_at' => now()])->save();
        }

        return $ticket;
    }

    /**
     * The ticket for the request's X-Ticket-Token header. Remembered on the
     * request, because the middleware and the rate limiter both ask.
     */
    public function ticketFor(Request $request): ?Ticket
    {
        if ($request->attributes->has(self::REQUEST_ATTRIBUTE)) {
            return $request->attributes->get(self::REQUEST_ATTRIBUTE);
        }

        $header = $request->header(self::HEADER);
        $ticket = $this->resolve(is_string($header) ? $header : null);

        if ($ticket === null) {
            Log::info('tickets.public: rejected ticket link.', ['ip' => $request->ip(), 'had_token' => $header !== null]);
        }

        $request->attributes->set(self::REQUEST_ATTRIBUTE, $ticket);

        return $ticket;
    }

    /** Can a link be (re)issued and used for this ticket at all? */
    public function linkStillValid(Ticket $ticket): bool
    {
        if ($ticket->requester_user_id !== null || $ticket->anonymized_at !== null) {
            return false;
        }

        if ($ticket->closed_at !== null) {
            $grace = max(0, (int) config('tickets.public.token_grace_days'));

            return $ticket->closed_at->copy()->addDays($grace)->isFuture();
        }

        return true;
    }

    /** The front-end URL the email links to. The token is in the fragment, so it never reaches a server log. */
    public function trackUrl(string $plain): string
    {
        return rtrim((string) config('app.frontend_url'), '/')
            . '/' . ltrim((string) config('tickets.public.links.track_path'), '/')
            . '#token=' . $plain;
    }

    private function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /** Keep only the newest few links per ticket. */
    private function retireOldest(Ticket $ticket): void
    {
        $max = max(1, (int) config('tickets.public.max_access_tokens_per_ticket'));

        $stale = TicketAccessToken::query()
            ->where('ticket_id', $ticket->getKey())
            ->orderByDesc('id')
            ->offset($max)->limit(1000)
            ->pluck('id');

        if ($stale->isNotEmpty()) {
            TicketAccessToken::query()->whereIn('id', $stale)->delete();
        }
    }
}
