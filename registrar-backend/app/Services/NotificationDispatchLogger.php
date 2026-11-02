<?php

namespace App\Services;

use App\DTOs\PushDispatchResult;
use App\Models\NotificationDispatchLog;
use Illuminate\Support\Facades\Log;

/**
 * Writes the dispatch trail required by Notification Policy §4.
 *
 * Logging must never break delivery: a failure to write a log row is itself
 * logged and swallowed. It must also never leak secrets: error text is
 * stripped of URLs (push endpoints are bearer-style secrets) and truncated.
 */
class NotificationDispatchLogger
{
    /**
     * @param array{
     *   reason?: ?string, trigger_event?: ?string, notification_id?: ?string,
     *   user_id?: ?int, request_id?: ?int, attempted?: int, delivered?: int, error?: ?string
     * } $context
     */
    public function record(string $channel, string $status, array $context = []): void
    {
        try {
            NotificationDispatchLog::create([
                'channel'         => $channel,
                'status'          => $status,
                'reason'          => $context['reason'] ?? null,
                'trigger_event'   => $context['trigger_event'] ?? null,
                'notification_id' => $context['notification_id'] ?? null,
                'user_id'         => $context['user_id'] ?? null,
                'request_id'      => $context['request_id'] ?? null,
                'attempted'       => $context['attempted'] ?? 0,
                'delivered'       => $context['delivered'] ?? 0,
                'error'           => $this->sanitize($context['error'] ?? null),
                'created_at'      => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('NotificationDispatchLogger: could not write dispatch log', [
                'channel' => $channel,
                'status'  => $status,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /** @param array<string, mixed> $context same keys as record(), minus attempted/delivered/reason/error */
    public function recordPush(PushDispatchResult $result, array $context = []): void
    {
        $this->record(NotificationDispatchLog::CHANNEL_PUSH, $result->status(), $context + [
            'reason'    => $result->reason(),
            'attempted' => $result->attempted,
            'delivered' => $result->delivered,
            'error'     => $result->error,
        ]);
    }

    private function sanitize(?string $error): ?string
    {
        if ($error === null || $error === '') {
            return null;
        }

        $error = preg_replace('#https?://\S+#i', '[url]', $error) ?? $error;

        return mb_substr($error, 0, 255);
    }
}
