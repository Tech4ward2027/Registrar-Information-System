<?php

namespace App\Enums;

/**
 * Support Ticketing — ticket priority.
 *
 * Priority is never chosen by the requester or by staff: it comes from the
 * ticket's category (ticket_categories.priority) and is copied onto the
 * ticket when it is created, so reclassifying a category later does not
 * silently rewrite the SLA of tickets already in flight.
 */
enum TicketPriorityEnum: string
{
    case High   = 'high';
    case Medium = 'medium';
    case Low    = 'low';

    public function label(): string
    {
        return match ($this) {
            self::High   => 'High',
            self::Medium => 'Medium',
            self::Low    => 'Low',
        };
    }

    /**
     * Numeric weight for ordering the queue (higher = more urgent). Priority
     * is stored as a string, so ORDER BY on the column would sort
     * alphabetically (high, low, medium) and be wrong.
     */
    public function weight(): int
    {
        return match ($this) {
            self::High   => 3,
            self::Medium => 2,
            self::Low    => 1,
        };
    }
}
