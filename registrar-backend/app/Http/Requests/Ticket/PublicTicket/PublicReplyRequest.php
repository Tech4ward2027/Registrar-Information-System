<?php

namespace App\Http\Requests\Ticket\PublicTicket;

use App\Rules\MaxLinks;

/** POST /public/tickets/track/replies */
class PublicReplyRequest extends PublicTicketActionRequest
{
    public function rules(): array
    {
        return [
            'body' => [
                'required', 'string',
                'max:' . (int) config('tickets.content.message_max_length'),
                new MaxLinks((int) config('tickets.content.max_links_per_message')),
            ],
        ];
    }
}
