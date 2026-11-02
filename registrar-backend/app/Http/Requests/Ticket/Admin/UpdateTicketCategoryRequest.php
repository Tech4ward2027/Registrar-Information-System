<?php

namespace App\Http\Requests\Ticket\Admin;

use App\Enums\TicketPriorityEnum;
use Illuminate\Validation\Rule;

/** The code is deliberately not editable. */
class UpdateTicketCategoryRequest extends ManageTicketsRequest
{
    public function rules(): array
    {
        return [
            'name'        => ['sometimes', 'required', 'string', 'min:3', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'priority'    => ['sometimes', 'required', 'string', Rule::in(array_column(TicketPriorityEnum::cases(), 'value'))],
            'is_active'   => ['sometimes', 'required', 'boolean'],
            'sort_order'  => ['sometimes', 'required', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
