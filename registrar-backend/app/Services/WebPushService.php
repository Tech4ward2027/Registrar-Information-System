<?php

namespace App\Services;

use App\DTOs\PushDispatchResult;
use App\Models\NotificationDispatchLog;
use App\Models\PushSubscription;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Delivers a Web Push message to every device a user has registered.
 *
 * Best-effort by design: the in-app notification (DB row + Reverb broadcast)
 * is the source of truth, so nothing here throws into the caller. Failures are
 * logged, and subscriptions the push service reports as gone (404/410) are
 * deleted so we stop sending to dead endpoints.
 */
class WebPushService
{
    public function isConfigured(): bool
    {
        return (bool) config('webpush.enabled')
            && filled(config('webpush.vapid.subject'))
            && filled(config('webpush.vapid.public_key'))
            && filled(config('webpush.vapid.private_key'));
    }

    public function publicKey(): ?string
    {
        return $this->isConfigured() ? config('webpush.vapid.public_key') : null;
    }

    /**
     * @param  array<string, mixed>  $payload  JSON-serialisable; keep well under 4 KB.
     * @return int  Number of devices the push service accepted the message for.
     */
    public function sendToUser(int $userId, array $payload): int
    {
        return $this->dispatchToUser($userId, $payload)->delivered;
    }

    /**
     * Same delivery as sendToUser(), returning the full outcome so the caller
     * can write an accurate dispatch-log row (Notification Policy §4).
     *
     * Only subscriptions with a live session are used (§3.3(3)): the FK
     * cascade removes subscriptions when their token is deleted, and the
     * withActiveSession() scope covers tokens that have expired by time but
     * not yet been pruned.
     *
     * @param  array<string, mixed>  $payload  JSON-serialisable; keep well under 4 KB.
     */
    public function dispatchToUser(int $userId, array $payload): PushDispatchResult
    {
        if (! $this->isConfigured()) {
            return PushDispatchResult::skipped(NotificationDispatchLog::REASON_NOT_CONFIGURED);
        }

        $subscriptions = PushSubscription::where('user_id', $userId)->withActiveSession()->get();
        if ($subscriptions->isEmpty()) {
            $hasAny = PushSubscription::where('user_id', $userId)->exists();

            return PushDispatchResult::skipped(
                $hasAny ? NotificationDispatchLog::REASON_NO_ACTIVE_SESSION : NotificationDispatchLog::REASON_NO_SUBSCRIPTIONS
            );
        }

        try {
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

            $webPush = new WebPush(
                [
                    'VAPID' => [
                        'subject'    => config('webpush.vapid.subject'),
                        'publicKey'  => config('webpush.vapid.public_key'),
                        'privateKey' => config('webpush.vapid.private_key'),
                    ],
                ],
                [
                    'TTL'     => (int) config('webpush.ttl'),
                    'urgency' => 'high',
                ],
                timeout: (int) config('webpush.timeout'),
            );
        } catch (\Throwable $e) {
            // Bad VAPID config or an unencodable payload — nothing can be sent.
            Log::error('WebPushService: could not initialise', ['error' => $e->getMessage()]);
            return PushDispatchResult::failed($e->getMessage());
        }

        $delivered = [];
        $expired   = [];

        // Sent one at a time on purpose: a single malformed subscription must
        // not abort delivery to the user's other devices.
        foreach ($subscriptions as $sub) {
            try {
                $report = $webPush->sendOneNotification(
                    Subscription::create([
                        'endpoint'        => $sub->endpoint,
                        'publicKey'       => $sub->public_key,
                        'authToken'       => $sub->auth_token,
                        'contentEncoding' => $sub->content_encoding,
                    ]),
                    $json,
                );
            } catch (\Throwable $e) {
                Log::warning('WebPushService: send threw', [
                    'subscription_id' => $sub->id,
                    'error'           => $e->getMessage(),
                ]);
                continue;
            }

            if ($report->isSuccess()) {
                $delivered[] = $sub->id;
            } elseif ($report->isSubscriptionExpired()) {
                $expired[] = $sub->id;
            } else {
                // 401/403 here almost always means VAPID keys/subject are wrong.
                // The endpoint URL is deliberately not logged (it is a secret).
                Log::warning('WebPushService: push rejected', [
                    'subscription_id' => $sub->id,
                    'status'          => $report->getResponse()?->getStatusCode(),
                    'reason'          => $report->getReason(),
                ]);
            }
        }

        if ($expired) {
            PushSubscription::whereIn('id', $expired)->delete();
        }
        if ($delivered) {
            PushSubscription::whereIn('id', $delivered)->update(['last_used_at' => now()]);
        }

        return PushDispatchResult::attempted($subscriptions->count(), count($delivered));
    }
}