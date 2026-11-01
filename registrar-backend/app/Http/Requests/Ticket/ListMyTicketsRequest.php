<?php

namespace App\Http\Requests\Ticket;

use App\Enums\TicketStatusEnum;
use App\Models\Ticket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** GET /tickets — the requester's own tickets, newest activity first. */
class ListMyTicketsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewOwn', Ticket::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'status'   => ['sometimes', Rule::enum(TicketStatusEnum::class)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:' . (int) config('tickets.api.max_page_size')],
        ];
    }
}
