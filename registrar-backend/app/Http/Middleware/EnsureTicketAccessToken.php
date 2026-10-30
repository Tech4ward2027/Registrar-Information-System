<?php

namespace App\Http\Middleware;

use App\Services\Tickets\PublicSubmission\TicketAccessTokenService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for the magic-link endpoints (alias 'ticket.token').
 *
 * Reads the X-Ticket-Token header, resolves it to a public ticket and exposes
 * that ticket to the controller as the `public_ticket` request attribute. An
 * unknown, malformed, tampered, expired or anonymized token all answer the same
 * 404, so the response never says which, and there is no ticket id in the URL
 * for anyone to enumerate.
 */
class EnsureTicketAccessToken
{
    public function __construct(private readonly TicketAccessTokenService $tokens) {}

    public function handle(Request $request, Closure $next): Response
    {
        $ticket = $this->tokens->ticketFor($request);

        if ($ticket === null) {
            return response()->json([
                'message' => 'This link is invalid or has expired. You can request a new one.',
                'code'    => 'invalid_ticket_link',
            ], 404);
        }

        $request->attributes->set('public_ticket', $ticket);

        return $next($request);
    }
}
