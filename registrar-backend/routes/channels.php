<?php

use Illuminate\Support\Facades\Broadcast;
use App\Models\SystemUser;

if (app()->environment('testing')) {
    return;
}

/*
|--------------------------------------------------------------------------
| PRIVATE CHANNEL: notifications.{userId}
|--------------------------------------------------------------------------
| Personal notification channel for each user.
| A user can ONLY subscribe to their own channel — we verify
| the authenticated user's user_id matches the channel's {userId}.
|--------------------------------------------------------------------------
*/
Broadcast::channel('notifications.{userId}', function (SystemUser $user, int $userId) {
    return (int) $user->user_id === (int) $userId;
});

// admin.notifications channel removed — NotificationSent::broadcastOn()
// only broadcasts to the personal notifications.{userId} channel.
// Each admin receives their own copy via SendBulkNotificationJob.
// Re-add this channel if you ever introduce a true shared admin broadcast.


/*
|--------------------------------------------------------------------------
| PRIVATE CHANNEL: admin.notifications
|--------------------------------------------------------------------------
| Shared channel for all admin users.
| Kept here as a defensive definition — channel auth will not 403
| if the frontend accidentally subscribes. Only admin and super_admin
| roles are authorised to join.
| NotificationSent currently broadcasts to personal channels only;
| this channel is available for future shared-admin broadcasts.
|--------------------------------------------------------------------------
*/
Broadcast::channel('admin.notifications', function (SystemUser $user) {
    return $user->isAdmin() || $user->isSuperAdmin();
});

/*
|--------------------------------------------------------------------------
| PRIVATE CHANNELS: tickets.staff and ticket.{publicId}  (Support Ticketing)
|--------------------------------------------------------------------------
| Live "something changed" pings (App\Events\TicketUpdated). Authorisation is
| delegated to TicketChannelAuthorizer, which asks TicketPolicy: staff who can
| see the queue may join tickets.staff; a ticket's owner (or such staff) may join
| ticket.{publicId}. Public submitters have no account and no channel.
|--------------------------------------------------------------------------
*/
Broadcast::channel('tickets.staff', fn (SystemUser $user) => \App\Support\Tickets\TicketChannelAuthorizer::staffQueue($user));

Broadcast::channel('ticket.{publicId}', fn (SystemUser $user, string $publicId) => \App\Support\Tickets\TicketChannelAuthorizer::ticket($user, $publicId));