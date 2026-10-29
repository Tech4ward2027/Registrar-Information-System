<?php

namespace App\Http\Requests\Ticket;

/** POST /admin/tickets/{ticket}/claim */
class ClaimTicketRequest extends StaffTicketRequest
{
    protected function ability(): string
    {
        return 'claim';
    }
}
