<?php

namespace App\Support\Health;

/**
 * Deterministic, I/O-free anomaly detection (deliberately not AI: it must be
 * explainable, testable and cheap).
 *
 * METHOD — modified z-score over a trailing baseline:
 *     score = 0.6745 * (observed - median) / MAD
 * Median and MAD (median absolute deviation) are robust: a single outage day
 * inside the baseline barely moves them, whereas mean/stddev would be
 * inflated by that day and hide the next incident.
 *
 * GUARDS (each one exists to stop a specific false alarm):
 *   - minBaselineDays : too little history -> never alert.
 *   - minCount floor  : tiny volumes are noisy; 3 failures is not an incident.
 *   - MAD = 0         : flat or all-zero baselines make the z-score undefined
 *                       (division by zero). Fall back to an absolute rule:
 *                       observed must beat the median by at least the floor
 *                       AND be at least double it.
 *   - one-sided       : only increases alert; failures dropping is good news.
 *
 * Callers must pass a baseline already trimmed to days that actually have
 * history, so pre-launch zero-days cannot drag the median down.
 */
final class AnomalyDetector
{
    private const MAD_SCALE = 0.6745;
    private const EPSILON   = 1e-9;

    public function __construct(
        private readonly float $zThreshold = 3.5,
        private readonly int $minCount = 5,
        private readonly int $minBaselineDays = 7,
        private readonly float $criticalZ = 7.0,
        private readonly int $flatCriticalCount = 25,
        private readonly int $rateMinAttempts = 10,
        private readonly float $rateMinDelta = 0.15,
        private readonly int $rateMinBaselineDays = 5,
        private readonly float $rateCriticalRate = 0.5,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            zThreshold:          (float) config('system_health.z_threshold', 3.5),
            minCount:            (int) config('system_health.min_count', 5),
            minBaselineDays:     (int) config('system_health.min_baseline_days', 7),
            criticalZ:           (float) config('system_health.critical_z', 7.0),
            flatCriticalCount:   (int) config('system_health.flat_critical_count', 25),
            rateMinAttempts:     (int) config('system_health.rate_min_attempts', 10),
            rateMinDelta:        (float) config('system_health.rate_min_delta', 0.15),
            rateMinBaselineDays: (int) config('system_health.rate_min_baseline_days', 5),
            rateCriticalRate:    (float) config('system_health.rate_critical_rate', 0.5),
        );
    }

    /**
     * @param list<int|float> $baseline daily counts, one per baseline day (zeros included)
     */
    public function detectCount(float $observed, array $baseline): Detection
    {
        if (count($baseline) < $this->minBaselineDays) {
            return Detection::normal('insufficient_baseline', $observed);
        }

        if ($observed < $this->minCount) {
            return Detection::normal('below_floor', $observed);
        }

        $median = self::median($baseline);
        $mad    = self::mad($baseline, $median);

        if ($mad < self::EPSILON) {
            $anomalous = ($observed - $median) >= $this->minCount && $observed >= 2 * $median;

            return $anomalous
                ? Detection::anomaly(
                    'flat_baseline', $observed, $median, $mad, null,
                    $observed >= $this->flatCriticalCount ? 'critical' : 'warning',
                )
                : Detection::normal('within_range', $observed, $median, $mad);
        }

        $score = self::MAD_SCALE * ($observed - $median) / $mad;

        if ($score < $this->zThreshold) {
            return Detection::normal('within_range', $observed, $median, $mad, $score);
        }

        return Detection::anomaly(
            'modified_z', $observed, $median, $mad, $score,
            $score >= $this->criticalZ ? 'critical' : 'warning',
        );
    }

    /**
     * Failure-rate check. Requires a minimum number of attempts on the day
     * and on each baseline day counted, so 2-of-3 failing is never "67 %".
     *
     * @param list<array{0:int,1:int}> $baseline [failures, attempts] per baseline day
     */
    public function detectRate(int $failures, int $attempts, array $baseline): Detection
    {
        $observedRate = $attempts > 0 ? $failures / $attempts : 0.0;

        if ($attempts < $this->rateMinAttempts) {
            return Detection::normal('below_min_attempts', $observedRate);
        }

        $rates = [];
        foreach ($baseline as [$f, $a]) {
            if ($a >= $this->rateMinAttempts) {
                $rates[] = $f / $a;
            }
        }

        if (count($rates) < $this->rateMinBaselineDays) {
            return Detection::normal('insufficient_baseline', $observedRate);
        }

        $median = self::median($rates);
        $mad    = self::mad($rates, $median);
        $delta  = $observedRate - $median;

        if ($delta < $this->rateMinDelta) {
            return Detection::normal('within_range', $observedRate, $median, $mad);
        }

        $severity = $observedRate >= $this->rateCriticalRate ? 'critical' : 'warning';

        if ($mad < self::EPSILON) {
            return Detection::anomaly('flat_baseline_rate', $observedRate, $median, $mad, null, $severity);
        }

        $score = self::MAD_SCALE * $delta / $mad;

        return $score >= $this->zThreshold
            ? Detection::anomaly('modified_z_rate', $observedRate, $median, $mad, $score, $severity)
            : Detection::normal('within_range', $observedRate, $median, $mad, $score);
    }

    /** @param non-empty-array<int|float> $values */
    public static function median(array $values): float
    {
        $v = array_values($values);
        sort($v);
        $n   = count($v);
        $mid = intdiv($n, 2);

        return $n % 2 === 1 ? (float) $v[$mid] : ($v[$mid - 1] + $v[$mid]) / 2;
    }

    /** @param non-empty-array<int|float> $values */
    public static function mad(array $values, float $median): float
    {
        return self::median(array_map(fn ($x) => abs($x - $median), $values));
    }
}
