<?php

namespace App\Http\Resources;

use App\Enums\TicketStatusEnum;
use Illuminate\Http\Request;

/**
 * One ticket with its conversation, as the REQUESTER sees it.
 *
 * `actions` tells the UI what to offer, computed from the same facts the
 * service enforces (status and the return limit). It is a convenience, not a
 * permission: the endpoints re-check everything.
 *
 * @mixin \App\Models\Ticket
 */
class TicketResource extends TicketSummaryResource
{
    public function toArray(Request $request): array
    {
        $status      = $this->status;
        $limit       = (int) config('tickets.max_requester_returns');
        $returnsLeft = max(0, $limit - (int) $this->requester_returns);
        $resolved    = $status === TicketStatusEnum::Resolved;

        return array_merge(parent::toArray($request), [
            'requester_returns' => (int) $this->requester_returns,
            'returns_remaining' => $returnsLeft,
            'actions' => [
                // A reply during the Resolved window counts as "this isn't
                // resolved", so it is unavailable once the returns are used up.
                'can_reply'   => $status !== TicketStatusEnum::Closed && ! ($resolved && $returnsLeft === 0),
                'can_confirm' => $resolved,
                'can_return'  => $resolved && $returnsLeft > 0,
            ],
            'messages' => TicketMessageResource::collection($this->whenLoaded('messages')),
        ]);
    }
}
