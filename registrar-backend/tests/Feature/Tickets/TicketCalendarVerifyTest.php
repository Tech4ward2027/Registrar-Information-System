<?php

use App\Models\BusinessCalendar;
use App\Models\BusinessCalendarOverride;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

require_once __DIR__ . '/../../Support/TicketSlaTestHelpers.php';

uses(RefreshDatabase::class);

/*
 * Phase 5 — tickets:verify-calendar. Read-only: it reports whether the calendar
 * the ticket clocks run on is open Tue–Fri 08:00–20:00 and closed Mon/Sat/Sun.
 */

function tkVerify(): array
{
    $code = Artisan::call('tickets:verify-calendar');

    return [$code, Artisan::output()];
}

test('a calendar with the policy schedule passes', function () {
    tkSlaPolicyCalendar();

    [$code, $out] = tkVerify();

    expect($code)->toBe(0)->and($out)->toContain('OK');
});

test('the seeded Monday-to-Friday calendar fails and says how to fix Monday', function () {
    config(['app.display_timezone' => 'Asia/Manila']);

    [$code, $out] = tkVerify();

    expect($code)->toBe(1)
        ->and($out)->toContain('Monday')
        ->and($out)->toContain('recurring override')
        ->and($out)->not->toContain('Tuesday');
});

test('a disabled Monday override does not count', function () {
    tkSlaPolicyCalendar();
    BusinessCalendarOverride::query()->update(['enabled' => false]);

    [$code, $out] = tkVerify();

    expect($code)->toBe(1)->and($out)->toContain('Monday');
});

test('different opening hours are reported', function () {
    tkSlaPolicyCalendar();
    $calendar = BusinessCalendar::where('is_default', true)->firstOrFail();
    $hours = $calendar->weekly_hours;
    $hours['tuesday'] = ['open' => '09:00', 'close' => '17:00'];
    $calendar->update(['weekly_hours' => $hours]);

    [$code, $out] = tkVerify();

    expect($code)->toBe(1)->and($out)->toContain('Tuesday')->and($out)->toContain('09:00');
});

test('an open Saturday is reported', function () {
    tkSlaPolicyCalendar();
    $calendar = BusinessCalendar::where('is_default', true)->firstOrFail();
    $hours = $calendar->weekly_hours;
    $hours['saturday'] = ['open' => '08:00', 'close' => '12:00'];
    $calendar->update(['weekly_hours' => $hours]);

    [$code, $out] = tkVerify();

    expect($code)->toBe(1)->and($out)->toContain('Saturday');
});

test('a wrong operating-day length is reported', function () {
    tkSlaPolicyCalendar();
    config(['tickets.sla.operating_day_hours' => 8]);

    [$code, $out] = tkVerify();

    expect($code)->toBe(1)->and($out)->toContain('OPERATING_DAY_HOURS');
});

test('it never changes the calendar', function () {
    $before = [
        BusinessCalendar::query()->get()->toArray(),
        BusinessCalendarOverride::query()->count(),
    ];

    tkVerify();

    expect([BusinessCalendar::query()->get()->toArray(), BusinessCalendarOverride::query()->count()])->toBe($before);
});

test('no default calendar is a failure, not a crash', function () {
    BusinessCalendar::query()->update(['is_default' => false]);

    [$code, $out] = tkVerify();

    expect($code)->toBe(1)->and($out)->toContain('no default business calendar');
});

test('a configured calendar id that does not exist is a failure', function () {
    config(['tickets.sla.calendar_id' => 999999]);

    [$code, $out] = tkVerify();

    expect($code)->toBe(1)->and($out)->toContain('999999');
});
