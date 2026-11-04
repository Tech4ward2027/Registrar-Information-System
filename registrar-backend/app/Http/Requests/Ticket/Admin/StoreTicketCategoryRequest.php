<?php

namespace App\Http\Requests\Ticket\Admin;

use App\Enums\TicketPriorityEnum;
use Illuminate\Validation\Rule;

class StoreTicketCategoryRequest extends ManageTicketsRequest
{
    public function rules(): array
    {
        return [
            // CAT-01 style. Fixed for life: tickets, reports and the audit log refer to it.
            'code'        => ['required', 'string', 'regex:/^CAT-\d{2,4}$/', Rule::unique('ticket_categories', 'code')],
            'name'        => ['required', 'string', 'min:3', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'priority'    => ['required', 'string', Rule::in(array_column(TicketPriorityEnum::cases(), 'value'))],
            'is_active'   => ['sometimes', 'boolean'],
            'sort_order'  => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
