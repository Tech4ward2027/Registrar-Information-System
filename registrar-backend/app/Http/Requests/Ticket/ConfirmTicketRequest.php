<?php

namespace App\Http\Requests\Ticket;

/** POST /tickets/{ticket}/confirm — the owner confirms the resolution (Resolved -> Closed). */
class ConfirmTicketRequest extends RequesterTicketRequest
{
    protected function ability(): string
    {
        return 'confirm';
    }
}
