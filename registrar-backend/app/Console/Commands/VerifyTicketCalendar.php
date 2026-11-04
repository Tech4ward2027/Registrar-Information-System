<?php

namespace App\Console\Commands;

use App\Models\BusinessCalendar;
use App\Models\BusinessCalendarOverride;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Support Ticketing — Phase 5. Read-only check that the business calendar the
 * ticket clocks run on matches the policy schedule: Tuesday to Friday,
 * 8 AM–8 PM, closed Monday, Saturday and Sunday.
 *
 * It deliberately changes nothing. The calendar is shared with request
 * processing and is edited by the registrar staff through the Calendar
 * Overrides screen, which writes the audit log; a CLI edit would bypass that and
 * would silently change the request clocks too. When something differs, the
 * output says exactly what to change there (usually: a recurring "closed every
 * Monday" override).
 *
 * Dated holidays and suspensions are not part of the weekly pattern and are
 * ignored here. Exit code 0 = matches, 1 = differs or no calendar. Safe to run
 * in a deploy script.
 */
class VerifyTicketCalendar extends Command
{
    /** day => [open, close] or null for closed — the approved schedule. */
    private const POLICY = [
        'monday'    => null,
        'tuesday'   => ['08:00', '20:00'],
        'wednesday' => ['08:00', '20:00'],
        'thursday'  => ['08:00', '20:00'],
        'friday'    => ['08:00', '20:00'],
        'saturday'  => null,
        'sunday'    => null,
    ];

    protected $signature   = 'tickets:verify-calendar';
    protected $description = 'Check the business calendar used for ticket SLAs matches the Tue–Fri 8 AM–8 PM policy';

    public function handle(): int
    {
        $id       = config('tickets.sla.calendar_id');
        $calendar = $id !== null
            ? BusinessCalendar::find((int) $id)
            : BusinessCalendar::where('is_default', true)->first();

        if ($calendar === null) {
            $this->error($id !== null
                ? "No business calendar with id {$id} (TICKETS_SLA_CALENDAR_ID)."
                : 'There is no default business calendar. Ticket deadlines cannot be calculated.');

            return self::FAILURE;
        }

        $this->line("Calendar: {$calendar->name} (id {$calendar->calendar_id})");

        $problems = [];
        $today    = Carbon::now(config('app.display_timezone', 'Asia/Manila'))->startOfDay();

        // Today and the six days after it cover every weekday once. Dated
        // exceptions are not consulted: only the recurring weekly pattern.
        for ($i = 0; $i < 7; $i++) {
            $date = $today->copy()->addDays($i);
            $day  = strtolower($date->format('l'));

            $actual   = $this->effectiveHours($calendar, $day, $date);
            $expected = self::POLICY[$day];

            if ($actual !== $expected) {
                $problems[] = sprintf(
                    '%-9s is %s, policy says %s.%s',
                    ucfirst($day), $this->describe($actual), $this->describe($expected), $this->fixHint($day, $expected),
                );
            }
        }

        $dayHours = (int) config('tickets.sla.operating_day_hours');
        if ($dayHours !== 12) {
            $problems[] = "TICKETS_SLA_OPERATING_DAY_HOURS is {$dayHours} but the policy day (8 AM–8 PM) is 12 hours; resolution deadlines are counted in these hours.";
        }

        if ($problems === []) {
            $this->info('OK: the calendar matches the Tue–Fri 8 AM–8 PM policy schedule.');

            return self::SUCCESS;
        }

        $this->warn('The calendar does not match the ticket policy schedule:');
        foreach ($problems as $problem) {
            $this->line('  - ' . $problem);
        }
        $this->line('Nothing was changed. Edit it in the admin Calendar screens so the change is audited and the registrar team owns it.');

        return self::FAILURE;
    }

    /**
     * The open window for that weekday after the weekly baseline and any
     * enabled recurring override, as ['HH:MM', 'HH:MM'] or null when closed.
     */
    private function effectiveHours(BusinessCalendar $calendar, string $day, Carbon $date): ?array
    {
        $closedByOverride = BusinessCalendarOverride::query()
            ->where('calendar_id', $calendar->calendar_id)
            ->where('enabled', true)
            ->where('is_closed', true)
            ->where('day_of_week', $day)
            ->get()
            ->contains(fn (BusinessCalendarOverride $o) => $o->appliesTo($date));

        $hours = $calendar->weekly_hours[$day] ?? null;

        if ($closedByOverride || ! $hours || empty($hours['open']) || empty($hours['close'])) {
            return null;
        }

        return [$hours['open'], $hours['close']];
    }

    private function describe(?array $hours): string
    {
        return $hours === null ? 'closed' : "open {$hours[0]}–{$hours[1]}";
    }

    private function fixHint(string $day, ?array $expected): string
    {
        return $expected === null
            ? " Add a recurring override \"closed every {$day}, until further notice\" in Calendar Overrides."
            : ' Remove the override closing it, or correct the calendar hours.';
    }
}
