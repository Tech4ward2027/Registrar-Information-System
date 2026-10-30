<?php

namespace App\Exceptions\Tickets;

/**
 * The address already has the maximum number of tickets still being worked.
 * Not a rate limit (waiting does not help, resolving a ticket does), so it is a
 * 409 with its own code rather than a 429.
 */
class PublicOpenTicketLimitException extends TicketException
{
    public function __construct(public readonly int $limit)
    {
        parent::__construct("This email address already has {$limit} open tickets. Please wait until one is resolved before submitting another.");
    }
}
