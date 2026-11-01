<?php

namespace App\Http\Requests\Ticket\Admin;

use App\Models\Ticket;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Base for the ticket management requests that are not about one ticket
 * (categories, blocklist, reports, export); also used as-is by the list and
 * delete routes, which take no input. Needs the tickets "Manage"
 * permission, decided by TicketPolicy::manage; the route middleware is only
 * the coarse gate in front of this.
 */
class ManageTicketsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->can('manage', Ticket::class);
    }

    /** No input of its own: used directly by the list and delete routes. */
    public function rules(): array
    {
        return [];
    }
}
