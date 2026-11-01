<?php

namespace App\Http\Requests\Ticket\Admin;

use App\Http\Requests\Ticket\StaffTicketRequest;

/**
 * Flag one ticket as spam (needs View on it, like every staff action here, plus
 * Manage). block_email / block_ip also add the submitter to the blocklist.
 */
class FlagSpamRequest extends StaffTicketRequest
{
    protected function ability(): string
    {
        return 'manage';
    }

    public function rules(): array
    {
        return [
            'block_email'      => ['sometimes', 'boolean'],
            'block_ip'         => ['sometimes', 'boolean'],
            'reason'           => ['nullable', 'string', 'max:255'],
            'block_expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}
