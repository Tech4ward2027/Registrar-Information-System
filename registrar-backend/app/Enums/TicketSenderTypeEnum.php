<?php

namespace App\Enums;

/**
 * Support Ticketing — who authored a message or performed an event.
 *
 * Requester covers both signed-in users and verified public submitters; the
 * two are told apart by whether the ticket has a requester_user_id. System is
 * for automatic actions (auto-close, claim timeout, SLA flags), which have no
 * human actor.
 */
enum TicketSenderTypeEnum: string
{
    case Requester = 'requester';
    case Staff     = 'staff';
    case System    = 'system';

    public function label(): string
    {
        return match ($this) {
            self::Requester => 'Requester',
            self::Staff     => 'Staff',
            self::System    => 'System',
        };
    }
}
