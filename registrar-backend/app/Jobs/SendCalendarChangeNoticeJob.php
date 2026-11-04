<?php

namespace App\Jobs;

use App\Models\SystemUser;
use App\Services\CalendarChangeNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Flushes the coalesced calendar changes (see CalendarChangeNotifier) as one
 * "business_calendar_modified" notification to every requestor role. The
 * actual per-user fan-out stays in SendBulkNotificationJob, which streams
 * users with cursor() and goes through NotificationService (dispatch log,
 * push, in-app) like every other notification.
 */
class SendCalendarChangeNoticeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    private const DETAILS_LIMIT = 300;

    public function handle(): void
    {
        $summaries = [];

        Cache::lock(CalendarChangeNotifier::LOCK_KEY, 5)->block(3, function () use (&$summaries) {
            $summaries = Cache::pull(CalendarChangeNotifier::PENDING_KEY, []);
        });

        if ($summaries === []) {
            return; // an earlier run already flushed these
        }

        $details = count($summaries) === 1
            ? $summaries[0]
            : count($summaries) . ' updates: ' . implode('; ', $summaries);

        SendBulkNotificationJob::dispatch(
            triggerEvent: 'business_calendar_modified',
            data:         ['details' => Str::limit($details, self::DETAILS_LIMIT)],
            onlyRoleIds:  [
                SystemUser::ROLE_STUDENT,
                SystemUser::ROLE_ALUMNI,
                SystemUser::ROLE_UNDERGRAD_REQUESTOR,
            ],
        );
    }
}
