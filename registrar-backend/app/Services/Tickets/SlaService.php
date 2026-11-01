<?php

namespace App\Services\Tickets;

use App\Enums\TicketPriorityEnum;
use App\Models\Ticket;
use App\Services\BusinessCalendarService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Support Ticketing — the SLA arithmetic.
 *
 * Every deadline is counted in WORKING time on the business calendar
 * (BusinessCalendarService), the same calendar request processing uses, so the
 * ticket clocks follow the same holidays, suspensions and closed days the
 * registrar staff already maintain. The policy's schedule (Tue–Fri 8 AM–8 PM,
 * Monday closed) is not coded here: it is whatever that calendar says. See
 * `php artisan tickets:verify-calendar`.
 *
 * What this class does, and does not do
 *   - It COMPUTES attributes (due dates, pause marker, claim expiry). It never
 *     writes a ticket. TicketService merges these into the same save as the
 *     status change they belong to, so a ticket can never be saved
 *     half-updated, and everything stays inside TicketService's one
 *     transaction and row lock.
 *   - It never lets a calendar problem block the real work. If the calendar
 *     cannot be read (no default calendar, every day closed) the ticket is
 *     still filed and the claim still succeeds: the failure is logged loudly
 *     and the affected deadline is simply null (shown as "no SLA"). A student
 *     filing a ticket must not fail because of a scheduling table.
 *
 * Semantics (the [DEFAULT]s are marked in config/tickets.php)
 *   Start      The clock starts when the ticket is created. A ticket filed
 *              outside working time starts counting at the next opening.
 *   Pause      Pending Client stops the RESOLUTION clock (the wait is on the
 *              requester). On resume, the resolution deadline moves back by the
 *              working time spent paused. The first-response deadline is never
 *              affected: Pending Client is only reachable after a staff reply.
 *   Restart    A requester sending a Resolved ticket back starts a fresh
 *              resolution window from that moment (the work begins again).
 *              Bounded by the two-returns limit.
 *   Claim      A claim lapses after the configured working hours without the
 *              claimer replying.
 */
final class SlaService
{
    private const SECONDS_PER_MINUTE = 60;

    public function __construct(private readonly BusinessCalendarService $calendar)
    {
    }

    // =====================================================================
    // TARGETS
    // =====================================================================

    /** Working minutes allowed for a first response at this priority. */
    public function firstResponseMinutes(TicketPriorityEnum $priority): int
    {
        return max(1, (int) config('tickets.sla.first_response_hours.' . $priority->value)) * 60;
    }

    /**
     * Working minutes allowed for resolution at this priority. The policy
     * counts "operating days"; an operating day is operating_day_hours long.
     */
    public function resolutionMinutes(TicketPriorityEnum $priority): int
    {
        $days  = max(1, (int) config('tickets.sla.resolution_days.' . $priority->value));
        $hours = max(1, (int) config('tickets.sla.operating_day_hours'));

        return $days * $hours * 60;
    }

    // =====================================================================
    // ATTRIBUTE BUILDERS (merged into TicketService's own saves)
    // =====================================================================

    /**
     * Due dates for a brand-new ticket.
     *
     * @return array<string,CarbonInterface|null>
     */
    public function startAttributes(TicketPriorityEnum $priority, CarbonInterface $from): array
    {
        return [
            'first_response_due_at' => $this->due($from, $this->firstResponseMinutes($priority)),
            'resolution_due_at'    => $this->due($from, $this->resolutionMinutes($priority)),
            'sla_paused_at'        => null,
        ];
    }

    /**
     * The ticket starts waiting on the requester.
     *
     * @return array<string,CarbonInterface>
     */
    public function pauseAttributes(CarbonInterface $now): array
    {
        return ['sla_paused_at' => $this->toStorage($now)];
    }

    /**
     * The requester answered: resume. The resolution deadline moves back by the
     * working minutes spent paused. A ticket that was claimed also gets a fresh
     * claim window, because the claimer could not have acted while waiting.
     *
     * Safe to call for a ticket that is not paused (returns the no-op shape).
     *
     * @return array<string,CarbonInterface|null>
     */
    public function resumeAttributes(Ticket $ticket, CarbonInterface $now): array
    {
        $attributes = ['sla_paused_at' => null];

        if ($ticket->sla_paused_at !== null && $ticket->resolution_due_at !== null) {
            $pausedMinutes = $this->safely(
                fn () => $this->calendar->minutesBetween($ticket->sla_paused_at, $now, $this->calendarId()),
                'resume',
            );

            // If the arithmetic failed, leave the deadline where it was (the
            // requester is not penalised, staff just keep the original date).
            if ($pausedMinutes !== null) {
                $attributes['resolution_due_at'] = $pausedMinutes > 0
                    ? ($this->due($ticket->resolution_due_at, $pausedMinutes) ?? $ticket->resolution_due_at)
                    : $ticket->resolution_due_at;
            }
        }

        if ($ticket->claimed_by_user_id !== null) {
            $expiry = $this->claimExpiresAt($now);
            if ($expiry !== null) {
                $attributes['claim_expires_at'] = $expiry;
            }
        }

        return $attributes;
    }

    /**
     * A Resolved ticket was sent back: open a fresh resolution window and clear
     * its resolution flags so the sweep can flag it again if it slips again.
     *
     * @return array<string,CarbonInterface|null>
     */
    public function restartAttributes(TicketPriorityEnum $priority, CarbonInterface $now): array
    {
        return [
            'resolution_due_at'      => $this->due($now, $this->resolutionMinutes($priority)),
            'resolution_at_risk_at'  => null,
            'resolution_breached_at' => null,
            'sla_paused_at'          => null,
        ];
    }

    /**
     * When a claim taken (or renewed) at $from lapses; null when the calendar
     * cannot say, in which case the claim simply does not expire by itself.
     */
    public function claimExpiresAt(CarbonInterface $from): ?CarbonInterface
    {
        $hours = (int) config('tickets.claim_timeout_working_hours');

        return $hours > 0 ? $this->due($from, $hours * 60) : null;
    }

    // =====================================================================
    // AT-RISK TEST (the one question that needs the calendar again)
    // =====================================================================

    /**
     * Whether the ticket has used up at least `at_risk_ratio` of the window for
     * $target without meeting it: working time left to the due date is no more
     * than (1 - ratio) of the window. Used only by the sweep.
     *
     * Returns false when it cannot tell (no due date, calendar unreadable).
     *
     * @param  Ticket::SLA_FIRST_RESPONSE|Ticket::SLA_RESOLUTION  $target
     */
    public function isAtRisk(Ticket $ticket, string $target, CarbonInterface $now): bool
    {
        $first  = $target === Ticket::SLA_FIRST_RESPONSE;
        $due    = $first ? $ticket->first_response_due_at : $ticket->resolution_due_at;
        $window = $first ? $this->firstResponseMinutes($ticket->priority) : $this->resolutionMinutes($ticket->priority);

        if ($due === null || $now->greaterThan($due)) {
            return false;
        }

        $remaining = $this->safely(
            fn () => $this->calendar->minutesBetween($now, $due, $this->calendarId()),
            'at-risk check',
        );

        if ($remaining === null) {
            return false;
        }

        $ratio = min(1.0, max(0.0, (float) config('tickets.sla.at_risk_ratio')));

        return $remaining <= $window * (1 - $ratio);
    }

    // =====================================================================
    // INTERNALS
    // =====================================================================

    /** `$minutes` of working time after $from, as a storable instant; null when the calendar cannot say. */
    private function due(CarbonInterface $from, int $minutes): ?CarbonInterface
    {
        $at = $this->safely(
            fn () => $this->calendar->addBusinessMinutes($from, $minutes, $this->calendarId()),
            'due date',
        );

        return $at === null ? null : $this->toStorage($at);
    }

    private function calendarId(): ?int
    {
        $id = config('tickets.sla.calendar_id');

        return $id === null ? null : (int) $id;
    }

    /**
     * The calendar speaks in config('app.display_timezone'); Eloquent writes a
     * Carbon's wall-clock time as given, ignoring its zone. Convert to the
     * application zone first or every stored deadline would be off by the
     * timezone offset.
     */
    private function toStorage(CarbonInterface $at): CarbonInterface
    {
        return Carbon::instance($at)->setTimezone(config('app.timezone'));
    }

    /**
     * Run a calendar calculation; on any failure log it and return null instead
     * of letting a scheduling problem break filing, claiming or replying.
     *
     * @template T
     * @param  callable():T  $callback
     * @return T|null
     */
    private function safely(callable $callback, string $what): mixed
    {
        try {
            return $callback();
        } catch (Throwable $e) {
            Log::error("tickets.sla: could not compute the {$what}; the SLA is skipped for this action.", [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
