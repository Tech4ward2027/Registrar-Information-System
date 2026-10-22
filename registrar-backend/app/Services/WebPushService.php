<?php

namespace App\Services;

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
        if (! $this->isConfigured()) {
            return 0;
        }

        $subscriptions = PushSubscription::where('user_id', $userId)->get();
        if ($subscriptions->isEmpty()) {
            return 0;
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
            return 0;
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

        return count($delivered);
    }
}
