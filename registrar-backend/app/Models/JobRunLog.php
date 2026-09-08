<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per invocation of a scheduled artisan command — see the
 * create_job_run_logs_table migration's docblock for why this table
 * exists and how it differs from AuditLog/SecurityEvent. Written by
 * every scheduled command via App\Console\Commands\Concerns\LogsJobRun,
 * read by SuperAdminAnalyticsService::scheduledJobsHealth() for the
 * SuperAdmin dashboard's "Scheduled Jobs Health" panel, and pruned on a
 * retention schedule by PruneJobRunLogs.
 */
class JobRunLog extends Model
{
    protected $table      = 'job_run_logs';
    protected $primaryKey = 'job_run_id';

    // No updated_at column — started_at/finished_at already capture the
    // two points in this row's lifecycle explicitly and unambiguously;
    // a generic updated_at would just duplicate finished_at.
    public $timestamps = false;

    protected $fillable = [
        'job_name',
        'status',
        'started_at',
        'finished_at',
        'duration_ms',
        'rows_affected',
        'error_message',
    ];

    protected $casts = [
        'started_at'    => 'datetime',
        'finished_at'   => 'datetime',
        'duration_ms'   => 'integer',
        'rows_affected' => 'integer',
    ];

    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED  = 'failed';

    /**
     * Canonical job_name => human-readable schedule label, for every
     * command registered in routes/console.php. This is the single
     * source of truth SuperAdminAnalyticsService::scheduledJobsHealth()
     * iterates over — a job that has NEVER produced a single row still
     * shows up on the dashboard as "no runs recorded" instead of just
     * being silently absent, which is itself an important signal (e.g.
     * the scheduler container never having been deployed with this
     * command registered).
     *
     * job_name values themselves are never hardcoded a second time
     * anywhere else — every command derives its own job_name at runtime
     * via $this->getName() (see LogsJobRun::startJobRun()), so this map
     * can only ever drift on the LABEL side, never silently stop
     * matching a real command's rows.
     */
    public const JOBS = [
        'notifications:shred-expired-requests'   => 'Hourly',
        'notifications:send-unclaimed-reminders' => 'Daily 08:05',
        'announcements:auto-disable-expired'     => 'Daily 08:10',
        'provisioning:expire-stale'              => 'Daily 08:15',
        'role-assignments:expire'                => 'Hourly',
        'audit:verify'                           => 'Daily 03:00',
        'break-glass:test'                       => 'Weekly',
        'security-events:prune'                  => 'Daily 08:25',
        'job-run-logs:prune'                     => 'Daily 08:30',
    ];

    /**
     * Job-Health Monitoring — "overdue" detection.
     *
     * The gap this closes: 'stalled' (above) only catches a run that
     * started and never finished. It says nothing about a job whose
     * LAST run finished fine but which simply never started again on
     * schedule — e.g. the scheduler container was down for a deploy
     * across an entire 08:05 tick, or down for hours across several
     * hourly ticks. That job's latest row still reads STATUS_SUCCESS,
     * so without this check scheduledJobsHealth() would report it
     * "success" indefinitely, even days after it should have run again.
     * This was flagged as an explicitly unverified edge case during the
     * Sep 2026 production check of SendUnclaimedReminders (job happened
     * to catch its window on the container's first opportunity — the
     * "container down across the whole window" case was never actually
     * observed) and is the reason this map exists.
     *
     * Value is the max minutes allowed since a job's last START before
     * SuperAdminAnalyticsService::scheduledJobsHealth() reports it as
     * 'overdue' instead of 'success'. Each cadence gets its own
     * generous-but-meaningful grace window rather than one global
     * constant:
     *   - Hourly jobs: 2x the interval (120 min) — tolerates a single
     *     missed tick (a deploy that takes a few minutes) without
     *     alarming, but catches two-or-more consecutive misses, which a
     *     genuinely dead scheduler container would produce.
     *   - Daily jobs: 26 hours (1560 min) — ~2 hours of grace past the
     *     24-hour cadence, enough to absorb a late-night deploy window
     *     without false-positiving on a job that's merely a little late.
     *   - Weekly (break-glass:test): 8 days (11520 min) — one day of
     *     grace past the 7-day cadence.
     *
     * A job intentionally absent from this map (there are none today,
     * but a future one-off/manual-only command might be) is simply
     * never flagged overdue — scheduledJobsHealth() treats an unmapped
     * job_name as opting out of this check, not as an error.
     */
    public const EXPECTED_INTERVAL_MINUTES = [
        'notifications:shred-expired-requests'   => 120,
        'role-assignments:expire'                => 120,
        'notifications:send-unclaimed-reminders' => 1560,
        'announcements:auto-disable-expired'     => 1560,
        'provisioning:expire-stale'              => 1560,
        'audit:verify'                           => 1560,
        'security-events:prune'                  => 1560,
        'job-run-logs:prune'                     => 1560,
        'break-glass:test'                       => 11520,
    ];
}