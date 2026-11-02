<?php

namespace App\Http\Requests\Ticket;

use App\Models\Ticket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /tickets — a signed-in requester files a ticket.
 *
 * Only a category CODE is accepted (CAT-01 ...). The integer id is not part of
 * the public contract, and priority is never accepted: it comes from the
 * category, so a client cannot talk its way into a higher priority.
 */
class StoreTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Ticket::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        // A subject is one line; collapse any line breaks and runs of spaces.
        if (is_string($this->input('subject'))) {
            $this->merge(['subject' => trim((string) preg_replace('/\s+/u', ' ', $this->input('subject')))]);
        }
    }

    public function rules(): array
    {
        $content = config('tickets.content');

        return [
            'category_code' => [
                'required', 'string',
                Rule::exists('ticket_categories', 'code')->where('is_active', true),
            ],
            'subject'     => ['required', 'string', 'max:' . (int) $content['subject_max_length']],
            'description' => [
                'required', 'string',
                'min:' . (int) $content['description_min_length'],
                'max:' . (int) $content['message_max_length'],
            ],
        ];
    }
}
