<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\LogsJobRun;
use App\Models\TicketPublicSubmission;
use Illuminate\Console\Command;

/**
 * Support Ticketing — Phase 4 retention for pending public submissions.
 *
 * A pending submission holds a stranger's email address and typed message until
 * they confirm. Ones never confirmed (expired links) and ones confirmed long
 * ago (their text already wiped, only bookkeeping left) are deleted after
 * config('tickets.public.pending_retention_days'). Idempotent: running it twice
 * deletes nothing the second time. Recorded in job_run_logs via LogsJobRun.
 */
class PruneTicketPublicSubmissions extends Command
{
    use LogsJobRun;

    protected $signature   = 'tickets:prune-public-submissions';
    protected $description = 'Delete expired or long-confirmed pending public ticket submissions';

    public function handle(): int
    {
        $this->startJobRun($this->getName());

        try {
            $cutoff  = now()->subDays(max(1, (int) config('tickets.public.pending_retention_days')));
            $deleted = TicketPublicSubmission::query()->where('created_at', '<', $cutoff)->delete();

            $this->info("Deleted {$deleted} pending public ticket submission(s).");
            $this->finishJobRun(self::SUCCESS, $deleted);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->failJobRun($e);
            throw $e;
        }
    }
}
