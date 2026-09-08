<?php

namespace App\Console\Commands\Concerns;

use App\Models\JobRunLog;
use Illuminate\Support\Str;
use Throwable;

/**
 * Wraps a scheduled command's handle() with a JobRunLog row, so the
 * SuperAdmin "Scheduled Jobs Health" dashboard panel (see
 * SuperAdminAnalyticsService::scheduledJobsHealth()) can answer "did
 * this job actually run, and did it succeed" without anyone SSHing into
 * the scheduler container and grepping storage/logs/scheduler.log —
 * previously the only signal, and nobody was alerted to it.
 *
 * USAGE — brackets a command's existing body, no business logic changes:
 *
 *   use LogsJobRun;
 *
 *   public function handle(SomeDep $dep): int
 *   {
 *       $this->startJobRun($this->getName());
 *
 *       try {
 *           // ...existing logic, unchanged...
 *           $this->finishJobRun(self::SUCCESS, $rowsAffected);
 *           return self::SUCCESS;
 *       } catch (Throwable $e) {
 *           $this->failJobRun($e);
 *           throw $e; // preserve the command's existing crash/exit-code behavior
 *       }
 *   }
 *
 * Commands with more than one INTENTIONAL (non-exception) exit point —
 * e.g. TestBreakGlassAccess/VerifyAuditChain, which return self::FAILURE
 * without throwing when a check fails — call finishJobRun() with the
 * real exit code and a short error summary immediately before EACH
 * return, not just the happy path. failJobRun() is reserved for genuine
 * uncaught exceptions (DB connection lost, etc.), so the row's
 * error_message reflects the command's own diagnosis rather than a
 * generic exception string when the command already knows exactly what
 * went wrong.
 */
trait LogsJobRun
{
    private ?JobRunLog $jobRunLog = null;

    protected function startJobRun(string $jobName): void
    {
        $this->jobRunLog = JobRunLog::create([
            'job_name'   => $jobName,
            'status'     => JobRunLog::STATUS_RUNNING,
            'started_at' => now(),
        ]);
    }

    /**
     * Closes out the current run as either success or failure, keyed off
     * the command's own exit code — $exitCode === self::SUCCESS (0) is
     * success, anything else (self::FAILURE = 1, self::INVALID = 2) is
     * recorded as failed.
     */
    protected function finishJobRun(int $exitCode, ?int $rowsAffected = null, ?string $errorSummary = null): void
    {
        if (!$this->jobRunLog) {
            // startJobRun() was never called — degrade silently rather
            // than fatal; job-health telemetry must never be able to
            // break the job it's observing.
            return;
        }

        $finishedAt = now();

        $this->jobRunLog->forceFill([
            'status'        => $exitCode === self::SUCCESS ? JobRunLog::STATUS_SUCCESS : JobRunLog::STATUS_FAILED,
            'finished_at'   => $finishedAt,
            'duration_ms'   => (int) $this->jobRunLog->started_at->diffInMilliseconds($finishedAt),
            'rows_affected' => $rowsAffected,
            'error_message' => $errorSummary !== null ? Str::limit($errorSummary, 2000, '') : null,
        ])->save();

        // Guard against double-finishing if a command path accidentally
        // calls this (or failJobRun()) more than once for the same run.
        $this->jobRunLog = null;
    }

    protected function failJobRun(Throwable $e): void
    {
        if (!$this->jobRunLog) {
            return;
        }

        $finishedAt = now();

        $this->jobRunLog->forceFill([
            'status'        => JobRunLog::STATUS_FAILED,
            'finished_at'   => $finishedAt,
            'duration_ms'   => (int) $this->jobRunLog->started_at->diffInMilliseconds($finishedAt),
            'error_message' => Str::limit($e->getMessage(), 2000, ''),
        ])->save();

        $this->jobRunLog = null;
    }
}
