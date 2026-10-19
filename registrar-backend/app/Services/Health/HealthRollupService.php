<?php

namespace App\Services\Health;

use App\Models\AuditLog;
use App\Models\SecurityEvent;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates one calendar day of operational signals into
 * health_daily_metrics (counts only — no names, emails or OR numbers).
 *
 * IDEMPOTENT: a day's rows are replaced inside one transaction, so running
 * it twice, or recomputing late-arriving data, always converges on the same
 * result. Safe to run for any past date (health:backfill).
 *
 * ZERO ROWS ARE WRITTEN ON PURPOSE for the headline series (attempts,
 * failures, per-system provisioning failures, new labels). HealthDetection-
 * Service treats "first day with any row for a source" as the start of
 * history, so writing zeros for quiet days is what lets a flat-zero
 * baseline be recognised as real history rather than missing data.
 *
 * COST: one date-bounded read per source. audit_logs is read with the
 * action + created_at range only (the JSON metadata is decoded in PHP, which
 * keeps this portable across MySQL / SQLite / Postgres). (verify) that
 * audit_logs has an index covering (action, created_at).
 *
 * Not counted as verification attempts:
 *   - method = admin_override rows (an admin confirmed a real receipt; no
 *     lookup happened) — tracked separately as cashier/overrides;
 *   - is_mock rows (no real payment context, always "valid").
 */
class HealthRollupService
{
    private const CHUNK = 1000;

    /** @return int number of metric rows written for the day */
    public function rollupDate(CarbonInterface $date): int
    {
        $day   = CarbonImmutable::parse($date)->startOfDay();
        $start = $day;
        $end   = $day->addDay();
        $now   = now();

        $rows = array_merge(
            $this->cashier($start, $end),
            $this->diagnosisCodes($start, $end),
            $this->provisioning($start, $end),
            $this->labels($start, $end),
        );

        $dayStr  = $day->toDateString();
        $payload = array_map(fn (array $r) => [
            'metric_date' => $dayStr,
            'source'      => $r[0],
            'metric'      => $r[1],
            'dimension'   => $r[2],
            'count'       => $r[3],
            'computed_at' => $now,
        ], $rows);

        DB::transaction(function () use ($dayStr, $payload) {
            DB::table('health_daily_metrics')->where('metric_date', $dayStr)->delete();

            foreach (array_chunk($payload, 200) as $chunk) {
                DB::table('health_daily_metrics')->upsert(
                    $chunk,
                    ['metric_date', 'source', 'metric', 'dimension'],
                    ['count', 'computed_at'],
                );
            }
        });

        return count($payload);
    }

    /** @return list<array{0:string,1:string,2:string,3:int}> */
    private function cashier(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $attempts  = 0;
        $failures  = 0;
        $overrides = 0;
        $byReason  = [];

        $this->eachMetadata(AuditLog::ACTION_CASHIER_VERIFICATION, $start, $end,
            function (array $m) use (&$attempts, &$failures, &$overrides, &$byReason) {
                if (($m['method'] ?? null) === 'admin_override') {
                    $overrides++;

                    return;
                }

                if (!empty($m['is_mock'])) {
                    return;
                }

                $attempts++;

                // Rows written before the failure_reason field existed
                // (Phase 2b) are bucketed as UNKNOWN rather than guessed.
                if (array_key_exists('final_approved', $m) && !$m['final_approved']) {
                    $failures++;
                    $reason            = $this->dimension($m['failure_reason'] ?? null) ?: 'UNKNOWN';
                    $byReason[$reason] = ($byReason[$reason] ?? 0) + 1;
                }
            });

        $out = [
            ['cashier', 'attempts', '', $attempts],
            ['cashier', 'failures', '', $failures],
            ['cashier', 'overrides', '', $overrides],
        ];

        foreach ($byReason as $reason => $n) {
            $out[] = ['cashier', 'failures_by_reason', (string) $reason, $n];
        }

        return $out;
    }

    /** @return list<array{0:string,1:string,2:string,3:int}> */
    private function diagnosisCodes(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $counts = [];

        $this->eachMetadata(AuditLog::ACTION_CASHIER_VERIFICATION_ENRICHED, $start, $end,
            function (array $m) use (&$counts) {
                $seen = [];
                foreach ((array) ($m['diagnosis_codes'] ?? []) as $c) {
                    // Accept both ['CODE', ...] and [['code' => 'CODE'], ...].
                    $code = $this->dimension(is_array($c) ? ($c['code'] ?? null) : $c);
                    if ($code !== '' && !isset($seen[$code])) {
                        $seen[$code]   = true;
                        $counts[$code] = ($counts[$code] ?? 0) + 1;
                    }
                }
            });

        $out = [];
        foreach ($counts as $code => $n) {
            $out[] = ['cashier', 'diagnosis_code', (string) $code, $n];
        }

        return $out;
    }

    /** @return list<array{0:string,1:string,2:string,3:int}> */
    private function provisioning(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $byReason = DB::table('security_events')
            ->where('event_type', SecurityEvent::EVENT_TYPE_PROVISIONING_FAILED)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->selectRaw('reason, COUNT(*) as n')
            ->groupBy('reason')
            ->pluck('n', 'reason');

        $bySystem = [SecurityEvent::SYSTEM_OGOS => 0, SecurityEvent::SYSTEM_PUPTAPS => 0];
        $out      = [];

        foreach ($byReason as $reason => $n) {
            $system            = SecurityEvent::PROVISIONING_REASON_SYSTEM[$reason] ?? 'other';
            $bySystem[$system] = ($bySystem[$system] ?? 0) + (int) $n;
            $out[]             = ['provisioning', 'failures_by_reason', $this->dimension($reason), (int) $n];
        }

        foreach ($bySystem as $system => $n) {
            $out[] = ['provisioning', 'failures', $system, $n];
        }

        return $out;
    }

    /** @return list<array{0:string,1:string,2:string,3:int}> */
    private function labels(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $n = DB::table('unmatched_cashier_items')
            ->where('first_seen_at', '>=', $start)
            ->where('first_seen_at', '<', $end)
            ->count();

        return [['labels', 'new_labels', '', $n]];
    }

    /**
     * Streams one action's rows for [start, end) and hands each decoded
     * metadata array to $fn. Chunked by primary key so memory stays flat.
     */
    private function eachMetadata(string $action, CarbonImmutable $start, CarbonImmutable $end, callable $fn): void
    {
        DB::table('audit_logs')
            ->where('action', $action)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->select(['id', 'metadata'])
            ->chunkById(self::CHUNK, function ($chunk) use ($fn) {
                foreach ($chunk as $row) {
                    $m = is_array($row->metadata) ? $row->metadata : json_decode((string) $row->metadata, true);
                    $fn(is_array($m) ? $m : []);
                }
            }, 'id');
    }

    /** Normalise a free value into a safe, bounded dimension string. */
    private function dimension(mixed $v): string
    {
        return is_string($v) || is_int($v) ? mb_substr(trim((string) $v), 0, 100) : '';
    }
}
