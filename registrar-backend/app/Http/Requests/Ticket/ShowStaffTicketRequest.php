<?php

namespace App\Http\Requests\Ticket;

/** GET /admin/tickets/{ticket} */
class ShowStaffTicketRequest extends StaffTicketRequest
{
    protected function ability(): string
    {
        return 'view';
    }
}
