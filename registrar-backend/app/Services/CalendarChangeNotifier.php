<?php

namespace App\Services;

use App\Jobs\SendCalendarChangeNoticeJob;
use App\Models\BusinessCalendarHoliday;
use App\Models\BusinessCalendarOverride;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Policy §3.4 "Business Calendar Modification" -> every requestor.
 *
 * Calendar edits are recorded here and flushed as ONE notice after a short
 * quiet window (notification_matrix.calendar_notice_debounce_minutes), so a
 * registrar entering a year of holidays does not send every requestor a
 * year of notifications. The first change in a window schedules the flush;
 * later changes in the same window only add their summary.
 *
 * Best effort by design: a cache or queue hiccup must never fail the
 * registrar's calendar edit, which is already saved and audited.
 */
final class CalendarChangeNotifier
{
    public const PENDING_KEY = 'calendar-notice:pending';
    public const LOCK_KEY    = 'calendar-notice:lock';

    public static function record(string $summary): void
    {
        try {
            $first = false;

            Cache::lock(self::LOCK_KEY, 5)->block(3, function () use ($summary, &$first) {
                $pending = Cache::get(self::PENDING_KEY, []);
                $first   = $pending === [];
                $pending[] = $summary;
                Cache::put(self::PENDING_KEY, $pending, now()->addDay());
            });

            if ($first) {
                SendCalendarChangeNoticeJob::dispatch()
                    ->delay(now()->addMinutes(max(0, (int) config('notification_matrix.calendar_notice_debounce_minutes', 10))))
                    ->afterCommit();
            }
        } catch (\Throwable $e) {
            Log::warning('[CalendarChangeNotifier] could not record calendar change', ['error' => $e->getMessage()]);
        }
    }

    public static function holiday(BusinessCalendarHoliday $holiday, string $verb): void
    {
        $when = $holiday->date->format('M j, Y');
        if (! $holiday->date->equalTo($holiday->end_date)) {
            $when .= ' - ' . $holiday->end_date->format('M j, Y');
        }

        self::record(ucfirst((string) $holiday->type) . " {$verb}: {$holiday->label} ({$when})");
    }

    public static function override(BusinessCalendarOverride $override, string $verb): void
    {
        self::record('Schedule change ' . $verb . ': ' . $override->label . ' (' . ucfirst((string) $override->day_of_week) . ')');
    }
}
