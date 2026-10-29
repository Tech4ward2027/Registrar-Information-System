<?php

namespace App\Http\Requests\Ticket;

use App\Models\Ticket;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Base for staff requests that act on ONE ticket.
 *
 * Every staff action here ANSWERS with the full ticket, so the caller must
 * also be allowed to view it: holding Reply without View must not become a way
 * to read a ticket. Hence the 'view' check alongside the action's own ability.
 * A staff member who may not see the ticket gets a normal 403.
 */
abstract class StaffTicketRequest extends FormRequest
{
    /** The TicketPolicy ability this action needs. */
    abstract protected function ability(): string;

    public function authorize(): bool
    {
        $ticket = $this->route('ticket');
        $user   = $this->user();

        return $ticket instanceof Ticket
            && $user !== null
            && $user->can('view', $ticket)
            && $user->can($this->ability(), $ticket);
    }

    public function rules(): array
    {
        return [];
    }
}
