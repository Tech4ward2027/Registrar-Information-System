<?php

namespace App\Http\Requests\Ticket\Admin;

use App\Models\TicketBlock;
use App\Services\Tickets\TicketAdminService;
use Illuminate\Validation\Rule;

class StoreTicketBlockRequest extends ManageTicketsRequest
{
    /** Store the value the way the blocklist lookup compares it. */
    protected function prepareForValidation(): void
    {
        $type  = $this->input('type');
        $value = $this->input('value');

        if (is_string($type) && is_string($value)) {
            $this->merge(['value' => TicketAdminService::normalise($type, $value)]);
        }
    }

    public function rules(): array
    {
        $type = $this->input('type');

        return [
            'type'       => ['required', Rule::in([TicketBlock::TYPE_EMAIL, TicketBlock::TYPE_IP])],
            'value'      => ['required', 'string', 'max:254', $type === TicketBlock::TYPE_IP ? 'ip' : 'email:rfc'],
            'reason'     => ['nullable', 'string', 'max:255'],
            // Null/absent = until lifted by hand.
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}
