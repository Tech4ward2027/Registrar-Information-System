<?php

namespace App\Http\Resources;

use App\Enums\TicketSenderTypeEnum;
use App\Support\Tickets\ActorName;
use Illuminate\Http\Request;

/**
 * One ticket with its conversation and activity log, as STAFF see it.
 *
 * `actions` is computed from TicketPolicy plus the ticket's status, so the UI
 * can show or grey out Claim / Release / Reply / Resolve. Convenience only:
 * every endpoint authorizes again.
 *
 * The event list exposes what happened and who did it, never the hash chain
 * fields (prev_hash, hash) or raw metadata.
 *
 * @mixin \App\Models\Ticket
 */
class StaffTicketResource extends StaffTicketSummaryResource
{
    public function toArray(Request $request): array
    {
        $user      = $request->user();
        $active    = $this->status->isActive();
        $isClaimed = $this->claimed_by_user_id !== null;

        $base = parent::toArray($request);
        // The submitter's email is useful to staff only for a public (no account) ticket.
        $base['requester']['email'] = $this->requester_user_id === null ? $this->requester_email : null;

        return array_merge($base, [
            'resolution_note'    => $this->resolution_note,
            'resolved_by'        => $this->resolved_by_user_id === null ? null
                : ActorName::staff($this->relationLoaded('resolvedBy') ? $this->resolvedBy : null),
            'first_responded_at' => $this->first_responded_at?->toIso8601String(),
            'claimed_at'         => $this->claimed_at?->toIso8601String(),
            'claim_expires_at'   => $this->claim_expires_at?->toIso8601String(),
            'auto_close_at'      => $this->auto_close_at?->toIso8601String(),
            'closed_at'          => $this->closed_at?->toIso8601String(),
            'close_reason'       => $this->close_reason,

            'actions' => [
                'can_claim'   => $active && ! $isClaimed && ($user?->can('claim', $this->resource) ?? false),
                'can_release' => $isClaimed && ($user?->can('release', $this->resource) ?? false),
                'can_reply'   => $active && ($user?->can('replyAsStaff', $this->resource) ?? false),
                'can_resolve' => $active && ($user?->can('resolve', $this->resource) ?? false),
            ],

            'messages' => $this->whenLoaded('messages', fn () => $this->messages->map(fn ($m) => [
                'id'          => $m->getKey(),
                'sender_type' => $m->sender_type->value,
                'sender_name' => match ($m->sender_type) {
                    TicketSenderTypeEnum::Staff     => ActorName::staff($m->relationLoaded('sender') ? $m->sender : null),
                    TicketSenderTypeEnum::Requester => ActorName::requester($this->resource),
                    TicketSenderTypeEnum::System    => 'System',
                },
                'body'        => $m->body,
                'created_at'  => $m->created_at?->toIso8601String(),
            ])->all()),

            'events' => $this->whenLoaded('events', fn () => $this->events->map(fn ($e) => [
                'type'       => $e->event_type,
                'actor_type' => $e->actor_type->value,
                'actor_name' => match ($e->actor_type) {
                    TicketSenderTypeEnum::Staff     => ActorName::staff($e->relationLoaded('actor') ? $e->actor : null),
                    TicketSenderTypeEnum::Requester => ActorName::requester($this->resource),
                    TicketSenderTypeEnum::System    => 'System',
                },
                'from_status' => $e->from_status?->value,
                'to_status'   => $e->to_status?->value,
                'created_at'  => $e->created_at?->toIso8601String(),
            ])->all()),
        ]);
    }
}
