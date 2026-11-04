<?php

namespace App\Http\Requests\Ticket;

use App\Models\Ticket;
use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Base for requester-side requests that act on ONE existing ticket
 * ({ticket} in the route, already resolved by public_id).
 *
 * Authorization is TicketPolicy: the caller must own the ticket. When they do
 * not, the answer is 404, not 403. A 403 would confirm that a ticket with that
 * id exists; the ids are unguessable ULIDs, but there is no reason to confirm
 * existence to someone who may not see it. (Staff-side requests answer 403
 * normally: staff are allowed to know the ticket exists.)
 */
abstract class RequesterTicketRequest extends FormRequest
{
    /** The TicketPolicy ability this request needs, e.g. 'view', 'confirm'. */
    abstract protected function ability(): string;

    public function authorize(): bool
    {
        $ticket = $this->route('ticket');

        return $ticket instanceof Ticket
            && $this->user() !== null
            && $this->user()->can($this->ability(), $ticket);
    }

    protected function failedAuthorization()
    {
        throw new NotFoundHttpException();
    }

    public function rules(): array
    {
        return [];
    }
}
