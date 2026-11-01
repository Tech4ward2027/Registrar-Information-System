<?php

namespace App\Support\Tickets;

use App\Models\SystemUser;
use App\Models\Ticket;
use Illuminate\Support\Facades\Gate;

/**
 * Support Ticketing — Phase 6. Who may listen to a ticket's live updates.
 *
 * routes/channels.php is skipped entirely under APP_ENV=testing (the file's own
 * convention), so the rules live here where they can be tested, and the
 * channel callbacks are one-line calls to this class. Everything is delegated
 * to TicketPolicy: the same rules that guard the API guard the socket.
 */
final class TicketChannelAuthorizer
{
    /** private tickets.staff: anyone who can see the queue. */
    public static function staffQueue(SystemUser $user): bool
    {
        return ($user->isAdmin() || $user->isSuperAdmin())
            && Gate::forUser($user)->allows('viewQueue', Ticket::class);
    }

    /** private ticket.{publicId}: the ticket's owner, or staff who can see the queue. */
    public static function ticket(SystemUser $user, string $publicId): bool
    {
        $ticket = Ticket::query()->where('public_id', strtolower($publicId))->first();

        return $ticket !== null && Gate::forUser($user)->allows('view', $ticket);
    }
}
