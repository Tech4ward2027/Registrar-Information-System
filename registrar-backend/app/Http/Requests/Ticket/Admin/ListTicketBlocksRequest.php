<?php

namespace App\Http\Requests\Ticket\Admin;

use Illuminate\Validation\Rule;

class ListTicketBlocksRequest extends ManageTicketsRequest
{
    public function rules(): array
    {
        return [
            'type'     => ['sometimes', Rule::in(['email', 'ip'])],
            'state'    => ['sometimes', Rule::in(['active', 'expired', 'all'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:' . (int) config('tickets.api.max_page_size')],
        ];
    }
}
