<?php

namespace App\Support\Tickets;

use App\Models\SystemUser;
use App\Models\Ticket;

/**
 * Display names for the people on a ticket.
 *
 * Reads only relations that are ALREADY LOADED. A name lookup inside a
 * Resource must never trigger a query per row, so the controllers eager-load
 * what they need, and anything not loaded degrades to a neutral label instead
 * of silently becoming an N+1.
 *
 * Staff: the label shown when no name can be resolved. Requesters are never
 * shown a staff member's name (see the requester Resources), only this label.
 */
final class ActorName
{
    public const STAFF_FALLBACK     = 'Registrar Staff';
    public const REQUESTER_FALLBACK = 'Requester';
    public const ANONYMIZED         = 'Anonymized requester';

    /** A staff member: Admin profile first, then the identity under a student-staff grant. */
    public static function staff(?SystemUser $user): string
    {
        return self::fromProfiles($user, ['adminProfile', 'studentProfile', 'alumniProfile']) ?? self::STAFF_FALLBACK;
    }

    /** The person who filed the ticket, as staff see them. */
    public static function requester(Ticket $ticket): string
    {
        if ($ticket->anonymized_at !== null) {
            return self::ANONYMIZED;
        }

        if ($ticket->requester_user_id === null) {
            return $ticket->requester_name ?: self::REQUESTER_FALLBACK;
        }

        return self::fromProfiles(
            $ticket->relationLoaded('requester') ? $ticket->requester : null,
            ['studentProfile', 'alumniProfile', 'undergradRequestorProfile', 'adminProfile'],
        ) ?? self::REQUESTER_FALLBACK;
    }

    /**
     * @param  list<string>  $relations  tried in order
     */
    private static function fromProfiles(?SystemUser $user, array $relations): ?string
    {
        if ($user === null) {
            return null;
        }

        foreach ($relations as $relation) {
            if (! $user->relationLoaded($relation)) {
                continue;
            }

            $profile = $user->getRelation($relation);
            if ($profile === null) {
                continue;
            }

            $name = trim(implode(' ', array_filter([$profile->first_name ?? null, $profile->last_name ?? null])));
            if ($name !== '') {
                return $name;
            }
        }

        return null;
    }
}
