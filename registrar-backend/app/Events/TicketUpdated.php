<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Support Ticketing — Phase 6. "Something changed on this ticket": the live
 * update that lets an open queue or ticket page refresh itself.
 *
 * It carries no message text and no names: only enough for a page to know WHICH
 * ticket changed and HOW, so it can re-fetch through the normal, authorised API.
 * (The same rule NotificationSent follows: nothing sensitive over the socket.)
 *
 * Channels (see routes/channels.php, authorised by TicketChannelAuthorizer):
 *   private tickets.staff        everyone who can see the queue
 *   private ticket.{publicId}    the requester, on their own ticket page; only
 *                                for a signed-in requester. A public submitter
 *                                has no account and so no channel: they get
 *                                email only.
 *
 * Dispatched after the change commits (TicketNotifier does that, and guards
 * it), so a page that re-fetches on receipt never sees the old state and a
 * rolled-back change never announces itself.
 *
 * Scalars only (no model), so there is nothing to re-load or leak when the
 * broadcast job runs on the `broadcasts` queue.
 */
class TicketUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly string $publicId,
        public readonly string $ticketNumber,
        public readonly string $status,
        public readonly bool   $claimed,
        public readonly string $action,
        public readonly bool   $hasAccountRequester,
        public readonly string $occurredAt,
    ) {
    }

    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel('tickets.staff')];

        if ($this->hasAccountRequester) {
            $channels[] = new PrivateChannel('ticket.' . $this->publicId);
        }

        return $channels;
    }

    public function broadcastQueue(): string
    {
        return 'broadcasts';
    }

    public function broadcastAs(): string
    {
        return 'TicketUpdated';
    }

    public function broadcastWith(): array
    {
        return [
            'public_id'     => $this->publicId,
            'ticket_number' => $this->ticketNumber,
            'status'        => $this->status,
            'claimed'       => $this->claimed,
            'action'        => $this->action,
            'occurred_at'   => $this->occurredAt,
        ];
    }
}
