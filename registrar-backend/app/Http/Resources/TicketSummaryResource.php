<?php

namespace App\Http\Resources;

use App\Enums\TicketStatusEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A ticket as a REQUESTER sees it in a list.
 *
 * Deliberately absent: the integer id (the public_id is the identifier), the
 * submitter's IP, who claimed it, who resolved it, and anything internal to
 * the staff queue. Requesters see their own conversation and status, nothing
 * about how the team is organised.
 *
 * @mixin \App\Models\Ticket
 */
class TicketSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $status = $this->status;

        return [
            'id'            => $this->public_id,
            'ticket_number' => $this->ticket_number,
            'subject'       => $this->subject,
            'status'        => $status->value,
            'status_label'  => $status->label(),
            'priority'      => $this->priority->value,
            'category'      => new TicketCategoryResource($this->whenLoaded('category')),

            // What the requester is asked to read: only once there is one.
            'resolution_note' => $this->when(
                in_array($status, [TicketStatusEnum::Resolved, TicketStatusEnum::Closed], true),
                $this->resolution_note,
            ),
            'resolved_at'   => $this->resolved_at?->toIso8601String(),
            // Only meaningful while the auto-close window is running.
            'auto_close_at' => $this->when($status === TicketStatusEnum::Resolved, fn () => $this->auto_close_at?->toIso8601String()),
            'closed_at'     => $this->closed_at?->toIso8601String(),
            'close_reason'  => $this->close_reason,

            'created_at'       => $this->created_at?->toIso8601String(),
            'last_activity_at' => $this->last_activity_at?->toIso8601String(),
        ];
    }
}
