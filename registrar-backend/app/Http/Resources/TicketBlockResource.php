<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A blocklist entry, for staff holding tickets/Manage only (it shows the
 * blocked address).
 *
 * @mixin \App\Models\TicketBlock
 */
class TicketBlockResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'type'       => $this->type,
            'value'      => $this->value,
            'reason'     => $this->reason,
            'created_at' => $this->created_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'in_force'   => $this->expires_at === null || $this->expires_at->isFuture(),
        ];
    }
}
