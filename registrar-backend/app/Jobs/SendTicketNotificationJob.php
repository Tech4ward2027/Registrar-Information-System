<?php

namespace App\Jobs;

use App\Enums\TicketNotificationKindEnum;
use App\Services\Tickets\Notifications\TicketNotificationSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Support Ticketing — Phase 6. Delivers one ticket notification (the bell entry
 * and/or the email) off the request thread.
 *
 * Dispatched by TicketNotifier after the originating change commits. It carries
 * only ids and small scalars and re-loads the ticket, so it always acts on
 * current data (a ticket anonymized or deleted in the meantime is simply
 * skipped) and never serialises a model.
 *
 * Retries are safe: $ref is fixed when the job is created, and the sender
 * records each completed delivery against it, so a retry after a partial
 * failure sends only what had not gone out yet.
 */
class SendTicketNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var int[] seconds between attempts */
    public array $backoff = [30, 120];

    /**
     * @param  array<string,scalar|null>  $context
     */
    public function __construct(
        public readonly int|string $ticketId,
        public readonly string $kind,
        public readonly string $ref,
        public readonly array $context = [],
    ) {
    }

    public function handle(TicketNotificationSender $sender): void
    {
        $kind = TicketNotificationKindEnum::tryFrom($this->kind);

        // A kind removed in a later release while jobs were still queued.
        if ($kind === null) {
            return;
        }

        $sender->deliver($this->ticketId, $kind, $this->ref, $this->context);
    }
}
