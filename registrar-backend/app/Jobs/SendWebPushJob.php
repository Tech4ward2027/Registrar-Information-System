<?php

namespace App\Jobs;

use App\Models\Notification;
use App\Models\SystemUser;
use App\Services\WebPushService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Sends the Web Push counterpart of one saved notification.
 *
 * Runs on the 'broadcasts' queue (the low-latency worker that already drains
 * Reverb pushes) so a slow push service never delays DB-heavy jobs.
 *
 * Takes the notification's id rather than the model: the row is re-read at
 * run time so a notification that was read or dismissed in the meantime is
 * not pushed to a lock screen.
 *
 * $tries = 1: a retry would re-send to devices that already received the
 * message. Push is best-effort; the in-app notification is authoritative.
 */
class SendWebPushJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    // Must stay below the broadcast worker's --timeout=30.
    public int $timeout = 25;

    public function __construct(public readonly string $notificationId)
    {
        $this->onQueue('broadcasts');
        // If send() runs inside a caller's outer DB transaction, wait for it
        // to commit so the row this job re-reads actually exists.
        $this->afterCommit();
    }

    public function handle(WebPushService $push): void
    {
        if (! $push->isConfigured()) {
            return;
        }

        // SoftDeletes scope already excludes dismissed notifications.
        $notification = Notification::with('type')->find($this->notificationId);

        if (! $notification || ! $notification->type || $notification->read_at !== null) {
            return;
        }

        $user = SystemUser::where('user_id', $notification->notifiable_id)
            ->where('status', 'Activated')
            ->first();

        if (! $user) {
            return;
        }

        $push->sendToUser((int) $user->user_id, $this->buildPayload($notification, $user));
    }

    /**
     * Payload consumed by public/sw.js. Contains no PII by default — it
     * transits third-party push services (see config/webpush.php).
     */
    private function buildPayload(Notification $notification, SystemUser $user): array
    {
        $requestId = $notification->request_id;
        $message   = (string) (($notification->data ?? [])['message'] ?? '');

        $body = config('webpush.include_message') && $message !== ''
            ? $message
            : ($requestId
                ? 'There is an update on one of your requests. Open PUPT-Registrar to view the details.'
                : 'You have a new notification. Open PUPT-Registrar to view the details.');

        return [
            'title'           => $notification->type->title,
            'body'            => $body,
            'url'             => config('webpush.inbox_paths')[(int) $user->role_id] ?? '/',
            // Same tag = the OS replaces the earlier banner for that request
            // instead of stacking one per status change.
            'tag'             => $requestId ? 'request-' . $this->requestTag((int) $requestId) : "notification-{$notification->id}",
            'notification_id' => $notification->id,
            'icon'            => config('webpush.icon'),
            'timestamp'       => $notification->created_at?->getTimestamp() * 1000,
        ];
    }

    /**
     * Opaque, stable per-request tag. The tag is only used by the device to
     * collapse repeat banners for the same request; it is visible to the push
     * service and the OS, so it must not expose the sequential request_id.
     * HMAC with the app key: stable for a given request, not reversible, and
     * not guessable by enumerating ids.
     */
    private function requestTag(int $requestId): string
    {
        return substr(hash_hmac('sha256', (string) $requestId, (string) config('app.key')), 0, 16);
    }
}