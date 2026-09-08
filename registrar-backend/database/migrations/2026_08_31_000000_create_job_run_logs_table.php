<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Job-Health Monitoring — closes the operational gap surfaced during the
 * Sep 2026 production verification of ShredExpiredRequests/
 * SendUnclaimedReminders: the ONLY prior signal that a scheduled command
 * actually ran was a line in storage/logs/scheduler.log inside the
 * scheduler container, which nobody sees unless they SSH in and grep it.
 * NotificationService::send() also swallows delivery failures into a
 * logged warning, so a broken run could silently stop notifying students
 * with nothing surfacing anywhere else.
 *
 * job_run_logs gives every scheduled command (see routes/console.php —
 * currently 8, see JobRunLog::JOBS for the canonical list) a queryable
 * row per invocation: started, finished, succeeded/failed, how many rows
 * it touched, and — on failure — why. SuperAdminAnalyticsService::
 * scheduledJobsHealth() reads the latest row per job for the SuperAdmin
 * dashboard's "Scheduled Jobs Health" panel, the same shape as the
 * existing adminRosterHealth()/cashierVerificationHealth() panels.
 *
 * Deliberately a separate table from both audit_logs (hash-chained
 * compliance record of WHO changed WHAT) and security_events
 * (RIS-only auth/security signal) — this is neither; it's job
 * execution telemetry, closer in spirit to security_events
 * (operational, prunable, not tamper-evident) but for a completely
 * different consumer and question ("did the cron actually run").
 *
 * Unlike security_events (write-once, see that model's docblock), a
 * job_run_logs row is written twice on purpose: once on start (status
 * = running) and once on completion (status = success/failed) — a row
 * still sitting in 'running' well past a job's normal runtime is itself
 * useful signal (the process died mid-run without ever reaching the
 * finally/catch that would have closed it out). See LogsJobRun trait.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_run_logs', function (Blueprint $table) {
            // Plain integer autoincrement, matching every other table's
            // PK convention in this schema (see e.g. unmatched_cashier_
            // items, security_events) rather than Laravel's default
            // bigIncrements.
            $table->integer('job_run_id')->autoIncrement();

            // The artisan command's own name (Command::getName(), e.g.
            // 'notifications:shred-expired-requests') — sourced directly
            // from each command via LogsJobRun::startJobRun($this->
            // getName()) rather than a separately-maintained constant, so
            // this column can never drift from the actual $signature that
            // ran.
            $table->string('job_name', 100);

            // 'running' | 'success' | 'failed' — see JobRunLog::STATUS_*.
            // Plain indexed string rather than a DB enum column, same
            // reasoning as security_events.event_type: a future status
            // never needs a migration to add a new enum value.
            $table->string('status', 20)->default('running');

            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();

            // Precomputed at write time (finished_at - started_at in ms)
            // rather than derived on every read — this table is read far
            // more often (every dashboard load, on a cache miss) than
            // written (at most a few dozen rows a day across all 8 jobs).
            $table->unsignedInteger('duration_ms')->nullable();

            // What the command itself reports it did — e.g. "3 requests
            // forfeited", "0 reminders sent". Semantics are job-specific
            // (see each command's own finishJobRun() call) but the column
            // is intentionally generic so the health panel doesn't need
            // per-job special-casing to render a number.
            $table->unsignedInteger('rows_affected')->nullable();

            // Populated only on status = 'failed'. Truncated to 2000
            // chars by LogsJobRun before it ever reaches this column
            // (see Str::limit call) — this is an operational summary for
            // a dashboard card, not a full stack trace; the full
            // exception still goes to storage/logs/laravel.log via the
            // command's own Log::error/Log::critical call as before.
            $table->text('error_message')->nullable();

            // Composite index matches the one way this table is actually
            // queried at read time: "for each job_name, find the row
            // with the max started_at" (see SuperAdminAnalyticsService::
            // scheduledJobsHealth()) without a full table scan as this
            // grows across every job, every run, indefinitely until
            // PruneJobRunLogs catches up with it.
            $table->index(['job_name', 'started_at'], 'job_run_logs_job_started_idx');

            // Supports PruneJobRunLogs' retention cutoff scan.
            $table->index('started_at', 'job_run_logs_started_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_run_logs');
    }
};
