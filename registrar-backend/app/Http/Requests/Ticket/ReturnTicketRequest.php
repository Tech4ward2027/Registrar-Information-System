<?php

namespace App\Http\Requests\Ticket;

/** POST /tickets/{ticket}/return — "This isn't resolved", with an optional reason. */
class ReturnTicketRequest extends RequesterTicketRequest
{
    protected function ability(): string
    {
        return 'returnToProgress';
    }

    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:' . (int) config('tickets.content.message_max_length')],
        ];
    }
}
