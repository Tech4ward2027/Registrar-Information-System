<?php

namespace App\Services\Tickets;

use App\Enums\TicketStatusEnum;
use App\Exceptions\Tickets\TicketTransitionException;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The only place a ticket's status is changed.
 *
 * The rules themselves live in TicketStatusEnum::allowedTransitions(); this
 * class enforces them and applies the change, so no service can move a ticket
 * to a status the table forbids, and a new rule is a one-line enum edit.
 *
 * Concurrency: transition() must run inside a transaction on a ticket row the
 * caller has locked (TicketService does Ticket::lockForUpdate()). It refuses
 * to run otherwise, because a status check on an unlocked row is a
 * check-then-act race: two requests could both see Resolved and both close it.
 * Claiming is the one status-affecting write that does NOT go through
 * transition(): it is a single conditional UPDATE and needs no lock; see
 * TicketService::claim(). claimTarget() below is what keeps that path inside
 * these rules.
 */
final class TicketStateMachine
{
    public function canTransition(TicketStatusEnum $from, TicketStatusEnum $to): bool
    {
        return $from->canTransitionTo($to);
    }

    /**
     * @throws TicketTransitionException
     */
    public function assertCanTransition(TicketStatusEnum $from, TicketStatusEnum $to): void
    {
        if (! $this->canTransition($from, $to)) {
            throw new TicketTransitionException($from, $to);
        }
    }

    /**
     * Move $ticket to $to and persist, together with any other columns that
     * must change in the same write (resolution fields, counters, ...).
     *
     * @param  array<string,mixed>  $attributes
     *
     * @throws TicketTransitionException
     * @throws LogicException when not inside a transaction
     */
    public function transition(Ticket $ticket, TicketStatusEnum $to, array $attributes = []): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Ticket status changes must run inside a transaction on a locked ticket row.');
        }

        $this->assertCanTransition($ticket->status, $to);

        // forceFill: status and lifecycle columns are deliberately not
        // mass-assignable (see Ticket), and this is the one trusted writer.
        $ticket->forceFill(['status' => $to] + $attributes)->save();
    }

    /**
     * Statuses a ticket can be claimed in: the ones still being worked.
     *
     * @return list<TicketStatusEnum>
     */
    public function claimableStatuses(): array
    {
        return array_values(array_filter(
            TicketStatusEnum::cases(),
            static fn (TicketStatusEnum $s) => $s->isActive()
        ));
    }

    /**
     * The status a ticket holds after being claimed from $current. Claiming an
     * Open ticket starts work on it (Open -> In Progress); claiming one that is
     * already In Progress or Pending Client changes nothing about its status.
     *
     * Asserts the move is legal, so the conditional UPDATE in
     * TicketService::claim() cannot drift from the transition table.
     */
    public function claimTarget(TicketStatusEnum $current): TicketStatusEnum
    {
        $target = $current === TicketStatusEnum::Open ? TicketStatusEnum::InProgress : $current;

        if ($target !== $current) {
            $this->assertCanTransition($current, $target);
        }

        return $target;
    }
}
