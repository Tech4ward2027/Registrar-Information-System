<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\LogsJobRun;
use App\Models\SecurityEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| PruneSecurityEvents
|--------------------------------------------------------------------------
| Runs daily via the Laravel scheduler (see routes/console.php).
|
| Phase 3h — audit_logs stays untouched/permanent (its retention is a
| compliance decision outside this command's scope); only security_events
| is pruned, per config('security_events.retention_days').
|
| Uses a query-builder mass delete (SecurityEvent::where(...)->delete()),
| NOT a per-row $model->delete() loop. Two reasons:
|   1. Performance — one SQL statement instead of N round-trips for
|      what can be a high-volume table (see the plan doc's volume
|      concern re: "every bad password attempt, every bot scan").
|   2. Correctness — Eloquent mass deletes do not fire individual model
|      events, so SecurityEvent::booted()'s deleting-guard (which exists
|      specifically to block ad-hoc single-row deletes elsewhere in the
|      app) does not block this intentional, scheduled, bulk operation.
|      This is the "deletion allowed only via retention job" rule from
|      the plan, enforced structurally rather than via a bypass flag.
|--------------------------------------------------------------------------
*/

class PruneSecurityEvents extends Command
{
    use LogsJobRun;

    protected $signature   = 'security-events:prune';
    protected $description = 'Delete security_events rows older than the configured retention window';

    /**
     * Job-Health Monitoring: see LogsJobRun's docblock. Logic below is
     * unchanged; only the outer try/catch and the two logging calls
     * around it are new.
     */
    public function handle(): int
    {
        $this->startJobRun($this->getName());

        try {
            $retentionDays = (int) config('security_events.retention_days', 90);
            $cutoff        = Carbon::now()->subDays($retentionDays);

            $deleted = SecurityEvent::where('created_at', '<', $cutoff)->delete();

            Log::info('[PruneSecurityEvents] rows pruned', [
                'deleted_count'   => $deleted,
                'retention_days'  => $retentionDays,
                'cutoff'          => $cutoff->toDateTimeString(),
            ]);

            $this->info("[PruneSecurityEvents] {$deleted} row(s) older than {$retentionDays} day(s) deleted.");

            $this->finishJobRun(self::SUCCESS, $deleted);
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->failJobRun($e);
            throw $e;
        }
    }
}