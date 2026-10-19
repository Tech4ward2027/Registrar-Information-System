<?php

namespace App\Services\Health;

use App\Models\SystemAlert;
use App\Support\Health\AnomalyDetector;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Loads rollup rows for one evaluated day, runs AnomalyDetector over every
 * watched series, and raises alerts. Reads only health_daily_metrics (counts)
 * — it never touches audit_logs or any personal data.
 *
 * Cost: one range query for the baseline window plus one MIN() per source,
 * regardless of how many series exist.
 */
class HealthDetectionService
{
    public function __construct(
        private readonly AnomalyDetector $detector,
        private readonly SystemAlertService $alerts,
    ) {}

    /** @return array{evaluated:int, raised:int} */
    public function evaluateDate(CarbonInterface $date, bool $notify = true): array
    {
        $day          = CarbonImmutable::parse($date)->startOfDay();
        $dayStr       = $day->toDateString();
        $baselineDays = max(1, (int) config('system_health.baseline_days', 28));
        $from         = $day->subDays($baselineDays);

        $countPairs = (array) config('system_health.count_series', []);
        $rateSeries = (array) config('system_health.rate_series', []);

        $wanted = [];
        foreach ($countPairs as [$s, $m]) {
            $wanted[] = [$s, $m];
        }
        foreach ($rateSeries as $r) {
            $wanted[] = [$r['source'], $r['numerator']];
            $wanted[] = [$r['source'], $r['denominator']];
        }

        if ($wanted === []) {
            return ['evaluated' => 0, 'raised' => 0];
        }

        $rows = DB::table('health_daily_metrics')
            ->whereBetween('metric_date', [$from->toDateString(), $dayStr])
            ->where(function ($q) use ($wanted) {
                foreach ($wanted as [$s, $m]) {
                    $q->orWhere(fn ($w) => $w->where('source', $s)->where('metric', $m));
                }
            })
            ->get(['metric_date', 'source', 'metric', 'dimension', 'count']);

        /** @var array<string, array<string,int>> $byKey key => [date => count] */
        $byKey = [];
        foreach ($rows as $r) {
            $byKey[$this->key($r->source, $r->metric, $r->dimension)][substr((string) $r->metric_date, 0, 10)] = (int) $r->count;
        }

        // First day of history per source, so zero-days before launch are
        // not mistaken for "quiet" days.
        $earliest = DB::table('health_daily_metrics')
            ->selectRaw('source, MIN(metric_date) as first_date')
            ->groupBy('source')
            ->pluck('first_date', 'source');

        $evaluated = 0;
        $raised    = 0;

        $isCountSeries = fn (string $source, string $metric) => in_array([$source, $metric], $countPairs, true);

        foreach ($byKey as $key => $series) {
            [$source, $metric, $dimension] = explode('|', $key, 3);

            if (!$isCountSeries($source, $metric)) {
                continue;
            }

            $observed = $series[$dayStr] ?? 0;
            $evaluated++;

            if ($observed <= 0) {
                continue;
            }

            $baseline  = $this->baselineFor($series, $day, $from, $earliest[$source] ?? null);
            $detection = $this->detector->detectCount($observed, $baseline);

            if ($detection->anomalous && $this->raise(
                SystemAlert::TYPE_COUNT_SPIKE, $source, $metric, $dimension, $dayStr,
                $detection, count($baseline), $notify,
            )) {
                $raised++;
            }
        }

        foreach ($rateSeries as $r) {
            $num = $byKey[$this->key($r['source'], $r['numerator'], '')] ?? [];
            $den = $byKey[$this->key($r['source'], $r['denominator'], '')] ?? [];

            $attempts = $den[$dayStr] ?? 0;
            $evaluated++;

            if ($attempts <= 0) {
                continue;
            }

            $start = $this->historyStart($from, $earliest[$r['source']] ?? null);
            $pairs = [];
            for ($d = $start; $d->lt($day); $d = $d->addDay()) {
                $ds      = $d->toDateString();
                $pairs[] = [$num[$ds] ?? 0, $den[$ds] ?? 0];
            }

            $detection = $this->detector->detectRate($num[$dayStr] ?? 0, $attempts, $pairs);

            if ($detection->anomalous && $this->raise(
                SystemAlert::TYPE_RATE_SPIKE, $r['source'], $r['numerator'] . '_rate', '', $dayStr,
                $detection, count($pairs), $notify,
            )) {
                $raised++;
            }
        }

        return ['evaluated' => $evaluated, 'raised' => $raised];
    }

    /** @return list<int> */
    private function baselineFor(array $series, CarbonImmutable $day, CarbonImmutable $from, ?string $earliest): array
    {
        $baseline = [];
        for ($d = $this->historyStart($from, $earliest); $d->lt($day); $d = $d->addDay()) {
            $baseline[] = $series[$d->toDateString()] ?? 0;
        }

        return $baseline;
    }

    private function historyStart(CarbonImmutable $from, ?string $earliest): CarbonImmutable
    {
        if ($earliest === null) {
            return $from;
        }

        $first = CarbonImmutable::parse(substr($earliest, 0, 10))->startOfDay();

        return $first->gt($from) ? $first : $from;
    }

    private function raise(
        string $type, string $source, string $metric, string $dimension, string $dayStr,
        \App\Support\Health\Detection $d, int $baselineDays, bool $notify,
    ): bool {
        $alert = $this->alerts->raise([
            // One incident per series per day.
            'alert_key'       => implode(':', [$type, $source, $metric, $dimension === '' ? '-' : $dimension, $dayStr]),
            'type'            => $type,
            'severity'        => $d->severity,
            'source'          => $source,
            'metric'          => $metric,
            'dimension'       => $dimension,
            'window_start'    => $dayStr,
            'window_end'      => $dayStr,
            'observed'        => $d->observed,
            'baseline_median' => $d->median,
            'baseline_mad'    => $d->mad,
            'score'           => $d->score,
            'context'         => ['reason' => $d->reason, 'baseline_days' => $baselineDays],
        ], $notify);

        return $alert !== null;
    }

    private function key(string $source, string $metric, string $dimension): string
    {
        return "{$source}|{$metric}|{$dimension}";
    }
}
