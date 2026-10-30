<?php

namespace App\Services\Tickets;

use App\Enums\TicketSenderTypeEnum;
use App\Enums\TicketStatusEnum;
use App\Models\Ticket;
use App\Models\TicketEvent;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Support Ticketing — flags tickets that are at risk of, or have breached, an
 * SLA target. Run on a schedule by tickets:sla-sweep.
 *
 * Idempotent by construction
 *   Each flag is a nullable timestamp (see the add_sla_columns migration) set
 *   exactly once. The scan only selects tickets whose flag is still empty, and
 *   every ticket is re-checked under its row lock before it is written, so:
 *     - running the sweep twice flags nothing the second time;
 *     - two overlapping runs cannot both flag (and log) the same ticket;
 *     - a ticket resolved, answered or paused between the scan and the check is
 *       left alone.
 *   Each flag writes one event (sla_at_risk / sla_breached, actor System) into
 *   the ticket's hash chain. It does not touch last_activity_at: a machine
 *   noticing a deadline is not activity on the ticket.
 *
 * What is measured
 *   first_response  from creation until the first staff reply
 *   resolution      from creation (or the last return) until Resolved
 *   A ticket waiting on the requester (Pending Client, sla_paused_at set) is
 *   skipped: its clock is stopped. Resolved and Closed tickets are never
 *   scanned.
 *
 * A breach also sets the at-risk flag (silently) when it was never set, so a
 * ticket cannot be "at risk" after it has already breached.
 */
final class TicketSlaMonitor
{
    /** target => [due column, at-risk flag, breached flag] */
    private const TARGETS = [
        Ticket::SLA_FIRST_RESPONSE => ['first_response_due_at', 'first_response_at_risk_at', 'first_response_breached_at'],
        Ticket::SLA_RESOLUTION     => ['resolution_due_at', 'resolution_at_risk_at', 'resolution_breached_at'],
    ];

    public function __construct(
        private readonly SlaService $sla,
        private readonly TicketEventRecorder $events,
    ) {
    }

    /**
     * @return array{at_risk:int, breached:int}  how many NEW flags this run set
     */
    public function run(?CarbonInterface $now = null): array
    {
        // Eloquent writes a Carbon's wall-clock time as given and ignores its
        // zone, so put it in the application zone before it is stored.
        $now = Carbon::instance($now ?? now())->setTimezone(config('app.timezone'));
        $result = ['at_risk' => 0, 'breached' => 0];

        foreach (array_keys(self::TARGETS) as $target) {
            $this->candidates($target)->chunkById(
                max(1, (int) config('tickets.sweep_chunk_size')),
                function ($tickets) use ($target, $now, &$result) {
                    foreach ($tickets as $ticket) {
                        $outcome = $this->check($ticket->getKey(), $target, $now);

                        if ($outcome !== null) {
                            $result[$outcome]++;
                        }
                    }
                },
            );
        }

        return $result;
    }

    /** Active, running (not paused) tickets with a due date and no breach recorded yet. */
    private function candidates(string $target)
    {
        [$due, , $breached] = self::TARGETS[$target];

        $query = Ticket::query()
            ->whereIn('status', TicketStatusEnum::activeValues())
            ->whereNull('sla_paused_at')
            ->whereNotNull($due)
            ->whereNull($breached);

        if ($target === Ticket::SLA_FIRST_RESPONSE) {
            $query->whereNull('first_responded_at');
        }

        return $query;
    }

    /**
     * Decide and (maybe) flag one ticket under its lock.
     *
     * @return 'at_risk'|'breached'|null
     */
    private function check(int|string $ticketId, string $target, CarbonInterface $now): ?string
    {
        [$due, $atRiskColumn, $breachedColumn] = self::TARGETS[$target];

        return DB::transaction(function () use ($ticketId, $target, $now, $due, $atRiskColumn, $breachedColumn) {
            $ticket = Ticket::query()->whereKey($ticketId)->lockForUpdate()->first();

            // Re-check everything the scan assumed: it may be stale by now.
            if ($ticket === null
                || ! $ticket->status->isActive()
                || $ticket->sla_paused_at !== null
                || $ticket->{$due} === null
                || $ticket->{$breachedColumn} !== null
                || ($target === Ticket::SLA_FIRST_RESPONSE && $ticket->first_responded_at !== null)) {
                return null;
            }

            $metadata = ['target' => $target, 'due_at' => $ticket->{$due}->toIso8601String()];

            if ($now->greaterThan($ticket->{$due})) {
                $ticket->forceFill([
                    $breachedColumn => $now,
                    $atRiskColumn   => $ticket->{$atRiskColumn} ?? $now,
                ])->save();

                $this->events->record($ticket, TicketEvent::TYPE_SLA_BREACHED, TicketSenderTypeEnum::System, null, null, null, $metadata);

                return 'breached';
            }

            if ($ticket->{$atRiskColumn} === null && $this->sla->isAtRisk($ticket, $target, $now)) {
                $ticket->forceFill([$atRiskColumn => $now])->save();

                $this->events->record($ticket, TicketEvent::TYPE_SLA_AT_RISK, TicketSenderTypeEnum::System, null, null, null, $metadata);

                return 'at_risk';
            }

            return null;
        });
    }
}
