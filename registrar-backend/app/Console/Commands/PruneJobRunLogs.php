<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\LogsJobRun;
use App\Models\JobRunLog;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| PruneJobRunLogs
|--------------------------------------------------------------------------
| Runs daily via the Laravel scheduler (see routes/console.php).
|
| Job-Health Monitoring's own retention sweep — deletes job_run_logs rows
| older than config('job_monitoring.retention_days'). Same rationale and
| same mass-delete-over-per-row-loop approach as PruneSecurityEvents (see
| that command's docblock): one SQL statement instead of N round-trips,
| and JobRunLog has no write-once/deleting guard to route around (unlike
| SecurityEvent) since this table is legitimately updated once per run
| (running -> success/failed) before it's ever pruned.
|
| Deliberately covers its OWN job_name too (see JobRunLog::JOBS) — this
| command's own runs are just as much "did the scheduler actually fire
| this" signal as any other job's, and excluding itself from the table it
| prunes would be an arbitrary, undocumented special case.
|--------------------------------------------------------------------------
*/

class PruneJobRunLogs extends Command
{
    use LogsJobRun;

    protected $signature   = 'job-run-logs:prune';
    protected $description = 'Delete job_run_logs rows older than the configured retention window';

    /**
     * Job-Health Monitoring: see LogsJobRun's docblock. Logic below is
     * unchanged in shape from PruneSecurityEvents; only the outer
     * try/catch and the two logging calls around it are new.
     */
    public function handle(): int
    {
        $this->startJobRun($this->getName());

        try {
            $retentionDays = (int) config('job_monitoring.retention_days', 180);
            $cutoff        = Carbon::now()->subDays($retentionDays);

            $deleted = JobRunLog::where('started_at', '<', $cutoff)->delete();

            Log::info('[PruneJobRunLogs] rows pruned', [
                'deleted_count'  => $deleted,
                'retention_days' => $retentionDays,
                'cutoff'         => $cutoff->toDateTimeString(),
            ]);

            $this->info("[PruneJobRunLogs] {$deleted} row(s) older than {$retentionDays} day(s) deleted.");

            $this->finishJobRun(self::SUCCESS, $deleted);
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->failJobRun($e);
            throw $e;
        }
    }
}
