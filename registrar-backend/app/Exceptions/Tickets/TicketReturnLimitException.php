<?php

namespace App\Exceptions\Tickets;

/**
 * The requester has already sent this ticket back from Resolved the maximum
 * number of times (config 'tickets.max_requester_returns').
 */
class TicketReturnLimitException extends TicketException
{
    public function __construct(public readonly int $limit)
    {
        parent::__construct("This ticket has already been returned {$limit} times. Please confirm the resolution or submit a new ticket.");
    }
}
