<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\BusinessCalendar;
use App\Models\BusinessCalendarHoliday;
use App\Models\SystemUser;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Owns the business logic for one-off dated calendar closures — holidays,
 * suspensions, and events (fumigation, team-building). Kept separate from
 * CalendarOverrideController's HTTP layer so overlap validation and audit
 * logging live in exactly one place, same reasoning as AnnouncementService.
 */
class CalendarExceptionService
{
    public function __construct(
        private BusinessCalendarService $calendarService,
        private AuditLogger $auditLogger,
    ) {}

    public function create(array $validated, SystemUser $actor, Request $request): BusinessCalendarHoliday
    {
        $calendarId = $validated['calendar_id'] ?? $this->calendarService->defaultCalendar()->calendar_id;
        $endDate = $validated['end_date'] ?? $validated['date'];
        $closedFromTime = $validated['closed_from_time'] ?? null;

        $this->assertNoOverlap($calendarId, $validated['date'], $endDate);
        $this->assertCutoffAfterOpening($calendarId, $validated['date'], $closedFromTime);

        $exception = BusinessCalendarHoliday::create([
            'calendar_id'      => $calendarId,
            'type'             => $validated['type'],
            'label'            => $validated['label'],
            'date'             => $validated['date'],
            'end_date'         => $endDate,
            'closed_from_time' => $closedFromTime,
        ]);

        $this->auditLogger->log($request, $actor, AuditLog::ACTION_CALENDAR_EXCEPTION_CREATED, [
            'exception_id' => $exception->holiday_id,
            'label'        => $exception->label,
            'date'         => $exception->date->toDateString(),
            'end_date'     => $exception->end_date->toDateString(),
        ]);

        CalendarChangeNotifier::holiday($exception, 'added');

        return $exception;
    }

    public function update(BusinessCalendarHoliday $exception, array $validated, SystemUser $actor, Request $request): BusinessCalendarHoliday
    {
        $wasSingleDay = $exception->date->equalTo($exception->end_date);

        $date = $validated['date'] ?? $exception->date->toDateString();

        // IMPORTANT: use array_key_exists, not ?? / isset. The request can
        // send end_date as an explicit null to mean "collapse this back to
        // a single day" — that's a real, meaningful value, distinct from
        // end_date simply being absent from the request entirely (meaning
        // "leave it alone"). ?? / isset can't tell those two cases apart
        // (both look like "no value"), so they'd silently keep the old
        // end_date even when the caller explicitly asked to clear it —
        // a no-op PATCH that still reports success because nothing about
        // the write itself failed. See CalendarOverrideService::update()'s
        // identical handling of effective_until for the same reasoning.
        if (array_key_exists('end_date', $validated)) {
            $endDate = $validated['end_date'] ?? $date; // explicit null -> single day
        } else {
            $endDate = $wasSingleDay ? $date : $exception->end_date->toDateString();
        }

        if ($endDate < $date) {
            throw ValidationException::withMessages([
                'end_date' => 'End date can\'t be before the (possibly newly updated) start date.',
            ]);
        }

        // Same array_key_exists reasoning as end_date above: an explicit
        // closed_from_time: null in the request is a real, meaningful
        // value — "clear the cutoff, go back to a full-day closure" —
        // distinct from the key being absent entirely ("leave it alone").
        $closedFromTime = array_key_exists('closed_from_time', $validated)
            ? $validated['closed_from_time']
            : $exception->closed_from_time;

        $this->assertNoOverlap($exception->calendar_id, $date, $endDate, excludeId: $exception->holiday_id);
        $this->assertCutoffAfterOpening($exception->calendar_id, $date, $closedFromTime);

        $exception->update(array_merge($validated, [
            'date'             => $date,
            'end_date'         => $endDate,
            'closed_from_time' => $closedFromTime,
        ]));

        $this->auditLogger->log($request, $actor, AuditLog::ACTION_CALENDAR_EXCEPTION_UPDATED, [
            'exception_id' => $exception->holiday_id,
            'label'        => $exception->label,
        ]);

        CalendarChangeNotifier::holiday($exception->refresh(), 'updated');

        return $exception;
    }

    public function delete(BusinessCalendarHoliday $exception, SystemUser $actor, Request $request): void
    {
        DB::transaction(function () use ($exception, $actor, $request) {
            $this->auditLogger->log($request, $actor, AuditLog::ACTION_CALENDAR_EXCEPTION_DELETED, [
                'exception_id' => $exception->holiday_id,
                'label'        => $exception->label,
                'date'         => $exception->date->toDateString(),
                'end_date'     => $exception->end_date->toDateString(),
            ]);

            $exception->delete();
        });

        CalendarChangeNotifier::holiday($exception, 'removed');
    }

    /**
     * Two exceptions on the same calendar shouldn't cover the same date —
     * an overlap is almost certainly a duplicate entry (someone declaring
     * "Aug 14 fumigation" twice) rather than an intentional stack, and
     * silently allowing it would make upcomingClosures() show the same day
     * twice with two different labels. Caught as a normal 422 validation
     * error, same shape as any other form-validation failure.
     */
    private function assertNoOverlap(int $calendarId, string $date, string $endDate, ?int $excludeId = null): void
    {
        $overlap = BusinessCalendarHoliday::where('calendar_id', $calendarId)
            ->where('enabled', true)
            ->where('date', '<=', $endDate)
            ->where('end_date', '>=', $date)
            ->when($excludeId, fn ($query) => $query->where('holiday_id', '!=', $excludeId))
            ->exists();

        if ($overlap) {
            throw ValidationException::withMessages([
                'date' => 'This date range overlaps an existing closure already declared for this calendar.',
            ]);
        }
    }

    /**
     * A closed_from_time at or before the calendar's normal opening time
     * for that weekday isn't a *partial* closure at all — it's a full-day
     * one that should just be entered without a cutoff. Lives here rather
     * than in the FormRequest because it needs the calendar's weekly_hours
     * to know what "normal opening" even means for this date, which a
     * FormRequest has no business reaching into.
     */
    private function assertCutoffAfterOpening(int $calendarId, string $date, ?string $closedFromTime): void
    {
        if ($closedFromTime === null) {
            return;
        }

        $calendar = BusinessCalendar::find($calendarId) ?? $this->calendarService->defaultCalendar();
        $weekday = strtolower(Carbon::parse($date)->format('l'));
        $normalOpen = $calendar->weekly_hours[$weekday]['open'] ?? null;

        // No normal opening at all on this weekday (e.g. a closure
        // declared on what's already a non-working day per weekly_hours)
        // — there's no "normal opening" to be early relative to, so
        // there's nothing meaningful to validate here.
        if ($normalOpen === null) {
            return;
        }

        // Both are 'H:i'-shaped strings ("15:00" vs "08:00"), so a plain
        // string comparison is a safe, timezone-free stand-in for a real
        // time comparison — no Carbon instant needed for this check.
        if ($closedFromTime <= $normalOpen) {
            throw ValidationException::withMessages([
                'closed_from_time' => 'Closes-early time must be after this day\'s normal opening time — otherwise it\'s a full-day closure, not a partial one.',
            ]);
        }
    }
}