<?php

namespace App\Exceptions\Tickets;

use App\Enums\TicketStatusEnum;

/**
 * The action makes no sense in the ticket's current status, and is not simply
 * an illegal transition (for example replying to a Closed ticket, or
 * "This isn't resolved" on a ticket that is not Resolved).
 */
class TicketActionNotAllowedException extends TicketException
{
    public function __construct(
        public readonly string $action,
        public readonly TicketStatusEnum $status,
    ) {
        parent::__construct("Cannot {$action} a ticket that is {$status->label()}.");
    }
}
