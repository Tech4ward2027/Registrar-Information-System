<?php

namespace App\Http\Requests\Ticket;

/** POST /admin/tickets/{ticket}/resolve — the note is what the requester reads. */
class ResolveTicketRequest extends StaffTicketRequest
{
    protected function ability(): string
    {
        return 'resolve';
    }

    public function rules(): array
    {
        return [
            'resolution_note' => [
                'required', 'string',
                'min:' . (int) config('tickets.content.resolution_note_min_length'),
                'max:' . (int) config('tickets.content.message_max_length'),
            ],
        ];
    }
}
