<?php

namespace App\Services\Tickets\PublicSubmission;

use App\Models\TicketBlock;

/** Is this public submitter blocked? (Entries are managed by staff; see the ticket_blocks migration.) */
class TicketBlockList
{
    public function isBlocked(string $email, ?string $ip): bool
    {
        $email = mb_strtolower(trim($email));

        return TicketBlock::query()
            ->inForce()
            ->where(function ($q) use ($email, $ip) {
                $q->where(fn ($e) => $e->where('type', TicketBlock::TYPE_EMAIL)->where('value', $email));

                if ($ip !== null && $ip !== '') {
                    $q->orWhere(fn ($i) => $i->where('type', TicketBlock::TYPE_IP)->where('value', $ip));
                }
            })
            ->exists();
    }
}
