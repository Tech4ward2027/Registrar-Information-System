<?php

namespace App\Services\Tickets;

use App\Enums\TicketSenderTypeEnum;
use App\Enums\TicketStatusEnum;
use App\Models\Ticket;
use App\Models\TicketEvent;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Writes and verifies a ticket's hash-chained event log.
 *
 * Every event stores a per-ticket sequence number, the previous event's hash
 * and its own hash (see TicketEvent for the algorithm, and the create_ticket_
 * events migration for why the chain is per ticket). Editing, deleting,
 * inserting or reordering any row afterwards makes verifyChain() fail.
 *
 * record() must run inside a transaction. It takes the ticket row lock itself
 * (idempotent when the caller already holds it), so two writers on the same
 * ticket can never read the same "last event" and fork the chain; the
 * UNIQUE (ticket_id, seq) index is the backstop if that were ever bypassed.
 */
final class TicketEventRecorder
{
    /**
     * @param  array<string,mixed>  $metadata  JSON-safe context for the event
     *
     * @throws LogicException when not inside a transaction
     */
    public function record(
        Ticket $ticket,
        string $type,
        TicketSenderTypeEnum $actorType,
        ?int $actorUserId = null,
        ?TicketStatusEnum $from = null,
        ?TicketStatusEnum $to = null,
        array $metadata = [],
    ): TicketEvent {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Ticket events must be recorded inside a transaction.');
        }

        if (! in_array($type, TicketEvent::TYPES, true)) {
            throw new LogicException("Unknown ticket event type '{$type}'.");
        }

        // Serialise writers on this ticket (a no-op re-lock if already held).
        DB::table('tickets')->where('id', $ticket->getKey())->lockForUpdate()->value('id');

        $last = TicketEvent::query()
            ->where('ticket_id', $ticket->getKey())
            ->orderByDesc('seq')
            ->first(['seq', 'hash']);

        $fields = [
            'ticket_id'     => $ticket->getKey(),
            'seq'           => ($last?->seq ?? 0) + 1,
            'event_type'    => $type,
            'actor_type'    => $actorType,
            'actor_user_id' => $actorUserId,
            'from_status'   => $from,
            'to_status'     => $to,
            'metadata'      => $metadata === [] ? null : $metadata,
            // Whole seconds: the column keeps no fraction, and the hash must
            // be identical before insert and after a read back.
            'created_at'    => now()->startOfSecond(),
        ];

        $prevHash = $last?->hash ?? TicketEvent::GENESIS_HASH;

        return TicketEvent::create($fields + [
            'prev_hash' => $prevHash,
            'hash'      => TicketEvent::computeHash($prevHash, TicketEvent::hashPayload($fields)),
        ]);
    }

    /**
     * Walk the ticket's whole chain and report the first thing wrong with it.
     *
     * @return array{ok:bool, events:int, broken_at_seq:?int, reason:?string}
     */
    public function verifyChain(Ticket $ticket): array
    {
        $expectedSeq = 1;
        $prevHash    = TicketEvent::GENESIS_HASH;
        $count       = 0;

        $fail = static fn (int $count, int $seq, string $reason) => [
            'ok' => false, 'events' => $count, 'broken_at_seq' => $seq, 'reason' => $reason,
        ];

        foreach (TicketEvent::query()->where('ticket_id', $ticket->getKey())->orderBy('seq')->cursor() as $event) {
            $count++;

            if ($event->seq !== $expectedSeq) {
                return $fail($count, $expectedSeq, 'sequence gap or reorder');
            }
            if ($event->prev_hash !== $prevHash) {
                return $fail($count, $event->seq, 'prev_hash does not match the previous event');
            }
            if ($event->hash !== $event->expectedHash()) {
                return $fail($count, $event->seq, 'hash does not match the event contents');
            }

            $prevHash = $event->hash;
            $expectedSeq++;
        }

        return ['ok' => true, 'events' => $count, 'broken_at_seq' => null, 'reason' => null];
    }
}
