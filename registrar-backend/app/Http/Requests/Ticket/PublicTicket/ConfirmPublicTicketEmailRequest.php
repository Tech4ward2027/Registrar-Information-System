<?php

namespace App\Http\Requests\Ticket\PublicTicket;

use Illuminate\Foundation\Http\FormRequest;

/** POST /public/tickets/confirm-email — the token from the emailed link. */
class ConfirmPublicTicketEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['token' => ['required', 'string', 'max:300']];
    }
}
