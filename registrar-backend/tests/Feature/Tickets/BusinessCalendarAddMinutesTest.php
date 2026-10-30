<?php

use App\Models\BusinessCalendar;
use App\Services\BusinessCalendarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

require_once __DIR__ . '/../../Support/TicketSlaTestHelpers.php';

uses(RefreshDatabase::class);

/*
 * BusinessCalendarService::addBusinessMinutes() — the "due date" half of the
 * calendar engine (minutesBetween() being the "elapsed" half). November 2026:
 * Tue 3, Wed 4, Thu 5, Fri 6, Sat 7, Sun 8, Mon 9, Tue 10.
 */

beforeEach(function () {
    tkSlaPolicyCalendar(); // Tue–Fri 08:00–20:00, Monday closed
});

function tkAdd(string $from, int $minutes): string
{
    return (new BusinessCalendarService())->addBusinessMinutes(tkSlaAt($from), $minutes)->format('Y-m-d H:i');
}

test('inside one open day it is plain addition', function () {
    expect(tkAdd('2026-11-03 10:00', 4 * 60))->toBe('2026-11-03 14:00');
});

test('time that does not fit in the day carries over to the next opening', function () {
    // 2 h left on Tuesday, 2 h more on Wednesday morning.
    expect(tkAdd('2026-11-03 18:00', 4 * 60))->toBe('2026-11-04 10:00');
});

test('landing exactly on closing time returns the closing time', function () {
    expect(tkAdd('2026-11-03 12:00', 8 * 60))->toBe('2026-11-03 20:00');
});

test('starting after closing counts from the next morning', function () {
    expect(tkAdd('2026-11-03 22:00', 60))->toBe('2026-11-04 09:00');
});

test('starting before opening counts from opening', function () {
    expect(tkAdd('2026-11-03 06:00', 60))->toBe('2026-11-03 09:00');
});

test('Saturday, Sunday and the closed Monday do not count', function () {
    // Friday 18:00 + 4 h: 2 h Friday, then the next opening is TUESDAY 08:00.
    expect(tkAdd('2026-11-06 18:00', 4 * 60))->toBe('2026-11-10 10:00');
});

test('a filing on the closed Monday starts at Tuesday opening', function () {
    expect(tkAdd('2026-11-09 10:00', 4 * 60))->toBe('2026-11-10 12:00');
});

test('without the Monday override Monday counts like any other weekday', function () {
    \App\Models\BusinessCalendarOverride::query()->delete();

    expect(tkAdd('2026-11-06 18:00', 4 * 60))->toBe('2026-11-09 10:00');
});

test('a declared holiday is skipped', function () {
    BusinessCalendar::where('is_default', true)->firstOrFail()
        ->holidays()->create(['date' => '2026-11-04', 'label' => 'Test holiday']); // Wednesday

    // 2 h Tuesday, Wednesday closed, 10 h Thursday: 12 working hours.
    expect(tkAdd('2026-11-03 18:00', 12 * 60))->toBe('2026-11-05 18:00');
});

test('a disabled holiday has no effect', function () {
    BusinessCalendar::where('is_default', true)->firstOrFail()
        ->holidays()->create(['date' => '2026-11-04', 'label' => 'Off', 'enabled' => false]);

    expect(tkAdd('2026-11-03 18:00', 12 * 60))->toBe('2026-11-04 18:00');
});

test('seconds are kept exactly', function () {
    $from = tkSlaAt('2026-11-03 10:00:30');

    expect((new BusinessCalendarService())->addBusinessMinutes($from, 60)->format('Y-m-d H:i:s'))->toBe('2026-11-03 11:00:30');
});

test('zero minutes returns the start unchanged and a negative number is refused', function () {
    $service = new BusinessCalendarService();
    $from    = tkSlaAt('2026-11-03 22:00');

    expect($service->addBusinessMinutes($from, 0)->equalTo($from))->toBeTrue();
    expect(fn () => $service->addBusinessMinutes($from, -1))->toThrow(InvalidArgumentException::class);
});

test('it is the exact inverse of minutesBetween() for every kind of start', function () {
    $service = new BusinessCalendarService();

    $starts = [
        '2026-11-03 10:00', '2026-11-03 19:30', '2026-11-03 21:00', '2026-11-03 05:00',
        '2026-11-06 18:00', '2026-11-07 12:00', '2026-11-08 09:00', '2026-11-09 10:00',
    ];

    foreach ($starts as $start) {
        foreach ([1, 59, 240, 720, 1440, 3000] as $minutes) {
            $from = tkSlaAt($start);
            $due  = $service->addBusinessMinutes($from, $minutes);

            expect($service->minutesBetween($from, $due))->toBe($minutes, "from {$start} + {$minutes} min");
        }
    }
});

test('the result is in the display timezone and the same instant whatever zone the start was in', function () {
    $service = new BusinessCalendarService();

    $manila = $service->addBusinessMinutes(tkSlaAt('2026-11-03 10:00'), 240);
    $utc    = $service->addBusinessMinutes(tkSlaAt('2026-11-03 10:00')->setTimezone('UTC'), 240);

    expect($manila->timezoneName)->toBe('Asia/Manila')
        ->and($manila->equalTo($utc))->toBeTrue();
});

test('a calendar with no open day throws instead of looping forever', function () {
    BusinessCalendar::where('is_default', true)->update(['weekly_hours' => json_encode(array_fill_keys(
        ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'], null,
    ))]);

    expect(fn () => (new BusinessCalendarService())->addBusinessMinutes(tkSlaAt('2026-11-03 10:00'), 60))
        ->toThrow(RuntimeException::class);
});
