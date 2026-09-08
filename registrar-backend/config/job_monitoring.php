<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Job-Health Monitoring — Retention
    |--------------------------------------------------------------------------
    | job_run_logs gets one (or two — start + finish) write per invocation
    | of every scheduled command (see JobRunLog::JOBS — currently 9,
    | including this retention job itself). At the busiest cadence in the
    | schedule (hourly), that's a bounded, low-volume table — nowhere near
    | security_events' "every bad password, every bot scan" concern — but
    | still worth pruning on a schedule rather than growing forever.
    |
    | retention_days
    |   How long a job_run_logs row is kept before PruneJobRunLogs deletes
    |   it. Longer than security_events' default (90 days) on purpose —
    |   this table is small enough that a longer window costs little, and
    |   a SuperAdmin diagnosing "was this job actually healthy last month"
    |   benefits from more history than a security-incident log needs.
    |--------------------------------------------------------------------------
    */

    'retention_days' => env('JOB_MONITORING_RETENTION_DAYS', 180),
];
