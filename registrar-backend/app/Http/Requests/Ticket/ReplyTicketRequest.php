<?php

namespace App\Http\Requests\Ticket;

/**
 * POST /tickets/{ticket}/replies — the owner replies.
 *
 * Whether a reply is possible in the ticket's current status (not Closed, not
 * past the return limit) is TicketService's decision, answered as a 409; this
 * only validates the text.
 */
class ReplyTicketRequest extends RequesterTicketRequest
{
    protected function ability(): string
    {
        return 'replyAsRequester';
    }

    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:' . (int) config('tickets.content.message_max_length')],
        ];
    }
}
