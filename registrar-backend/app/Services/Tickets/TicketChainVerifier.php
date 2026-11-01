<?php

namespace App\Services\Tickets;

use App\Models\Ticket;
use App\Models\TicketEvent;
use Illuminate\Database\Eloquent\Builder;

/**
 * Support Ticketing — Phase 7. Verifies EVERY ticket's hash-chained event log in
 * one streaming pass; this is what `audit:verify` calls.
 *
 * A ticket's chain is broken if any of these is true (the same rules as
 * TicketEventRecorder::verifyChain(), which checks one ticket):
 *   - its sequence has a gap, a duplicate or is out of order (an event was
 *     deleted, inserted or moved);
 *   - an event's prev_hash is not the previous event's hash;
 *   - an event's hash is not what its own stored fields produce (it was edited);
 *   - an event holds a value that cannot be read back at all (also an edit).
 * Plus one rule a chain cannot express by itself: a ticket with NO events is
 * reported, because every ticket is born with a "created" event.
 *
 * The hash itself comes from TicketEvent::expectedHash(), the one place the
 * algorithm lives, so writer and verifier cannot disagree.
 *
 * Memory is bounded: events are read in keyset pages ordered by (ticket_id, seq)
 * on the unique index, and only the current ticket's running state is kept.
 * Only the FIRST break per ticket is reported, so one tampered row does not
 * produce a flood of follow-on failures.
 *
 * Known limit, shared with every hash chain: deleting the NEWEST events of a
 * ticket leaves a shorter, still valid chain. Detecting that would need the
 * head hash stored somewhere an attacker with database access cannot also edit.
 */
final class TicketChainVerifier
{
    /**
     * @return array{tickets:int, events:int, breaks:list<array{ticket_id:int, seq:?int, reason:string}>}
     */
    public function verifyAll(int $chunkSize = 1000): array
    {
        $chunkSize = max(1, $chunkSize);

        $breaks  = [];
        $events  = 0;
        $tickets = 0;

        // State of the ticket being walked.
        $current     = null;   // ticket id
        $expectedSeq = 1;
        $prevHash    = TicketEvent::GENESIS_HASH;
        $broken      = false;  // this ticket already reported

        $afterTicket = 0;
        $afterSeq    = 0;

        while (true) {
            $page = TicketEvent::query()
                ->where(function (Builder $q) use ($afterTicket, $afterSeq) {
                    $q->where('ticket_id', '>', $afterTicket)
                        ->orWhere(fn (Builder $q) => $q->where('ticket_id', $afterTicket)->where('seq', '>', $afterSeq));
                })
                ->orderBy('ticket_id')
                ->orderBy('seq')
                ->limit($chunkSize)
                ->get();

            if ($page->isEmpty()) {
                break;
            }

            foreach ($page as $event) {
                $events++;

                if ((int) $event->ticket_id !== $current) {
                    $current     = (int) $event->ticket_id;
                    $expectedSeq = 1;
                    $prevHash    = TicketEvent::GENESIS_HASH;
                    $broken      = false;
                    $tickets++;
                }

                if ($broken) {
                    continue;
                }

                $reason = $this->problem($event, $expectedSeq, $prevHash);

                if ($reason !== null) {
                    $breaks[] = ['ticket_id' => (int) $event->ticket_id, 'seq' => (int) $event->seq, 'reason' => $reason];
                    $broken   = true;
                    continue;
                }

                $prevHash = $event->hash;
                $expectedSeq++;
            }

            $last        = $page->last();
            $afterTicket = (int) $last->ticket_id;
            $afterSeq    = (int) $last->seq;
        }

        foreach ($this->ticketsWithoutEvents($chunkSize) as $ticketId) {
            $breaks[] = ['ticket_id' => $ticketId, 'seq' => null, 'reason' => 'the ticket has no event log at all'];
        }

        return ['tickets' => $tickets, 'events' => $events, 'breaks' => $breaks];
    }

    private function problem(TicketEvent $event, int $expectedSeq, string $prevHash): ?string
    {
        if ($event->seq !== $expectedSeq) {
            return "sequence gap or reorder: expected seq {$expectedSeq}, found {$event->seq}";
        }

        if ($event->prev_hash !== $prevHash) {
            return 'prev_hash does not match the previous event (an event was removed, inserted or altered)';
        }

        try {
            $expected = $event->expectedHash();
        } catch (\Throwable) {
            return 'the event holds a value that cannot be read back (it was altered after being written)';
        }

        if ($event->hash !== $expected) {
            return 'hash does not match the event contents (the event was altered after being written)';
        }

        return null;
    }

    /**
     * @return \Generator<int,int>  ids of tickets that have no events
     */
    private function ticketsWithoutEvents(int $chunkSize): \Generator
    {
        $afterId = 0;

        while (true) {
            $ids = Ticket::query()
                ->where('id', '>', $afterId)
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('ticket_events')->whereColumn('ticket_events.ticket_id', 'tickets.id'))
                ->orderBy('id')
                ->limit($chunkSize)
                ->pluck('id');

            if ($ids->isEmpty()) {
                return;
            }

            foreach ($ids as $id) {
                yield (int) $id;
            }

            $afterId = (int) $ids->last();
        }
    }
}
