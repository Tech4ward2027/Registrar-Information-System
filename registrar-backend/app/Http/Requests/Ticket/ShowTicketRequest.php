<?php

namespace App\Http\Requests\Ticket;

/** GET /tickets/{ticket} — the owner reads their ticket. */
class ShowTicketRequest extends RequesterTicketRequest
{
    protected function ability(): string
    {
        return 'view';
    }
}
