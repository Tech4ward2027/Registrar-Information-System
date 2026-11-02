<?php

namespace App\Http\Resources;

use App\Enums\TicketSenderTypeEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One message, as the REQUESTER sees it.
 *
 * A staff member is never named to a requester: they see "Registrar Staff".
 * Who actually replied is recorded in the event log, which staff can see.
 *
 * @mixin \App\Models\TicketMessage
 */
class TicketMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->getKey(),
            'sender_type'  => $this->sender_type->value,
            'sender_label' => match ($this->sender_type) {
                TicketSenderTypeEnum::Requester => 'You',
                TicketSenderTypeEnum::Staff     => 'Registrar Staff',
                TicketSenderTypeEnum::System    => 'System',
            },
            'body'         => $this->body,
            'created_at'   => $this->created_at?->toIso8601String(),
        ];
    }
}
