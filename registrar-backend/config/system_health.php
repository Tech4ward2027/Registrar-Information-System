<?php

/**
 * System Health — tunables for rollups, anomaly detection and alerts.
 *
 * Every value is env-overridable so thresholds can be tuned on backfilled
 * data without a deploy. The whole feature is additionally gated by
 * config('features.system_health') (default false).
 *
 * METRIC CATALOG (health_daily_metrics rows; `dimension` is '' when unused)
 *   cashier      attempts              ''                 verification attempts that day
 *   cashier      failures              ''                 attempts that did not end in approval
 *   cashier      overrides             ''                 admin OR overrides (not lookups; not in attempts)
 *   cashier      failures_by_reason    NOT_FOUND|API_ERROR|UNKNOWN
 *   cashier      diagnosis_code        <failure_reason_codes.code>
 *   provisioning failures              ogos|puptaps
 *   provisioning failures_by_reason    <SecurityEvent provisioning reason>
 *   labels       new_labels            ''                 new unmatched cashier labels
 *
 * PRIVACY: this table stores counts only — never names, emails, OR numbers.
 */
return [

    // Trailing window the "normal" level is estimated from.
    'baseline_days' => (int) env('SYSTEM_HEALTH_BASELINE_DAYS', 28),

    // A series needs at least this many days of real history before it can alert.
    'min_baseline_days' => (int) env('SYSTEM_HEALTH_MIN_BASELINE_DAYS', 7),

    // Modified z-score (median/MAD) at or above which a day is anomalous.
    'z_threshold' => (float) env('SYSTEM_HEALTH_Z_THRESHOLD', 3.5),

    // Severity escalates to "critical" at this score...
    'critical_z' => (float) env('SYSTEM_HEALTH_CRITICAL_Z', 7.0),

    // Volume floor: a count below this never alerts, however unusual.
    'min_count' => (int) env('SYSTEM_HEALTH_MIN_COUNT', 5),

    // ...and, on a flat baseline (MAD = 0, so no z-score), at this raw count.
    'flat_critical_count' => (int) env('SYSTEM_HEALTH_FLAT_CRITICAL_COUNT', 25),

    // Failure-rate check: needs this many attempts that day, and in each
    // baseline day that counts toward the baseline.
    'rate_min_attempts'      => (int) env('SYSTEM_HEALTH_RATE_MIN_ATTEMPTS', 10),
    'rate_min_baseline_days' => (int) env('SYSTEM_HEALTH_RATE_MIN_BASELINE_DAYS', 5),
    // Rate must also exceed the baseline median by this absolute margin (0.15 = 15 points).
    'rate_min_delta'         => (float) env('SYSTEM_HEALTH_RATE_MIN_DELTA', 0.15),
    'rate_critical_rate'     => (float) env('SYSTEM_HEALTH_RATE_CRITICAL_RATE', 0.5),

    // Repeat-failure watch: one user with >= N failures inside the window.
    'repeat_failure_threshold'   => (int) env('SYSTEM_HEALTH_REPEAT_FAILURE_N', 3),
    'repeat_failure_window_days' => (int) env('SYSTEM_HEALTH_REPEAT_FAILURE_WINDOW_DAYS', 7),

    // Which series the detector watches: [source, metric]. Every dimension
    // found under a pair is evaluated independently.
    'count_series' => [
        ['cashier', 'failures'],
        ['cashier', 'failures_by_reason'],
        ['cashier', 'diagnosis_code'],
        ['provisioning', 'failures'],
        ['provisioning', 'failures_by_reason'],
        ['labels', 'new_labels'],
    ],

    // Rate series: numerator / denominator, both dimension ''.
    'rate_series' => [
        ['source' => 'cashier', 'numerator' => 'failures', 'denominator' => 'attempts'],
    ],

    // Retention (days). Rollups hold counts only; alerts hold user IDs, not names.
    'retention' => [
        'daily_metrics_days' => (int) env('SYSTEM_HEALTH_METRICS_RETENTION_DAYS', 400),
        'alerts_days'        => (int) env('SYSTEM_HEALTH_ALERTS_RETENTION_DAYS', 365),
    ],
];