<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public-safe view of a ticket category. Exposes the business `code`
 * (CAT-01 ...) as the identifier; the internal integer id is not part of the
 * contract.
 *
 * @mixin \App\Models\TicketCategory
 */
class TicketCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'code'        => $this->code,
            'name'        => $this->name,
            'description' => $this->description,
            'priority'    => $this->priority->value,
        ];
    }
}
