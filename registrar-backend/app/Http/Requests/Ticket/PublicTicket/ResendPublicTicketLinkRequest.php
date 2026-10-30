<?php

namespace App\Http\Requests\Ticket\PublicTicket;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /public/tickets/resend-link
 *
 * Validation here is shape-only. It must never depend on whether the ticket
 * exists: the answer to a well-formed request is always the same message.
 */
class ResendPublicTicketLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email'         => ['required', 'string', 'email:rfc', 'max:254'],
            'ticket_number' => ['required', 'string', 'max:30'],
        ];
    }
}
