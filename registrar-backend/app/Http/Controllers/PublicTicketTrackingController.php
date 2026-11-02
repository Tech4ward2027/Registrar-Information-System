<?php

namespace App\Http\Controllers;

use App\Http\Requests\Ticket\PublicTicket\PublicReplyRequest;
use App\Http\Requests\Ticket\PublicTicket\PublicReturnRequest;
use App\Http\Requests\Ticket\PublicTicket\PublicTicketActionRequest;
use App\Http\Resources\TicketResource;
use App\Models\Ticket;
use App\Services\Tickets\TicketService;
use Illuminate\Http\JsonResponse;

/**
 * Support Ticketing — Phase 4: a public submitter following their ticket
 * through the magic link.
 *
 * The 'ticket.token' middleware has already resolved the X-Ticket-Token header
 * to the ticket (the `public_ticket` request attribute). There is no ticket id
 * in any URL. The actions are the same TicketService calls the signed-in
 * requester uses, with no account behind them, so the status rules (return
 * limit, Closed is final) are identical by construction. The view is the same
 * requester TicketResource: staff are never named.
 */
class PublicTicketTrackingController extends Controller
{
    public function __construct(private readonly TicketService $tickets) {}

    // GET /public/tickets/track
    public function show(PublicTicketActionRequest $request): JsonResponse
    {
        return $this->respond($request->attributes->get('public_ticket'));
    }

    // POST /public/tickets/track/replies
    public function reply(PublicReplyRequest $request): JsonResponse
    {
        $ticket = $request->attributes->get('public_ticket');
        $this->tickets->replyAsRequester($ticket, null, $request->validated('body'));

        return $this->respond($ticket, 201);
    }

    // POST /public/tickets/track/confirm
    public function confirm(PublicTicketActionRequest $request): JsonResponse
    {
        $ticket = $request->attributes->get('public_ticket');
        $this->tickets->confirm($ticket, null);

        return $this->respond($ticket);
    }

    // POST /public/tickets/track/return
    public function returnToProgress(PublicReturnRequest $request): JsonResponse
    {
        $ticket = $request->attributes->get('public_ticket');
        $this->tickets->returnToProgress($ticket, null, $request->validated('reason'));

        return $this->respond($ticket);
    }

    private function respond(Ticket $ticket, int $status = 200): JsonResponse
    {
        $fresh = Ticket::query()->with(['category', 'messages'])->whereKey($ticket->getKey())->firstOrFail();

        return (new TicketResource($fresh))->response()->setStatusCode($status);
    }
}
