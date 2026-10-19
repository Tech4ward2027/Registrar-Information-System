<?php

use App\Models\BusinessCalendar;
use App\Models\BusinessCalendarHoliday;
use App\Services\BusinessCalendarService;
use App\Services\CalendarExceptionService;
use App\Services\CalendarOverrideService;
use App\Models\SystemUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// Mirrors certMakeUser() in CertificationTypeControllerTest.php — super
// admin bypasses EnsureModuleAccess entirely (see
// SystemUser::hasModuleAccess()), so it's the simplest actor for
// HTTP-level tests against the calendar-exceptions routes.
function calendarExceptionActingSuperAdmin(): SystemUser
{
    $user = SystemUser::factory()->create([
        'role_id' => SystemUser::ROLE_SUPER_ADMIN,
        'status' => 'Activated',
    ]);
    Sanctum::actingAs($user);
    return $user;
}

beforeEach(function () {
    config(['app.display_timezone' => 'Asia/Manila']);
});

test('a multi-day exception closes every day in its range', function () {
    $service = new BusinessCalendarService();
    $calendar = BusinessCalendar::where('is_default', true)->firstOrFail();

    // 3-day suspension: Wed-Fri.
    $calendar->holidays()->create([
        'type'     => 'suspension',
        'label'    => 'Typhoon suspension',
        'date'     => '2026-03-11', // Wed
        'end_date' => '2026-03-13', // Fri
    ]);

    // Tuesday 10 AM -> Monday 10 AM. Only Tue (10h) + Mon (2h) should count;
    // Wed/Thu/Fri suspended, Sat/Sun already closed.
    $start = Carbon::parse('2026-03-10 10:00:00', 'Asia/Manila'); // Tuesday
    $end   = Carbon::parse('2026-03-16 10:00:00', 'Asia/Manila'); // Monday

    expect($service->minutesBetween($start, $end))->toBe((10 + 2) * 60);
});

test('a dated exception takes precedence over a recurring override on the same day', function () {
    $service = new BusinessCalendarService();
    $calendar = BusinessCalendar::where('is_default', true)->firstOrFail();

    // Every Wednesday closed (WFH), starting well before our test window.
    $calendar->overrides()->create([
        'day_of_week'     => 'wednesday',
        'is_closed'       => true,
        'label'           => 'WFH Wednesday',
        'effective_from'  => '2026-01-01',
        'effective_until' => null,
    ]);

    // But THIS particular Wednesday is also a declared event day — the
    // exception's label should win as the reason, and the day is still
    // simply closed either way (this test locks in that exceptions are
    // checked first, per the service's documented precedence).
    $calendar->holidays()->create([
        'type'     => 'event',
        'label'    => 'Mosquito fogging',
        'date'     => '2026-03-11',
        'end_date' => '2026-03-11',
    ]);

    $status = $service->currentStatus(Carbon::parse('2026-03-11 10:00:00', 'Asia/Manila'));

    expect($status['is_open'])->toBeFalse()
        ->and($status['reason'])->toBe('Mosquito fogging');
});

test('an indefinite recurring override (no effective_until) closes every matching weekday', function () {
    $service = new BusinessCalendarService();
    $calendar = BusinessCalendar::where('is_default', true)->firstOrFail();

    $calendar->overrides()->create([
        'day_of_week'     => 'monday',
        'is_closed'       => true,
        'label'           => 'WFH Monday',
        'effective_from'  => '2026-03-02',
        'effective_until' => null, // "until further notice"
    ]);

    // Two Mondays, weeks apart — both should be closed since there's no end date.
    foreach (['2026-03-09', '2026-06-15'] as $monday) {
        $status = $service->currentStatus(Carbon::parse("{$monday} 10:00:00", 'Asia/Manila'));
        expect($status['is_open'])->toBeFalse()
            ->and($status['reason'])->toBe('WFH Monday');
    }
});

test('an override with a set effective_until stops applying after that date', function () {
    $service = new BusinessCalendarService();
    $calendar = BusinessCalendar::where('is_default', true)->firstOrFail();

    $calendar->overrides()->create([
        'day_of_week'     => 'monday',
        'is_closed'       => true,
        'label'           => 'WFH Monday',
        'effective_from'  => '2026-03-02',
        'effective_until' => '2026-03-30',
    ]);

    // A Monday after the policy ended should be a normal open Monday.
    $status = $service->currentStatus(Carbon::parse('2026-04-06 10:00:00', 'Asia/Manila'));

    expect($status['is_open'])->toBeTrue();
});

test('currentStatus reason is null for an ordinary weekend, not a fabricated label', function () {
    $service = new BusinessCalendarService();

    $status = $service->currentStatus(Carbon::parse('2026-03-14 10:00:00', 'Asia/Manila')); // Saturday

    expect($status['is_open'])->toBeFalse()
        ->and($status['reason'])->toBeNull();
});

test('updating only the start date of a multi-day exception does not collapse its range', function () {
    $calendar = BusinessCalendar::where('is_default', true)->firstOrFail();

    $exception = $calendar->holidays()->create([
        'type'     => 'suspension',
        'label'    => 'Multi-day suspension',
        'date'     => '2026-03-11',
        'end_date' => '2026-03-13',
    ]);

    $service = app(CalendarExceptionService::class);
    $actor = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN]);

    $updated = $service->update($exception, ['date' => '2026-03-10'], $actor, Request::create('/'));

    // end_date must still be 03-13 — only the start moved.
    expect($updated->date->toDateString())->toBe('2026-03-10')
        ->and($updated->end_date->toDateString())->toBe('2026-03-13');
});

test('two overlapping exceptions on the same calendar are rejected', function () {
    $calendar = BusinessCalendar::where('is_default', true)->firstOrFail();
    $calendar->holidays()->create([
        'type' => 'event', 'label' => 'First', 'date' => '2026-03-11', 'end_date' => '2026-03-13',
    ]);

    $service = app(CalendarExceptionService::class);
    $actor = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN]);

    expect(fn () => $service->create([
        'type' => 'suspension', 'label' => 'Overlaps', 'date' => '2026-03-12', 'end_date' => '2026-03-14',
    ], $actor, Request::create('/')))->toThrow(ValidationException::class);
});

test('explicitly clearing end_date on a multi-day exception collapses it to a single day (regression: QA 2026-08-13)', function () {
    $calendar = BusinessCalendar::where('is_default', true)->firstOrFail();

    $exception = $calendar->holidays()->create([
        'type'     => 'suspension',
        'label'    => 'Typhoon suspension',
        'date'     => '2026-03-11',
        'end_date' => '2026-03-13',
    ]);

    $service = app(CalendarExceptionService::class);
    $actor = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN]);

    // The frontend sends end_date explicitly as null when the admin clears
    // the field in the Edit modal — that must collapse the range down to
    // the start date, not silently keep the old end_date just because the
    // value looks "empty".
    $updated = $service->update($exception, ['end_date' => null], $actor, Request::create('/'));

    expect($updated->end_date->toDateString())->toBe($updated->date->toDateString())
        ->and($updated->end_date->toDateString())->toBe('2026-03-11');
});

test('an end_date key that is entirely absent from the request still preserves the existing range', function () {
    $calendar = BusinessCalendar::where('is_default', true)->firstOrFail();

    $exception = $calendar->holidays()->create([
        'type'     => 'suspension',
        'label'    => 'Typhoon suspension',
        'date'     => '2026-03-11',
        'end_date' => '2026-03-13',
    ]);

    $service = app(CalendarExceptionService::class);
    $actor = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN]);

    // No 'end_date' key at all (e.g. only the label changed) — distinct
    // from the case above where the key is present with an explicit null.
    $updated = $service->update($exception, ['label' => 'Renamed'], $actor, Request::create('/'));

    expect($updated->label)->toBe('Renamed')
        ->and($updated->end_date->toDateString())->toBe('2026-03-13');
});

test('a disabled exception no longer closes the office, even though the row still exists', function () {
    $service = new BusinessCalendarService();
    $calendar = BusinessCalendar::where('is_default', true)->firstOrFail();

    $calendar->holidays()->create([
        'type' => 'suspension', 'label' => 'Called-off suspension',
        'date' => '2026-03-11', 'end_date' => '2026-03-11',
        'enabled' => false,
    ]);

    $status = $service->currentStatus(Carbon::parse('2026-03-11 10:00:00', 'Asia/Manila'));

    expect($status['is_open'])->toBeTrue();
});

test('a disabled recurring override no longer closes its weekday', function () {
    $service = new BusinessCalendarService();
    $calendar = BusinessCalendar::where('is_default', true)->firstOrFail();

    $calendar->overrides()->create([
        'day_of_week' => 'monday', 'is_closed' => true, 'label' => 'Paused WFH Monday',
        'effective_from' => '2026-01-01', 'effective_until' => null,
        'enabled' => false,
    ]);

    $status = $service->currentStatus(Carbon::parse('2026-03-09 10:00:00', 'Asia/Manila')); // a Monday

    expect($status['is_open'])->toBeTrue();
});

test('a disabled exception does not block a new closure covering the same dates', function () {
    $calendar = BusinessCalendar::where('is_default', true)->firstOrFail();
    $calendar->holidays()->create([
        'type' => 'event', 'label' => 'Cancelled event',
        'date' => '2026-03-11', 'end_date' => '2026-03-13',
        'enabled' => false,
    ]);

    $service = app(CalendarExceptionService::class);
    $actor = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN]);

    $exception = $service->create([
        'type' => 'suspension', 'label' => 'Real suspension', 'date' => '2026-03-12', 'end_date' => '2026-03-14',
    ], $actor, Request::create('/'));

    expect($exception->label)->toBe('Real suspension');
});

test('creating an exception with a valid closed_from_time stores it', function () {
    $service = app(CalendarExceptionService::class);
    $actor = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN]);

    $exception = $service->create([
        'type' => 'suspension', 'label' => 'Typhoon suspension',
        'date' => '2026-03-11', 'end_date' => '2026-03-11',
        'closed_from_time' => '15:00',
    ], $actor, Request::create('/'));

    expect(Carbon::parse($exception->closed_from_time)->format('H:i'))->toBe('15:00');
});

test('a closed_from_time at or before the normal opening time is rejected', function () {
    $service = app(CalendarExceptionService::class);
    $actor = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN]);

    // Default calendar opens at 08:00 — 07:30 isn't a partial closure,
    // it's a full one.
    expect(fn () => $service->create([
        'type' => 'suspension', 'label' => 'Bad cutoff',
        'date' => '2026-03-11', 'end_date' => '2026-03-11',
        'closed_from_time' => '07:30',
    ], $actor, Request::create('/')))->toThrow(ValidationException::class);

    // Exactly the opening time is also rejected — that's still "the
    // whole day," not a partial closure.
    expect(fn () => $service->create([
        'type' => 'suspension', 'label' => 'Bad cutoff',
        'date' => '2026-03-11', 'end_date' => '2026-03-11',
        'closed_from_time' => '08:00',
    ], $actor, Request::create('/')))->toThrow(ValidationException::class);
});

test('creating an exception via the API rejects a closed_from_time outside 8 AM–8 PM', function () {
    calendarExceptionActingSuperAdmin();

    // A future Monday — guaranteed to be both in the future (won't trip
    // the past-date rule below) and a normal 08:00–20:00 business day on
    // the default calendar (won't trip the "must be after normal opening"
    // service-level check either), so this test exercises only the 8
    // AM–8 PM window rule in isolation.
    $date = Carbon::now(config('app.display_timezone', 'Asia/Manila'))->next(Carbon::MONDAY)->toDateString();

    $tooEarly = $this->postJson('/api/v1/calendar-exceptions', [
        'type' => 'suspension', 'label' => 'Too early',
        'date' => $date, 'end_date' => $date,
        'closed_from_time' => '05:00',
    ]);
    $tooEarly->assertStatus(422)->assertJsonValidationErrors('closed_from_time');

    $tooLate = $this->postJson('/api/v1/calendar-exceptions', [
        'type' => 'suspension', 'label' => 'Too late',
        'date' => $date, 'end_date' => $date,
        'closed_from_time' => '21:00',
    ]);
    $tooLate->assertStatus(422)->assertJsonValidationErrors('closed_from_time');

    expect(BusinessCalendarHoliday::count())->toBe(0);
});

test('creating an exception via the API accepts closed_from_time at the 8 AM–8 PM boundaries', function () {
    calendarExceptionActingSuperAdmin();

    // Two distinct future weekdays so the two closures below don't
    // overlap (assertNoOverlap would otherwise reject the second one).
    $monday  = Carbon::now(config('app.display_timezone', 'Asia/Manila'))->next(Carbon::MONDAY)->toDateString();
    $tuesday = Carbon::now(config('app.display_timezone', 'Asia/Manila'))->next(Carbon::TUESDAY)->toDateString();

    // 08:00 is within the request's own validation bounds, but the
    // service-level "must be after the day's normal opening time" check
    // (see the test above this one in the file) will still reject it if
    // the default calendar also opens at 08:00 — so exercise the two
    // rules independently by using a time comfortably inside the window
    // that's also after the default calendar's opening time, plus the
    // literal upper boundary.
    $withinWindow = $this->postJson('/api/v1/calendar-exceptions', [
        'type' => 'suspension', 'label' => 'Within window',
        'date' => $monday, 'end_date' => $monday,
        'closed_from_time' => '08:01',
    ]);
    $withinWindow->assertStatus(201);

    $upperBoundary = $this->postJson('/api/v1/calendar-exceptions', [
        'type' => 'suspension', 'label' => 'Upper boundary',
        'date' => $tuesday, 'end_date' => $tuesday,
        'closed_from_time' => '20:00',
    ]);
    $upperBoundary->assertStatus(201);
});

test('creating an exception with a past date is rejected', function () {
    calendarExceptionActingSuperAdmin();

    $yesterday = Carbon::now(config('app.display_timezone', 'Asia/Manila'))->subDay()->toDateString();

    $response = $this->postJson('/api/v1/calendar-exceptions', [
        'type' => 'suspension', 'label' => 'Backdated closure',
        'date' => $yesterday, 'end_date' => $yesterday,
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors('date');
    expect(BusinessCalendarHoliday::count())->toBe(0);
});

test('creating an exception dated today is accepted', function () {
    calendarExceptionActingSuperAdmin();

    // "Today" per the app's display timezone, not the test runner's
    // local/UTC time — see the matching comment in
    // StoreCalendarExceptionRequest. Same-day closures (e.g. an
    // emergency suspension declared the morning of) must stay allowed.
    $today = Carbon::now(config('app.display_timezone', 'Asia/Manila'))->toDateString();

    $response = $this->postJson('/api/v1/calendar-exceptions', [
        'type' => 'suspension', 'label' => 'Same-day suspension',
        'date' => $today, 'end_date' => $today,
    ]);

    $response->assertStatus(201);
});

test('editing an existing closure that already has a past date is still allowed', function () {
    $calendar = BusinessCalendar::where('is_default', true)->firstOrFail();
    $pastDate = Carbon::now(config('app.display_timezone', 'Asia/Manila'))->subMonth()->toDateString();

    $exception = $calendar->holidays()->create([
        'type' => 'holiday', 'label' => 'Old label',
        'date' => $pastDate, 'end_date' => $pastDate,
    ]);

    calendarExceptionActingSuperAdmin();

    // The past-date rule is scoped to *creation* (StoreCalendarExceptionRequest)
    // only — UpdateCalendarExceptionRequest deliberately has no such rule,
    // so correcting a typo on a historical entry keeps working.
    $response = $this->putJson("/api/v1/calendar-exceptions/{$exception->holiday_id}", [
        'label' => 'Corrected label',
    ]);

    $response->assertStatus(200);
    expect($exception->fresh()->label)->toBe('Corrected label');
});

test('explicitly clearing closed_from_time on update collapses the exception back to a full-day closure', function () {
    $calendar = BusinessCalendar::where('is_default', true)->firstOrFail();

    $exception = $calendar->holidays()->create([
        'type' => 'suspension', 'label' => 'Typhoon suspension',
        'date' => '2026-03-11', 'end_date' => '2026-03-11',
        'closed_from_time' => '15:00',
    ]);

    $service = app(CalendarExceptionService::class);
    $actor = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN]);

    // Same array_key_exists shape as the end_date regression above: an
    // explicit null must actually clear the cutoff, not be ignored.
    $updated = $service->update($exception, ['closed_from_time' => null], $actor, Request::create('/'));

    expect($updated->closed_from_time)->toBeNull();

    $status = (new BusinessCalendarService())->currentStatus(Carbon::parse('2026-03-11 16:00:00', 'Asia/Manila'));
    expect($status['is_open'])->toBeFalse(); // now fully closed all day, past what used to be "open" before 3pm
});

test('a closed_from_time key entirely absent from an update request preserves the existing cutoff', function () {
    $calendar = BusinessCalendar::where('is_default', true)->firstOrFail();

    $exception = $calendar->holidays()->create([
        'type' => 'suspension', 'label' => 'Typhoon suspension',
        'date' => '2026-03-11', 'end_date' => '2026-03-11',
        'closed_from_time' => '15:00',
    ]);

    $service = app(CalendarExceptionService::class);
    $actor = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN]);

    $updated = $service->update($exception, ['label' => 'Renamed'], $actor, Request::create('/'));

    expect($updated->label)->toBe('Renamed')
        ->and(Carbon::parse($updated->closed_from_time)->format('H:i'))->toBe('15:00');
});

test('toggling enabled off via update() takes effect immediately, and back on again restores it', function () {
    $service = new BusinessCalendarService();
    $calendar = BusinessCalendar::where('is_default', true)->firstOrFail();

    $exception = $calendar->holidays()->create([
        'type' => 'suspension', 'label' => 'Toggle me', 'date' => '2026-03-11', 'end_date' => '2026-03-11',
    ]);

    $exceptionService = app(CalendarExceptionService::class);
    $actor = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN]);

    $exceptionService->update($exception, ['enabled' => false], $actor, Request::create('/'));
    expect((new BusinessCalendarService())->currentStatus(Carbon::parse('2026-03-11 10:00:00', 'Asia/Manila'))['is_open'])->toBeTrue();

    $exceptionService->update($exception->refresh(), ['enabled' => true], $actor, Request::create('/'));
    expect((new BusinessCalendarService())->currentStatus(Carbon::parse('2026-03-11 10:00:00', 'Asia/Manila'))['is_open'])->toBeFalse();
});