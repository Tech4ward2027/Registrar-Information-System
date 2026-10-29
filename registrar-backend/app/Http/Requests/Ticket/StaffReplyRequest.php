<?php

namespace App\Http\Requests\Ticket;

/**
 * POST /admin/tickets/{ticket}/replies
 *
 * await_requester = true also moves the ticket to Pending Client (staff asked
 * a question and the clock waits on the requester).
 */
class StaffReplyRequest extends StaffTicketRequest
{
    protected function ability(): string
    {
        return 'replyAsStaff';
    }

    public function rules(): array
    {
        return [
            'body'            => ['required', 'string', 'max:' . (int) config('tickets.content.message_max_length')],
            'await_requester' => ['sometimes', 'boolean'],
        ];
    }
}
