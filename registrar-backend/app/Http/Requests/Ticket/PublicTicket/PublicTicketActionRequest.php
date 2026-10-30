<?php

namespace App\Http\Requests\Ticket\PublicTicket;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Base for the magic-link endpoints. Authorization is possession of a valid
 * token, already checked by the `ticket.token` middleware before this runs,
 * so there is nothing further to authorize here.
 */
class PublicTicketActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }
}
