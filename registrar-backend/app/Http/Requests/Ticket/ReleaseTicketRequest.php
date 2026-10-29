<?php

namespace App\Http\Requests\Ticket;

/**
 * POST /admin/tickets/{ticket}/release
 *
 * TicketPolicy::release allows the claimer, or staff who can Manage tickets.
 * TicketService::release checks the same rule again under the row lock.
 */
class ReleaseTicketRequest extends StaffTicketRequest
{
    protected function ability(): string
    {
        return 'release';
    }
}
