<?php

namespace App\Services;

use App\Events\NotificationSent;
use App\Models\DocumentRequest;
use App\Models\Notification;
use App\Models\NotificationType;
use App\Models\SystemUser;
use App\Jobs\SendBulkNotificationJob;
use App\Jobs\SendWebPushJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Contracts\NotificationServiceInterface;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| NotificationService
|--------------------------------------------------------------------------
| The single entry point for sending any notification in the system.
|
| HOW TO USE FROM A CONTROLLER:
|
|   // Send to a specific user:
|   NotificationService::send(
|       recipient:    $user,
|       triggerEvent: 'request_submitted',
|       data:         ['request_id' => $request->request_id],
|       requestId:    $request->request_id
|   );
|
|   // Send to all admins:
|   NotificationService::sendToAdmins(
|       triggerEvent: 'admin_new_request',
|       data:         ['request_id' => $request->request_id]
|   );
|
|--------------------------------------------------------------------------
*/

class NotificationService implements NotificationServiceInterface
{
    // -------------------------------------------------------
    // SEND TO A SPECIFIC USER
    // -------------------------------------------------------
    // This is the core method everything else calls.
    //
    // What happens here step by step:
    //   1. Look up the notification_type by trigger_event slug
    //   2. Build the message by replacing :placeholders in the template
    //   3. Save a row to the notifications table (persistent storage)
    //   4. Fire the broadcast event (real-time WebSocket push via Reverb)
    //
    // We wrap steps 3+4 in a DB transaction so if the broadcast
    // fails, the notification row is still saved (and vice versa
    // the row won't be orphaned if something goes wrong mid-save).
    // -------------------------------------------------------
    public function send(
        SystemUser $recipient,
        string     $triggerEvent,
        array      $data      = [],
        ?int       $requestId = null,
    ): ?Notification {

        try {
            // Step 1: Find the notification template
            // Cache the lookup — notification types change only via seeder/admin,
            // so a 6-hour TTL is safe and avoids a DB hit on every send().
            $type = Cache::remember(
                "notif_type:{$triggerEvent}",
                now()->addHours(6),
                fn () => NotificationType::where('trigger_event', $triggerEvent)
                             ->where('is_active', true)
                             ->first()
            );

            if (! $type) {
                Log::warning("NotificationService: unknown trigger_event '{$triggerEvent}'");
                return null;
            }

            // Step 2: Build the final message
            // Replace :placeholders in the template with actual values
            // e.g. "Payment verified for request #:request_id"
            //   → "Payment verified for request #42"
            $message = $this->buildMessage($type->message_template, $data);

            // Capture scalar values from the type NOW, before the transaction,
            // so we don't rely on a loaded relation inside the queued broadcast job.
            // SerializesModels strips loaded relations from the event before queuing.
            $typeTitle        = $type->title;
            $typeTriggerEvent = $type->trigger_event;

            // public_id for the broadcast payload, looked up now for the same
            // reason. Archived requests included. One indexed primary-key read.
            $requestPublicId = $requestId !== null
                ? DocumentRequest::withArchived()->whereKey($requestId)->value('public_id')
                : null;

            // Step 3 + 4: Save to DB and broadcast — atomically
            $notification = null;
            // &$notification — pass by reference so the Notification created
            // inside the closure is visible to the outer scope after the
            // transaction commits. Without &, PHP captures a copy and the
            // outer variable stays null (PHP closures are pass-by-value by default).
            DB::transaction(function () use (&$notification,
                $recipient, $type, $typeTitle, $typeTriggerEvent, $message, $data, $requestId, $requestPublicId
            ) {
                $notification = Notification::create([
                    'notification_type_id' => $type->notification_type_id,
                    'notifiable_type'      => SystemUser::class,
                    'notifiable_id'        => $recipient->user_id,
                    'data'                 => array_merge($data, ['message' => $message]),
                    'request_id'           => $requestId,
                ]);

                // Fire the broadcast event — Reverb picks this up and
                // pushes it to the frontend over WebSockets instantly.
                // We pass typeTitle + typeTriggerEvent as plain strings so
                // broadcastWith() never touches the relation (which would be
                // stripped by SerializesModels before the job runs).
                broadcast(new NotificationSent($notification, $recipient, $typeTitle, $typeTriggerEvent, $requestPublicId));

                return $notification;
            });

            // Step 5: Background (Web Push) delivery for urgent types only.
            // Done after the transaction and isolated in its own try/catch so
            // a queue hiccup can never turn a saved notification into a null.
            $this->queueWebPush($notification, $type);

            // Return the notification created inside the transaction.
            // The closure's own "return $notification" returns to DB::transaction(),
            // not to this method — without this line send() fell off the end with
            // no return value, violating the ?Notification return type declaration
            // and throwing "Return value must be of type ?Notification, none returned".
            return $notification;

        } catch (\Throwable $e) {
            Log::error('NotificationService::send failed', [
                'trigger_event' => $triggerEvent,
                'recipient_id'  => $recipient->user_id,
                'error'         => $e->getMessage(),
            ]);
            return null;
        }
    }

    // -------------------------------------------------------
    // QUEUE WEB PUSH (background notification)
    // -------------------------------------------------------
    // Only types flagged send_push (urgent ones) are pushed to the lock
    // screen. `getAttributes()` rather than `$type->send_push` because the
    // type may come from a cache entry written before the column existed.
    // -------------------------------------------------------
    private function queueWebPush(Notification $notification, NotificationType $type): void
    {
        if (! config('webpush.enabled') || empty($type->getAttributes()['send_push'])) {
            return;
        }

        try {
            SendWebPushJob::dispatch($notification->id);
        } catch (\Throwable $e) {
            Log::warning('NotificationService: could not queue web push', [
                'notification_id' => $notification->id,
                'error'           => $e->getMessage(),
            ]);
        }
    }

    // -------------------------------------------------------
    // SEND TO ALL SUPER ADMINS
    // -------------------------------------------------------
    // Convenience method for super-admin-only audience notifications
    // (e.g. access request submissions — only a Super Admin can
    // review/approve those, so regular Admins are not targeted).
    // -------------------------------------------------------
    public function sendToSuperAdmins(
        string $triggerEvent,
        array  $data      = [],
        ?int   $requestId = null,
    ): void {
        dispatch(new SendBulkNotificationJob(
            triggerEvent: $triggerEvent,
            data:         $data,
            onlyRoleIds:  [SystemUser::ROLE_SUPER_ADMIN],
            requestId:    $requestId,
        ));
    }

    // -------------------------------------------------------
    // SEND TO ALL ADMINS AND SUPER ADMINS
    // -------------------------------------------------------
    // Convenience method for admin-audience notifications.
    // Fetches all active admin/superadmin users and sends
    // each one a personal notification.
    //
    // Why send individually instead of one broadcast?
    // Because we need a DB row per user for the notification
    // center/bell icon to work — each admin needs their own
    // read_at state. The WebSocket push is just the real-time
    // alert on top of that persistent record.
    // -------------------------------------------------------
    public function sendToAllExcept(
        array  $excludedRoleIds,
        string $triggerEvent,
        array  $data      = [],
        ?int   $requestId = null,
    ): void {
        // Dispatch to queue — loop runs in background, HTTP response is instant.
        dispatch(new SendBulkNotificationJob(
            triggerEvent:    $triggerEvent,
            data:            $data,
            excludedRoleIds: $excludedRoleIds,
            requestId:       $requestId,
        ));
    }
    public function sendToAdmins(
        string $triggerEvent,
        array  $data      = [],
        ?int   $requestId = null,
    ): void {
        // Only send to ROLE_ADMIN — superadmins are not concerned
        // with document request handling notifications
        dispatch(new SendBulkNotificationJob(
            triggerEvent: $triggerEvent,
            data:         $data,
            onlyRoleIds:  [SystemUser::ROLE_ADMIN],
            requestId:    $requestId,
        ));
    }

    // -------------------------------------------------------
    // BUILD MESSAGE FROM TEMPLATE
    // -------------------------------------------------------
    // Replaces :key placeholders in the template string
    // with values from the $data array.
    //
    // Example:
    //   template: "Payment verified for request #:request_id"
    //   data:     ['request_id' => 42]
    //   result:   "Payment verified for request #42"
    // -------------------------------------------------------
    private function buildMessage(string $template, array $data): string
    {
        foreach ($data as $key => $value) {
            if (is_scalar($value)) {
                $template = str_replace(':' . $key, $value, $template);
            }
        }
        return $template;
    }

    // -------------------------------------------------------
    // GET UNREAD COUNT FOR A USER
    // -------------------------------------------------------
    // Used by the frontend bell icon badge.
    // Returns a simple integer — fast single-column query.
    // -------------------------------------------------------
    public function unreadCount(SystemUser $user): int
    {
        return Notification::where('notifiable_type', SystemUser::class)
            ->where('notifiable_id', $user->user_id)
            ->whereNull('read_at')
            ->whereNull('deleted_at')
            ->count();
    }

    // -------------------------------------------------------
    // MARK ALL AS READ FOR A USER
    // -------------------------------------------------------
    public function markAllAsRead(SystemUser $user): void
    {
        Notification::where('notifiable_type', SystemUser::class)
            ->where('notifiable_id', $user->user_id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }
}