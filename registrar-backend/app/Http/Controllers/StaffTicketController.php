<?php

namespace App\Http\Controllers;

use App\Enums\TicketStatusEnum;
use App\Http\Requests\Ticket\ClaimTicketRequest;
use App\Http\Requests\Ticket\QueueTicketsRequest;
use App\Http\Requests\Ticket\ReleaseTicketRequest;
use App\Http\Requests\Ticket\ResolveTicketRequest;
use App\Http\Requests\Ticket\ShowStaffTicketRequest;
use App\Http\Requests\Ticket\StaffReplyRequest;
use App\Http\Resources\StaffTicketResource;
use App\Http\Resources\StaffTicketSummaryResource;
use App\Models\SystemUser;
use App\Models\Ticket;
use App\Services\Tickets\TicketService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Support Ticketing — the staff side: the shared queue and the actions on a
 * ticket (claim, release, reply, resolve).
 *
 * Same shape as TicketController: Form Request authorizes and validates,
 * TicketService acts, a Resource answers, and rule refusals become 409s in
 * TicketExceptionRenderer. Claiming is not an assignment: tickets sit in a
 * shared queue and staff claim from it. The atomic claim itself lives in
 * TicketService::claim().
 *
 * Route middleware ('module:tickets,...') is the coarse gate; the Form
 * Request's policy check is the real one and also covers per-ticket rules.
 */
class StaffTicketController extends Controller
{
    /** Relations the queue rows need (names are read from loaded relations only). */
    private const LIST = [
        'category',
        'claimedBy.adminProfile', 'claimedBy.studentProfile', 'claimedBy.alumniProfile',
        'requester.studentProfile', 'requester.alumniProfile', 'requester.undergradRequestorProfile',
    ];

    /** Relations the detail view needs, on top of LIST. */
    private const DETAIL = [
        ...self::LIST,
        'resolvedBy.adminProfile', 'resolvedBy.studentProfile', 'resolvedBy.alumniProfile',
        'messages.sender.adminProfile', 'messages.sender.studentProfile', 'messages.sender.alumniProfile',
        'events.actor.adminProfile', 'events.actor.studentProfile', 'events.actor.alumniProfile',
    ];

    public function __construct(private readonly TicketService $tickets)
    {
    }

    // GET /admin/tickets
    public function index(QueueTicketsRequest $request): AnonymousResourceCollection
    {
        /** @var SystemUser $user */
        $user = $request->user();
        $v    = $request->validated();

        $perPage = (int) ($v['per_page'] ?? config('tickets.api.page_size'));

        $query = Ticket::query()->with(self::LIST);

        // Status: one explicit status, else the three being worked. Resolved
        // and Closed are reachable only by asking for them, so the default
        // queue is the work to do.
        isset($v['status'])
            ? $query->where('status', $v['status'])
            : $query->whereIn('status', TicketStatusEnum::activeValues());

        match ($v['scope'] ?? 'unclaimed') {
            'mine'      => $query->where('claimed_by_user_id', $user->user_id),
            'all'       => null,
            default     => $query->whereNull('claimed_by_user_id'),
        };

        if (isset($v['priority'])) {
            $query->where('priority', $v['priority']);
        }

        if (isset($v['category'])) {
            $query->whereIn('category_id', fn ($sub) => $sub->select('id')->from('ticket_categories')->where('code', $v['category']));
        }

        if (isset($v['q']) && trim($v['q']) !== '') {
            $this->applySearch($query, trim($v['q']));
        }

        // Most urgent first, then oldest first (first come, first served within
        // a priority). id makes the order total, which cursor pagination needs.
        $tickets = $query
            ->orderByDesc('priority_weight')
            ->orderBy('created_at')
            ->orderBy('id')
            ->cursorPaginate($perPage)
            ->withQueryString();

        return StaffTicketSummaryResource::collection($tickets);
    }

    // GET /admin/tickets/{ticket}
    public function show(ShowStaffTicketRequest $request, Ticket $ticket): StaffTicketResource
    {
        return new StaffTicketResource($this->fresh($ticket));
    }

    // POST /admin/tickets/{ticket}/claim
    public function claim(ClaimTicketRequest $request, Ticket $ticket): JsonResponse
    {
        $this->tickets->claim($ticket, $request->user());

        return $this->respond($ticket);
    }

    // POST /admin/tickets/{ticket}/release
    public function release(ReleaseTicketRequest $request, Ticket $ticket): JsonResponse
    {
        $this->tickets->release($ticket, $request->user());

        return $this->respond($ticket);
    }

    // POST /admin/tickets/{ticket}/replies
    public function reply(StaffReplyRequest $request, Ticket $ticket): JsonResponse
    {
        $this->tickets->replyAsStaff(
            $ticket,
            $request->user(),
            $request->validated('body'),
            (bool) $request->validated('await_requester', false),
        );

        return $this->respond($ticket, 201);
    }

    // POST /admin/tickets/{ticket}/resolve
    public function resolve(ResolveTicketRequest $request, Ticket $ticket): JsonResponse
    {
        $this->tickets->resolve($ticket, $request->user(), $request->validated('resolution_note'));

        return $this->respond($ticket);
    }

    /**
     * A ticket number prefix ("TKT-2026-0012") matches the indexed number
     * column; anything else is a case-insensitive subject match.
     *
     * Wildcards in the user's text are escaped, so "100%" searches for "100%"
     * and not for "100 followed by anything". '!' is the escape character
     * because `ESCAPE '!'` means the same on MySQL and SQLite, where a
     * backslash would not.
     */
    private function applySearch(Builder $query, string $term): void
    {
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term);

        if (stripos($term, 'TKT-') === 0) {
            $query->whereRaw("ticket_number LIKE ? ESCAPE '!'", [strtoupper($escaped) . '%']);

            return;
        }

        $query->whereRaw("LOWER(subject) LIKE ? ESCAPE '!'", ['%' . mb_strtolower($escaped) . '%']);
    }

    private function respond(Ticket $ticket, int $status = 200): JsonResponse
    {
        return (new StaffTicketResource($this->fresh($ticket)))->response()->setStatusCode($status);
    }

    private function fresh(Ticket $ticket): Ticket
    {
        return Ticket::query()->with(self::DETAIL)->whereKey($ticket->getKey())->firstOrFail();
    }
}
