<?php

namespace App\Events;

use App\Models\Notification;
use App\Models\SystemUser;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/*
|--------------------------------------------------------------------------
| NotificationSent Event
|--------------------------------------------------------------------------
| This is the event that gets fired over WebSockets via Reverb.
|
|   Tells Laravel this event should be sent over the broadcast driver
|   (Reverb in our case) in addition to being fired internally.
|   Without this interface, the event only exists in PHP memory.
|
| broadcastOn():
|   Returns which WebSocket channel(s) to broadcast to.
|   PrivateChannel means the channel requires authentication
|   (our channels.php closure runs to authorize it).
|
| broadcastWith():
|   Returns the JSON payload sent to the frontend.
|   Keep this lean — only what the frontend needs to display
|   the notification without making another API call.
|
| broadcastAs():
|   The event name the frontend listens for.
|   e.g. Echo.private('notifications.5').listen('NotificationSent', ...)
|--------------------------------------------------------------------------
*/

// ShouldBroadcast (queued) is used instead of ShouldBroadcastNow (synchronous)
// so the WebSocket push is dispatched AFTER the DB transaction commits.
// With ShouldBroadcastNow the push fires inside the transaction, meaning the
// frontend can receive a real-time event for a row not yet visible to other
// DB connections — a silent race condition on every notification send.
class NotificationSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Notification $notification,
        public readonly SystemUser   $recipient,
        // Pass title and trigger_event as plain strings rather than reading
        // them from the relation inside broadcastWith().
        // SerializesModels strips loaded relations before queuing, so
        // $notification->type would be null (or trigger a lazy-load) when
        // BroadcastEvent runs. Scalars survive serialization untouched.
        public readonly string       $typeTitle        = '',
        public readonly string       $typeTriggerEvent = '',
        // The request's public_id, resolved by the caller for the same reason
        // (the relation would be stripped before the job runs). Null when the
        // notification is not about a request.
        public readonly ?string      $requestPublicId  = null,
    ) {}

    // -------------------------------------------------------
    // WHICH CHANNEL(S) TO BROADCAST TO
    // -------------------------------------------------------
    // We broadcast to TWO channels simultaneously when needed:
    //   1. The user's personal private channel (always)
    //   2. The admin channel (only if recipient is admin/superadmin)
    // -------------------------------------------------------
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('notifications.' . $this->recipient->user_id),
        ];
    }
    // -------------------------------------------------------
    // WHICH QUEUE TO DISPATCH THE BROADCAST JOB ON
    // -------------------------------------------------------
    // broadcastQueue() is the method Laravel 10+ BroadcastEvent reads to
    // decide which queue the broadcast job lands on.
    // viaQueues() is the Queueable-trait method for regular ShouldQueue jobs
    // and is silently ignored for ShouldBroadcast events — using it was the
    // root cause of all broadcast jobs landing on 'default' instead of here.
    // The broadcast-worker container drains 'broadcasts' with --sleep=1 and
    // --timeout=30 for low-latency delivery.
    // -------------------------------------------------------

    public function broadcastQueue(): string
    {
        return 'broadcasts';
    }


    // -------------------------------------------------------
    // WHAT DATA TO SEND TO THE FRONTEND
    // -------------------------------------------------------
    public function broadcastWith(): array
    {
        $data = $this->notification->data ?? [];
        return [
            'id'           => $this->notification->id,
            // Use the scalar fields passed at construction time instead of
            // accessing the relation. SerializesModels strips loaded relations
            // before the job is queued, so $notification->type would be null
            // (or fire a lazy load) when this runs inside BroadcastEvent.
            'title'        => $this->typeTitle,
            'message'      => $data['message'] ?? '',
            'type'         => $this->typeTriggerEvent,
            'request_id'   => $this->notification->request_id,
            'request_public_id' => $this->requestPublicId,
            'read_at'      => $this->notification->read_at,
            'created_at'   => $this->notification->created_at->toISOString(),
        ];
    }

    // -------------------------------------------------------
    // EVENT NAME THE FRONTEND LISTENS FOR
    // -------------------------------------------------------
    // Without this, Laravel uses the full class name as the
    // event name: "App\Events\NotificationSent" — ugly.
    // This makes it clean: listen('.NotificationSent', ...)
    // The dot prefix tells Echo this is a custom event name.
    // -------------------------------------------------------
    public function broadcastAs(): string
    {
        return 'NotificationSent';
    }
}