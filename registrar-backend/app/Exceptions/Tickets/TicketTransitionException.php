<?php

namespace App\Exceptions\Tickets;

use App\Enums\TicketStatusEnum;

/**
 * The requested status change is not in TicketStatusEnum::allowedTransitions().
 */
class TicketTransitionException extends TicketException
{
    public function __construct(
        public readonly TicketStatusEnum $from,
        public readonly TicketStatusEnum $to,
    ) {
        parent::__construct("A ticket cannot move from {$from->label()} to {$to->label()}.");
    }
}
