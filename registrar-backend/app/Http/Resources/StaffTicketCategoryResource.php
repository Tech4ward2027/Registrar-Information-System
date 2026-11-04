<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A ticket category as the people who manage them see it: every category
 * (inactive too), with whether it is in use. Requesters get the smaller
 * TicketCategoryResource, active categories only.
 *
 * The integer id is returned because the management endpoints address a
 * category by it; it is only ever shown to staff holding tickets/Manage.
 *
 * @mixin \App\Models\TicketCategory
 */
class StaffTicketCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'code'          => $this->code,
            'name'          => $this->name,
            'description'   => $this->description,
            'priority'      => $this->priority->value,
            'is_active'     => $this->is_active,
            'sort_order'    => $this->sort_order,
            'tickets_count' => $this->whenCounted('tickets'),
        ];
    }
}
