<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\BusinessCalendarOverride;
use App\Models\SystemUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Owns the business logic for recurring, time-bound calendar overrides —
 * e.g. "closed every Monday, effective <date>, until further notice."
 * See CalendarExceptionService for the sibling one-off-closure service and
 * why the two are kept apart.
 */
class CalendarOverrideService
{
    public function __construct(
        private BusinessCalendarService $calendarService,
        private AuditLogger $auditLogger,
    ) {}

    public function create(array $validated, SystemUser $actor, Request $request): BusinessCalendarOverride
    {
        $calendarId = $validated['calendar_id'] ?? $this->calendarService->defaultCalendar()->calendar_id;

        $this->assertNoOverlap(
            $calendarId,
            $validated['day_of_week'],
            $validated['effective_from'],
            $validated['effective_until'] ?? null,
        );

        $override = BusinessCalendarOverride::create([
            'calendar_id'      => $calendarId,
            'day_of_week'      => $validated['day_of_week'],
            'is_closed'        => $validated['is_closed'] ?? true,
            'label'            => $validated['label'],
            'effective_from'   => $validated['effective_from'],
            'effective_until'  => $validated['effective_until'] ?? null,
        ]);

        $this->auditLogger->log($request, $actor, AuditLog::ACTION_CALENDAR_OVERRIDE_CREATED, [
            'override_id' => $override->override_id,
            'label'       => $override->label,
            'day_of_week' => $override->day_of_week,
        ]);

        CalendarChangeNotifier::override($override, 'added');

        return $override;
    }

    public function update(BusinessCalendarOverride $override, array $validated, SystemUser $actor, Request $request): BusinessCalendarOverride
    {
        $dayOfWeek = $validated['day_of_week'] ?? $override->day_of_week;
        $from = $validated['effective_from'] ?? $override->effective_from->toDateString();
        $until = array_key_exists('effective_until', $validated)
            ? $validated['effective_until']
            : $override->effective_until?->toDateString();

        $this->assertNoOverlap($override->calendar_id, $dayOfWeek, $from, $until, excludeId: $override->override_id);

        $override->update($validated);

        $this->auditLogger->log($request, $actor, AuditLog::ACTION_CALENDAR_OVERRIDE_UPDATED, [
            'override_id' => $override->override_id,
            'label'       => $override->label,
        ]);

        $override->refresh();
        CalendarChangeNotifier::override($override, 'updated');

        return $override;
    }

    public function delete(BusinessCalendarOverride $override, SystemUser $actor, Request $request): void
    {
        DB::transaction(function () use ($override, $actor, $request) {
            $this->auditLogger->log($request, $actor, AuditLog::ACTION_CALENDAR_OVERRIDE_DELETED, [
                'override_id' => $override->override_id,
                'label'       => $override->label,
                'day_of_week' => $override->day_of_week,
            ]);

            $override->delete();
        });

        CalendarChangeNotifier::override($override, 'removed');
    }

    /**
     * Two override rules for the same calendar + weekday with overlapping
     * effective ranges would make BusinessCalendarService::overrideFor()'s
     * match ambiguous (it takes the first result, with no defined
     * ordering guarantee) — block it at write time instead, same reasoning
     * as CalendarExceptionService::assertNoOverlap().
     */
    private function assertNoOverlap(int $calendarId, string $dayOfWeek, string $from, ?string $until, ?int $excludeId = null): void
    {
        $overlap = BusinessCalendarOverride::where('calendar_id', $calendarId)
            ->where('enabled', true)
            ->where('day_of_week', $dayOfWeek)
            ->where('effective_from', '<=', $until ?? '9999-12-31')
            ->where(function ($query) use ($from) {
                $query->whereNull('effective_until')
                    ->orWhere('effective_until', '>=', $from);
            })
            ->when($excludeId, fn ($query) => $query->where('override_id', '!=', $excludeId))
            ->exists();

        if ($overlap) {
            throw ValidationException::withMessages([
                'effective_from' => 'This weekday already has an overlapping override rule active in that date range.',
            ]);
        }
    }
}