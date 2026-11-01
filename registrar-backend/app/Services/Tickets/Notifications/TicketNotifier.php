<?php

namespace App\Services\Tickets\Notifications;

use App\Enums\TicketNotificationKindEnum;
use App\Events\TicketUpdated;
use App\Jobs\SendTicketNotificationJob;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Support Ticketing — Phase 6. The one door the ticket code uses to announce
 * "this happened": it queues the notifications and the live update.
 *
 * Called by TicketService (and the SLA monitor) from INSIDE their
 * transactions, right after the change is saved. That is safe and deliberate:
 *   - the job and the event are both dispatched AFTER COMMIT (DB::afterCommit),
 *     so a change that rolls back sends nothing and announces nothing, and a
 *     recipient who opens the link never finds the old state. With no
 *     transaction open the callback simply runs at once;
 *   - nothing here reads the database, so calling it mid-transaction cannot
 *     disturb the SQLite/MySQL locking the claim path is careful about.
 *
 * Fire-and-forget by design: a problem in the notification layer is logged and
 * swallowed, never thrown. Filing, claiming or resolving a ticket must not fail
 * because a queue or a broadcaster is down. The guard sits INSIDE the
 * after-commit callback on purpose: that is the moment the dispatch happens, and
 * an exception there would otherwise surface after the data was committed.
 *
 * Exactly-once: each notify() mints a fresh $ref and hands it to the job. The
 * sender remembers (ref, recipient, channel) once delivered, so a retried or
 * duplicated job cannot send the same bell entry or email twice.
 */
final class TicketNotifier
{
    /**
     * Notify the people this kind is for, and push a live update.
     *
     * @param  array<string,scalar|null>  $context  small scalars only (ids, ISO dates);
     *                                               it is serialised onto the queue
     */
    public function notify(Ticket $ticket, TicketNotificationKindEnum $kind, array $context = []): void
    {
        $ticketId = $ticket->getKey();
        $ref      = (string) Str::uuid();

        $this->afterCommit(
            fn () => SendTicketNotificationJob::dispatch($ticketId, $kind->value, $ref, $context),
            'queue a notification',
            ['ticket_id' => $ticketId, 'kind' => $kind->value],
        );

        $this->liveUpdate($ticket, $kind->value);
    }

    /**
     * Push a live update with no notification (a claim, a release, a closure
     * the requester caused themselves...). $action is a short label the front
     * end can switch on.
     */
    public function liveUpdate(Ticket $ticket, string $action): void
    {
        if (! config('tickets.notifications.live_updates_enabled')) {
            return;
        }

        // Built now, from the state just saved, so it describes this change.
        $event = new TicketUpdated(
            publicId:            $ticket->public_id,
            ticketNumber:        $ticket->ticket_number,
            status:              $ticket->status->value,
            claimed:             $ticket->claimed_by_user_id !== null,
            action:              $action,
            hasAccountRequester: $ticket->requester_user_id !== null,
            occurredAt:          now()->toIso8601String(),
        );

        $this->afterCommit(
            fn () => event($event),
            'push a live update',
            ['ticket_id' => $ticket->getKey(), 'action' => $action],
        );
    }

    /** Run $callback once the surrounding transaction commits (at once if there is none), never letting it throw. */
    private function afterCommit(callable $callback, string $what, array $context): void
    {
        DB::afterCommit(function () use ($callback, $what, $context) {
            try {
                $callback();
            } catch (Throwable $e) {
                Log::error("tickets.notifications: could not {$what}.", $context + ['error' => $e->getMessage()]);
            }
        });
    }
}
