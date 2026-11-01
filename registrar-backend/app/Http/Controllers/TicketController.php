<?php

namespace App\Http\Controllers;

use App\Http\Requests\Ticket\ConfirmTicketRequest;
use App\Http\Requests\Ticket\ListMyTicketsRequest;
use App\Http\Requests\Ticket\ReplyTicketRequest;
use App\Http\Requests\Ticket\ReturnTicketRequest;
use App\Http\Requests\Ticket\ShowTicketRequest;
use App\Http\Requests\Ticket\StoreTicketRequest;
use App\Http\Resources\TicketResource;
use App\Http\Resources\TicketSummaryResource;
use App\Models\SystemUser;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Services\Tickets\TicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Support Ticketing — the requester's side (Student, Alumni, Undergrad
 * Requestor).
 *
 * A thin HTTP adapter, like the other controllers in this codebase: the Form
 * Request validates and authorizes (TicketPolicy), TicketService does the work,
 * a Resource shapes the answer. There is no business logic and no try/catch
 * here: a refusal by the ticket rules is thrown by the service and turned into
 * a 409 by TicketExceptionRenderer.
 *
 * Every action that changes a ticket answers with the whole, fresh ticket, so
 * the client never has to guess the new state or make a second call.
 *
 * Ownership is not a query filter here but a policy decision made before any
 * action; a ticket that is not the caller's answers 404 (see
 * RequesterTicketRequest). The list endpoint is the one place ownership IS a
 * query filter, since there is no single ticket to authorize.
 */
class TicketController extends Controller
{
    /** Relations the requester's detail view needs. */
    private const DETAIL = ['category', 'messages'];

    public function __construct(private readonly TicketService $tickets)
    {
    }

    // GET /tickets
    public function index(ListMyTicketsRequest $request): AnonymousResourceCollection
    {
        /** @var SystemUser $user */
        $user = $request->user();

        $perPage = (int) ($request->validated('per_page') ?? config('tickets.api.page_size'));

        $tickets = Ticket::query()
            ->forRequester($user->user_id)
            ->with('category')
            ->when($request->validated('status'), fn ($q, $status) => $q->where('status', $status))
            // id breaks ties so the cursor is stable when activity times collide.
            ->orderByDesc('last_activity_at')
            ->orderByDesc('id')
            ->cursorPaginate($perPage)
            ->withQueryString();

        return TicketSummaryResource::collection($tickets);
    }

    // POST /tickets
    public function store(StoreTicketRequest $request): JsonResponse
    {
        /** @var SystemUser $user */
        $user = $request->user();

        $category = TicketCategory::query()
            ->active()
            ->where('code', $request->validated('category_code'))
            ->firstOrFail();

        $ticket = $this->tickets->createForUser(
            $user,
            $category,
            $request->validated('subject'),
            $request->validated('description'),
            $request->ip(),
        );

        return $this->respond($ticket, 201);
    }

    // GET /tickets/{ticket}
    public function show(ShowTicketRequest $request, Ticket $ticket): TicketResource
    {
        return new TicketResource($this->fresh($ticket));
    }

    // POST /tickets/{ticket}/replies
    public function reply(ReplyTicketRequest $request, Ticket $ticket): JsonResponse
    {
        $this->tickets->replyAsRequester($ticket, $request->user(), $request->validated('body'));

        return $this->respond($ticket, 201);
    }

    // POST /tickets/{ticket}/confirm
    public function confirm(ConfirmTicketRequest $request, Ticket $ticket): JsonResponse
    {
        $this->tickets->confirm($ticket, $request->user());

        return $this->respond($ticket);
    }

    // POST /tickets/{ticket}/return
    public function returnToProgress(ReturnTicketRequest $request, Ticket $ticket): JsonResponse
    {
        $this->tickets->returnToProgress($ticket, $request->user(), $request->validated('reason'));

        return $this->respond($ticket);
    }

    private function respond(Ticket $ticket, int $status = 200): JsonResponse
    {
        return (new TicketResource($this->fresh($ticket)))->response()->setStatusCode($status);
    }

    /** Re-read with the detail relations: the service worked on its own locked copy. */
    private function fresh(Ticket $ticket): Ticket
    {
        return Ticket::query()->with(self::DETAIL)->whereKey($ticket->getKey())->firstOrFail();
    }
}
