<?php

namespace App\Support\Health;

/**
 * Immutable result of one AnomalyDetector check. `reason` is a stable
 * machine-readable code (never user text) so it can be stored in alert
 * context and asserted in tests.
 */
final class Detection
{
    private function __construct(
        public readonly bool $anomalous,
        public readonly string $reason,
        public readonly float $observed,
        public readonly ?float $median,
        public readonly ?float $mad,
        public readonly ?float $score,
        public readonly ?string $severity,
    ) {}

    public static function normal(string $reason, float $observed, ?float $median = null, ?float $mad = null, ?float $score = null): self
    {
        return new self(false, $reason, $observed, $median, $mad, $score, null);
    }

    public static function anomaly(string $reason, float $observed, float $median, float $mad, ?float $score, string $severity): self
    {
        return new self(true, $reason, $observed, $median, $mad, $score, $severity);
    }
}
