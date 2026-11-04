<?php

namespace App\Http\Controllers;

use App\Http\Requests\Ticket\Admin\FlagSpamRequest;
use App\Http\Requests\Ticket\Admin\ListTicketBlocksRequest;
use App\Http\Requests\Ticket\Admin\ManageTicketsRequest;
use App\Http\Requests\Ticket\Admin\StoreTicketBlockRequest;
use App\Http\Resources\StaffTicketResource;
use App\Http\Resources\TicketBlockResource;
use App\Models\Ticket;
use App\Models\TicketBlock;
use App\Services\Tickets\TicketAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

/**
 * Support Ticketing — the spam blocklist and flagging spam (tickets/Manage).
 * Phase 4 enforces the blocklist on the public form; this is how staff edit it.
 */
class StaffTicketBlockController extends Controller
{
    public function __construct(private readonly TicketAdminService $admin)
    {
    }

    // GET /admin/tickets/blocks
    public function index(ListTicketBlocksRequest $request): AnonymousResourceCollection
    {
        $v     = $request->validated();
        $query = TicketBlock::query();

        isset($v['type']) && $query->where('type', $v['type']);

        match ($v['state'] ?? 'active') {
            'active'  => $query->inForce(),
            'expired' => $query->whereNotNull('expires_at')->where('expires_at', '<=', now()),
            default   => null,
        };

        return TicketBlockResource::collection(
            $query->orderByDesc('id')
                ->cursorPaginate((int) ($v['per_page'] ?? config('tickets.api.page_size')))
                ->withQueryString()
        );
    }

    // POST /admin/tickets/blocks — adding a listed value renews it (200); a new one is 201
    public function store(StoreTicketBlockRequest $request): JsonResponse
    {
        $v        = $request->validated();
        $existing = TicketBlock::query()->where(['type' => $v['type'], 'value' => $v['value']])->exists();

        $block = $this->admin->addBlock(
            $request, $request->user(), $v['type'], $v['value'], $v['reason'] ?? null,
            isset($v['expires_at']) ? Carbon::parse($v['expires_at']) : null,
        );

        return (new TicketBlockResource($block))->response()->setStatusCode($existing ? 200 : 201);
    }

    // DELETE /admin/tickets/blocks/{block}
    //
    // {block} is taken as a plain id and looked up AFTER the Form Request has authorized the
    // caller. With implicit model binding the lookup ran first, so anyone (even a student)
    // got 404 for a missing id but 403 for an existing one, which revealed which ids exist.
    public function destroy(ManageTicketsRequest $request, int $block): JsonResponse
    {
        $block = TicketBlock::query()->findOrFail($block);

        $this->admin->removeBlock($request, $request->user(), $block);

        return response()->json(['message' => 'Block removed.']);
    }

    // POST /admin/tickets/{ticket}/spam
    public function flagSpam(FlagSpamRequest $request, Ticket $ticket): StaffTicketResource
    {
        $v = $request->validated();

        $types = array_values(array_filter([
            ! empty($v['block_email']) ? TicketBlock::TYPE_EMAIL : null,
            ! empty($v['block_ip']) ? TicketBlock::TYPE_IP : null,
        ]));

        $flagged = $this->admin->flagSpam(
            $request, $request->user(), $ticket, $types, $v['reason'] ?? null,
            isset($v['block_expires_at']) ? Carbon::parse($v['block_expires_at']) : null,
        );

        return new StaffTicketResource($flagged->load(StaffTicketController::DETAIL));
    }
}