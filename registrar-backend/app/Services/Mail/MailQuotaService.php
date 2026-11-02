<?php

namespace App\Services\Mail;

use App\Contracts\NotificationServiceInterface;
use App\Models\NotificationDispatchLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Mailer quota accounting (Notification Policy: Super Admin monitors quota).
 *
 * Usage is counted from notification_dispatch_logs (channel = mail,
 * status = sent), the same trail that records every attempt, so there is one
 * source of truth and no counter that can drift from what actually went out.
 * The (channel, status, created_at) index keeps the count a short range scan.
 */
class MailQuotaService
{
    public function dailyLimit(): int
    {
        return max(0, (int) config('notification_mail.quota.daily_limit'));
    }

    public function usedToday(): int
    {
        return $this->sentSince(now()->startOfDay());
    }

    public function usedThisMonth(): int
    {
        return $this->sentSince(now()->startOfMonth());
    }

    /** A limit of 0 means "no limit configured": monitoring only, nothing blocked. */
    public function lifecycleAllowed(): bool
    {
        if (! config('notification_mail.quota.enforce') || $this->dailyLimit() === 0) {
            return true;
        }

        return $this->usedToday() < $this->dailyLimit();
    }

    /** @return array{daily_limit:int,used_today:int,remaining_today:?int,used_this_month:int,warn_ratio:float,enforced:bool,state:string} */
    public function snapshot(): array
    {
        $limit = $this->dailyLimit();
        $used  = $this->usedToday();

        return [
            'daily_limit'     => $limit,
            'used_today'      => $used,
            'remaining_today' => $limit > 0 ? max(0, $limit - $used) : null,
            'used_this_month' => $this->usedThisMonth(),
            'warn_ratio'      => (float) config('notification_mail.quota.warn_ratio'),
            'enforced'        => (bool) config('notification_mail.quota.enforce'),
            'state'           => $this->state($used, $limit),
        ];
    }

    /**
     * Alert Super Admins once per day at the warning level and once at the
     * limit. Cache::add() is atomic, so concurrent workers raise one alert.
     * Never throws: monitoring must not break delivery.
     */
    public function evaluateAlerts(): void
    {
        try {
            $limit = $this->dailyLimit();
            if ($limit === 0) {
                return;
            }

            $used  = $this->usedToday();
            $state = $this->state($used, $limit);
            if ($state === 'ok') {
                return;
            }

            $key = "mail_quota_alert:{$state}:" . now()->toDateString();
            if (! Cache::add($key, 1, now()->endOfDay())) {
                return;
            }

            $summary = $state === 'exceeded'
                ? "daily limit of {$limit} reached ({$used} sent); lifecycle emails are paused until tomorrow."
                : "{$used} of {$limit} daily emails used.";

            app(NotificationServiceInterface::class)->sendToSuperAdmins('mail_quota_alert', ['summary' => $summary]);
        } catch (Throwable $e) {
            Log::warning('MailQuotaService: could not evaluate quota alerts', ['error' => $e->getMessage()]);
        }
    }

    private function state(int $used, int $limit): string
    {
        if ($limit === 0) {
            return 'ok';
        }
        if ($used >= $limit) {
            return 'exceeded';
        }

        return $used >= (int) ceil($limit * (float) config('notification_mail.quota.warn_ratio')) ? 'warning' : 'ok';
    }

    private function sentSince(\DateTimeInterface $since): int
    {
        return NotificationDispatchLog::where('channel', NotificationDispatchLog::CHANNEL_MAIL)
            ->where('status', NotificationDispatchLog::STATUS_SENT)
            ->where('created_at', '>=', $since)
            ->count();
    }
}
