<?php

namespace App\Jobs;

use App\Models\NotificationDispatchLog;
use App\Services\Mail\MailDispatcher;
use App\Services\NotificationDispatchLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;

/**
 * Sends the System Mailer email for one saved notification.
 *
 * Takes the notification id, not the model: everything is re-read at run time
 * so the recipient, allow-list and quota are judged when the mail actually
 * leaves, not when it was queued.
 *
 * Throttling uses the 'notification-mail' limiter. A throttled job is released
 * back to the queue, and every release counts as an attempt, so the retry
 * budget is a time window (retryUntil) plus a cap on real errors
 * (maxExceptions, 3) instead of $tries. Retries are safe: MailDispatcher skips a
 * notification that already has a 'sent' mail row.
 */
class SendLifecycleMailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Retry budget is a time window (retryUntil) plus a cap on real errors.
    public int $tries = 0;

    public int $maxExceptions = 3;

    public int $timeout = 60;

    public function __construct(public readonly string $notificationId)
    {
        // Wait for a caller's open transaction so the row this job re-reads exists.
        $this->afterCommit();
    }

    /** @return list<RateLimited> */
    public function middleware(): array
    {
        return [new RateLimited('notification-mail')];
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return (array) config('notification_mail.backoff', [60, 300, 900]);
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours((int) config('notification_mail.retry_hours', 6));
    }

    public function handle(MailDispatcher $dispatcher): void
    {
        $dispatcher->deliver($this->notificationId);
    }

    public function failed(\Throwable $e): void
    {
        app(NotificationDispatchLogger::class)->record(
            NotificationDispatchLog::CHANNEL_MAIL,
            NotificationDispatchLog::STATUS_FAILED,
            [
                'reason'          => NotificationDispatchLog::REASON_EXCEPTION,
                'notification_id' => $this->notificationId,
                'error'           => 'giving up: ' . $e->getMessage(),
            ],
        );
    }
}
