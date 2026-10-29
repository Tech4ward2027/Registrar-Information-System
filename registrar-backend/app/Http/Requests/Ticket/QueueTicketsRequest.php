<?php

namespace App\Http\Requests\Ticket;

use App\Enums\TicketPriorityEnum;
use App\Enums\TicketStatusEnum;
use App\Models\Ticket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /admin/tickets — the shared queue.
 *
 *   scope     unclaimed (default) | mine | all
 *   status    one status; omitted means the three active statuses
 *   priority  high | medium | low
 *   category  a category code (CAT-01 ...)
 *   q         ticket number prefix, or words from the subject
 *   per_page  capped by config('tickets.api.max_page_size')
 *   cursor    opaque, taken from the previous page's links.next
 */
class QueueTicketsRequest extends FormRequest
{
    public const SCOPES = ['unclaimed', 'mine', 'all'];

    public function authorize(): bool
    {
        return $this->user()?->can('viewQueue', Ticket::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'scope'    => ['sometimes', Rule::in(self::SCOPES)],
            'status'   => ['sometimes', Rule::enum(TicketStatusEnum::class)],
            'priority' => ['sometimes', Rule::enum(TicketPriorityEnum::class)],
            'category' => ['sometimes', 'string', 'max:20', Rule::exists('ticket_categories', 'code')],
            'q'        => ['sometimes', 'nullable', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:' . (int) config('tickets.api.max_page_size')],
        ];
    }
}
