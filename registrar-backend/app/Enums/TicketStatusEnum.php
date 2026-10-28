<?php

namespace App\Enums;

/**
 * Support Ticketing — ticket lifecycle status.
 *
 * Backs a plain string column (tickets.status), never a database ENUM, like
 * RequestChannelEnum: adding a status later is a code change, not an ALTER
 * TABLE, and it behaves identically on MySQL and SQLite.
 *
 *   Open -> In Progress -> Pending Client -> Resolved -> Closed
 *
 * This enum is the single source of truth for which moves are legal.
 * TicketStateMachine (Phase 2) enforces allowedTransitions(); nothing else
 * may decide on its own that a transition is allowed.
 *
 * The one BACKWARD move is Resolved -> In Progress (the requester says
 * "This isn't resolved", or replies during the Resolved window). Limiting it
 * to config('tickets.max_requester_returns') per ticket is a counter check in
 * the service, because a transition table cannot express a count.
 * Pending Client -> In Progress is a forward move (the requester answered).
 * Closed is terminal: there is no reopening.
 */
enum TicketStatusEnum: string
{
    case Open          = 'open';
    case InProgress    = 'in_progress';
    case PendingClient = 'pending_client';
    case Resolved      = 'resolved';
    case Closed        = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open          => 'Open',
            self::InProgress    => 'In Progress',
            self::PendingClient => 'Pending Client',
            self::Resolved      => 'Resolved',
            self::Closed        => 'Closed',
        };
    }

    /**
     * Statuses this one may legally move to.
     *
     * Open -> Resolved and Pending Client -> Resolved exist because the
     * policy lets staff resolve straight away when no further information
     * is needed, and Resolve is its own permission, not tied to claiming.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Open          => [self::InProgress, self::Resolved],
            self::InProgress    => [self::PendingClient, self::Resolved],
            self::PendingClient => [self::InProgress, self::Resolved],
            self::Resolved      => [self::Closed, self::InProgress],
            self::Closed        => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    /**
     * Resolved -> In Progress: the only move that goes back. Used to apply
     * the per-ticket return limit and to label the event.
     */
    public function isReturnTo(self $next): bool
    {
        return $this === self::Resolved && $next === self::InProgress;
    }

    public function isTerminal(): bool
    {
        return $this === self::Closed;
    }

    /**
     * "Active" = still being worked: counts toward the open-tickets-per-email
     * cap and appears in the default staff queue. Resolved is excluded: it is
     * done unless the requester pushes back.
     */
    public function isActive(): bool
    {
        return in_array($this, [self::Open, self::InProgress, self::PendingClient], true);
    }

    /**
     * @return list<string>  backing values of the active statuses, for
     *                       whereIn() clauses that must not hardcode strings
     */
    public static function activeValues(): array
    {
        return array_map(
            static fn (self $s) => $s->value,
            array_values(array_filter(self::cases(), static fn (self $s) => $s->isActive()))
        );
    }
}
