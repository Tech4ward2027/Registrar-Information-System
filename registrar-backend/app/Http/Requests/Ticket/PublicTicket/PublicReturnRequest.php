<?php

namespace App\Http\Requests\Ticket\PublicTicket;

use App\Rules\MaxLinks;

/** POST /public/tickets/track/return */
class PublicReturnRequest extends PublicTicketActionRequest
{
    public function rules(): array
    {
        return [
            'reason' => [
                'nullable', 'string',
                'max:' . (int) config('tickets.content.message_max_length'),
                new MaxLinks((int) config('tickets.content.max_links_per_message')),
            ],
        ];
    }
}
