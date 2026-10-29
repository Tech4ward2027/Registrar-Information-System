<?php

namespace App\Http\Resources;

use App\Support\Tickets\ActorName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A ticket as STAFF see it in the queue.
 *
 * Names come from relations the controller eager-loads (see ActorName); a
 * relation that was not loaded degrades to a neutral label rather than firing
 * a query per row. The submitter's IP is never exposed.
 *
 * @mixin \App\Models\Ticket
 */
class StaffTicketSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $claimedBy = $this->claimed_by_user_id;

        return [
            'id'            => $this->public_id,
            'ticket_number' => $this->ticket_number,
            'subject'       => $this->subject,
            'status'        => $this->status->value,
            'status_label'  => $this->status->label(),
            'priority'      => $this->priority->value,
            'category'      => new TicketCategoryResource($this->whenLoaded('category')),

            'requester' => [
                'name' => ActorName::requester($this->resource),
                'type' => $this->requester_user_id !== null ? 'account' : 'public',
            ],

            'is_claimed' => $claimedBy !== null,
            'claimed_by' => $claimedBy === null ? null : [
                'id'    => (int) $claimedBy,
                'name'  => ActorName::staff($this->relationLoaded('claimedBy') ? $this->claimedBy : null),
                'is_me' => (int) $claimedBy === (int) $request->user()?->user_id,
            ],

            'awaiting_first_response' => $this->first_responded_at === null,
            'requester_returns'       => (int) $this->requester_returns,

            'created_at'       => $this->created_at?->toIso8601String(),
            'last_activity_at' => $this->last_activity_at?->toIso8601String(),
            'resolved_at'      => $this->resolved_at?->toIso8601String(),
        ];
    }
}
