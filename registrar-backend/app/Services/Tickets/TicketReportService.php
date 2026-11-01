<?php

namespace App\Services\Tickets;

use App\Enums\TicketStatusEnum;
use App\Models\Ticket;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Support Ticketing — Phase 8. Read-only reporting: the SLA and volume summary,
 * and the rows of the audited log export.
 *
 * Dates. Reports take Manila calendar dates, both ends included, because that
 * is how the registrar thinks about a day. Timestamps are stored in UTC, so a
 * range is turned into [start of the first day, start of the day AFTER the last)
 * in Manila time and then converted to UTC for the queries.
 *
 * SLA numbers read the flag columns the SLA sweep sets (Phase 5)
 * instead of recomputing deadlines with the calendar, so a summary is a handful
 * of indexed aggregates however many tickets there are. A target counts as
 * "breached" if the sweep flagged it OR it was completed after its due time (the
 * sweep runs every 15 minutes, so a late answer can land before it notices).
 * One caveat: when a requester sends a Resolved ticket back, its resolution
 * deadline is restarted, so the summary judges the CURRENT deadline; the
 * original is only in the event log.
 */
final class TicketReportService
{
    /**
     * @return array{0:CarbonImmutable,1:CarbonImmutable} [start, end) in UTC
     */
    public function bounds(string $fromDate, string $toDate): array
    {
        $tz = $this->tz();

        return [
            CarbonImmutable::parse($fromDate, $tz)->startOfDay()->utc(),
            CarbonImmutable::parse($toDate, $tz)->addDay()->startOfDay()->utc(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function summary(string $fromDate, string $toDate): array
    {
        [$start, $end] = $this->bounds($fromDate, $toDate);

        $created = fn () => Ticket::query()->where('tickets.created_at', '>=', $start)->where('tickets.created_at', '<', $end);

        return [
            'range' => ['from' => $fromDate, 'to' => $toDate, 'timezone' => $this->tz()],
            'volume' => [
                'created'     => $created()->count(),
                'by_category' => $created()
                    ->join('ticket_categories', 'ticket_categories.id', '=', 'tickets.category_id')
                    ->groupBy('ticket_categories.code', 'ticket_categories.name', 'ticket_categories.sort_order')
                    ->orderBy('ticket_categories.sort_order')
                    ->selectRaw('ticket_categories.code as code, ticket_categories.name as name, count(*) as total')
                    ->get()
                    ->map(fn ($r) => ['code' => $r->code, 'name' => $r->name, 'total' => (int) $r->total])
                    ->all(),
                'by_priority' => $this->countBy($created(), 'tickets.priority'),
                'by_status'   => $this->countBy($created(), 'tickets.status'),
                'per_day'     => $this->perDay($start, $end),
                'returned'    => $created()->where('tickets.requester_returns', '>', 0)->count(),
                'closed'      => $this->closedInRange($start, $end),
            ],
            'sla' => $this->sla($start, $end),
            'queue_now' => $this->queueNow(),
        ];
    }

    /** Number of events in the range: what the export will contain (at least; more may arrive while it streams). */
    public function exportCount(string $fromDate, string $toDate): int
    {
        [$start, $end] = $this->bounds($fromDate, $toDate);

        return DB::table('ticket_events')->where('created_at', '>=', $start)->where('created_at', '<', $end)->count();
    }

    /**
     * The ticket events whose timestamp falls in the range, for the export.
     * Deliberately holds NO message text, no resolution note and nothing about
     * the requester: ticket number, category, what happened, who (staff id or
     * "requester"/"system"), and the chain hash so the file can be checked
     * against the database later.
     *
     * @return \Generator<int,array<int,scalar|null>>  CSV rows, oldest first
     */
    public function exportRows(string $fromDate, string $toDate, int $chunk): \Generator
    {
        [$start, $end] = $this->bounds($fromDate, $toDate);
        $chunk   = max(1, $chunk);
        $afterId = 0;

        while (true) {
            $rows = DB::table('ticket_events as e')
                ->join('tickets as t', 't.id', '=', 'e.ticket_id')
                ->join('ticket_categories as c', 'c.id', '=', 't.category_id')
                ->where('e.created_at', '>=', $start)
                ->where('e.created_at', '<', $end)
                ->where('e.id', '>', $afterId)
                ->orderBy('e.id')
                ->limit($chunk)
                ->get([
                    'e.id', 't.ticket_number', 'c.code as category', 'e.seq', 'e.event_type', 'e.actor_type',
                    'e.actor_user_id', 'e.from_status', 'e.to_status', 'e.created_at', 'e.hash',
                ]);

            if ($rows->isEmpty()) {
                return;
            }

            foreach ($rows as $r) {
                yield [
                    $r->ticket_number, $r->category, $r->seq, $r->event_type, $r->actor_type,
                    $r->actor_user_id, $r->from_status, $r->to_status,
                    CarbonImmutable::parse($r->created_at, 'UTC')->setTimezone($this->tz())->toIso8601String(),
                    $r->hash,
                ];
            }

            $afterId = (int) $rows->last()->id;
        }
    }

    /** @return list<string> */
    public static function exportHeader(): array
    {
        return ['ticket_number', 'category', 'seq', 'event_type', 'actor_type', 'actor_user_id', 'from_status', 'to_status', 'created_at', 'hash'];
    }

    // ── Pieces ───────────────────────────────────────────────────────────

    /** @return array<string,int> */
    private function countBy($query, string $column): array
    {
        return $query->groupBy($column)->selectRaw("{$column} as k, count(*) as total")->pluck('total', 'k')
            ->map(fn ($n) => (int) $n)->all();
    }

    /**
     * Tickets filed per Manila day, every day of the range present (zero
     * included) so a chart needs no gap filling. Bucketed in PHP rather than SQL
     * because "which Manila day is this UTC instant" differs between MySQL and
     * SQLite; only the timestamp column is read.
     *
     * @return list<array{date:string,total:int}>
     */
    private function perDay(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $tz     = $this->tz();
        $counts = [];

        for ($d = $start->setTimezone($tz); $d->lt($end); $d = $d->addDay()) {
            $counts[$d->format('Y-m-d')] = 0;
        }

        foreach (Ticket::query()->where('created_at', '>=', $start)->where('created_at', '<', $end)->select('created_at')->toBase()->cursor() as $row) {
            $day = CarbonImmutable::parse($row->created_at, 'UTC')->setTimezone($tz)->format('Y-m-d');
            $counts[$day] = ($counts[$day] ?? 0) + 1;
        }

        return array_map(fn ($date, $total) => ['date' => $date, 'total' => $total], array_keys($counts), array_values($counts));
    }

    /** Tickets closed in the range, by how they closed. */
    private function closedInRange(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $by = Ticket::query()
            ->where('closed_at', '>=', $start)->where('closed_at', '<', $end)
            ->groupBy('close_reason')->selectRaw('close_reason as k, count(*) as total')->pluck('total', 'k');

        return [
            'confirmed' => (int) ($by[Ticket::CLOSE_REASON_CONFIRMED] ?? 0),
            'auto'      => (int) ($by[Ticket::CLOSE_REASON_AUTO] ?? 0),
        ];
    }

    /**
     * @return array{first_response:array<string,int|float|null>,resolution:array<string,int|float|null>}
     */
    private function sla(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $row = Ticket::query()
            ->where('created_at', '>=', $start)->where('created_at', '<', $end)
            ->toBase()
            ->selectRaw(
                'SUM(CASE WHEN first_response_due_at IS NOT NULL THEN 1 ELSE 0 END) AS fr_tracked, '
                . 'SUM(CASE WHEN first_response_due_at IS NOT NULL AND (first_response_breached_at IS NOT NULL OR first_responded_at > first_response_due_at) THEN 1 ELSE 0 END) AS fr_breached, '
                . 'SUM(CASE WHEN first_response_due_at IS NOT NULL AND first_response_breached_at IS NULL AND first_responded_at IS NOT NULL AND first_responded_at <= first_response_due_at THEN 1 ELSE 0 END) AS fr_met, '
                . 'SUM(CASE WHEN first_response_at_risk_at IS NOT NULL THEN 1 ELSE 0 END) AS fr_at_risk, '
                . 'SUM(CASE WHEN resolution_due_at IS NOT NULL THEN 1 ELSE 0 END) AS rs_tracked, '
                . 'SUM(CASE WHEN resolution_due_at IS NOT NULL AND (resolution_breached_at IS NOT NULL OR resolved_at > resolution_due_at) THEN 1 ELSE 0 END) AS rs_breached, '
                . "SUM(CASE WHEN resolution_due_at IS NOT NULL AND resolution_breached_at IS NULL AND resolved_at IS NOT NULL AND resolved_at <= resolution_due_at AND status IN ('resolved', 'closed') THEN 1 ELSE 0 END) AS rs_met, "
                . 'SUM(CASE WHEN resolution_at_risk_at IS NOT NULL THEN 1 ELSE 0 END) AS rs_at_risk'
            )
            ->first();

        return [
            'first_response' => $this->slaBlock((int) $row->fr_tracked, (int) $row->fr_met, (int) $row->fr_breached, (int) $row->fr_at_risk),
            'resolution'     => $this->slaBlock((int) $row->rs_tracked, (int) $row->rs_met, (int) $row->rs_breached, (int) $row->rs_at_risk),
        ];
    }

    /**
     * tracked  tickets that have a due date for this target
     * met      done on time
     * breached missed (flagged, or done late)
     * pending  neither yet (still within time, or paused)
     * at_risk_flagged  ever flagged "at risk" (a ticket can be both this and met)
     * compliance_pct   met / (met + breached), null when nothing has finished
     */
    private function slaBlock(int $tracked, int $met, int $breached, int $atRisk): array
    {
        $done = $met + $breached;

        return [
            'tracked'         => $tracked,
            'met'             => $met,
            'breached'        => $breached,
            'pending'         => max(0, $tracked - $done),
            'at_risk_flagged' => $atRisk,
            'compliance_pct'  => $done > 0 ? round($met / $done * 100, 1) : null,
        ];
    }

    /**
     * The queue as it is right now, whatever the date range: what is waiting
     * for staff and how long the oldest unclaimed ticket has waited.
     *
     * @return array<string,int|string|null>
     */
    private function queueNow(): array
    {
        $active = TicketStatusEnum::activeValues();

        $unclaimed = Ticket::query()->whereIn('status', $active)->whereNull('claimed_by_user_id');

        return [
            'unclaimed'         => (clone $unclaimed)->count(),
            'claimed'           => Ticket::query()->whereIn('status', $active)->whereNotNull('claimed_by_user_id')->count(),
            'pending_client'    => Ticket::query()->where('status', TicketStatusEnum::PendingClient->value)->count(),
            'resolved_waiting'  => Ticket::query()->where('status', TicketStatusEnum::Resolved->value)->count(),
            'oldest_unclaimed_at' => ($t = (clone $unclaimed)->min('created_at'))
                ? CarbonImmutable::parse($t, 'UTC')->setTimezone($this->tz())->toIso8601String()
                : null,
        ];
    }

    private function tz(): string
    {
        return (string) config('app.display_timezone', 'Asia/Manila');
    }
}
