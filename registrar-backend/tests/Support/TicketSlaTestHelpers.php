<?php

/*
 * Helpers for the SLA (Phase 5) ticket tests. tkSla prefix; see
 * TicketApiTestHelpers.php for why helpers are require_once'd and prefixed.
 *
 * Calendar facts the expectations rely on (policy schedule): open Tuesday to
 * Friday 08:00–20:00 Asia/Manila, closed Monday, Saturday and Sunday. In
 * November 2026 that gives Tue 3, Wed 4, Thu 5, Fri 6 | Mon 9 closed | Tue 10.
 */

use App\Models\BusinessCalendar;
use App\Models\BusinessCalendarOverride;
use Illuminate\Support\Carbon;

require_once __DIR__ . '/TicketApiTestHelpers.php';

/** A Manila wall-clock time such as '2026-11-03 10:00' as a Carbon. */
function tkSlaAt(string $manila): Carbon
{
    return Carbon::parse($manila, 'Asia/Manila');
}

/**
 * Freeze "now" at a Manila wall-clock time. The frozen instant is converted to
 * the application zone, because that is what now() returns in production and
 * Eloquent stores a Carbon's wall-clock time as given.
 */
function tkSlaNow(string $manila): void
{
    Carbon::setTestNow(tkSlaAt($manila)->setTimezone(config('app.timezone')));
}

/** A stored timestamp rendered back as Manila wall-clock time, for readable assertions. */
function tkSlaManila(?Carbon $stored): ?string
{
    return $stored?->copy()->setTimezone('Asia/Manila')->format('Y-m-d H:i');
}

/**
 * The default calendar with the policy's closed Monday, set up the way the
 * registrar staff do it: a recurring "closed every Monday" override.
 */
function tkSlaPolicyCalendar(): BusinessCalendar
{
    config(['app.display_timezone' => 'Asia/Manila']);

    $calendar = BusinessCalendar::where('is_default', true)->firstOrFail();

    BusinessCalendarOverride::create([
        'calendar_id'     => $calendar->calendar_id,
        'day_of_week'     => 'monday',
        'is_closed'       => true,
        'label'           => 'Closed on Mondays',
        'effective_from'  => '2020-01-01',
        'effective_until' => null,
        'enabled'         => true,
    ]);

    return $calendar;
}
