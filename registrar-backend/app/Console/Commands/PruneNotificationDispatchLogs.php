<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\LogsJobRun;
use App\Models\NotificationDispatchLog;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| PruneNotificationDispatchLogs
|--------------------------------------------------------------------------
| Daily retention sweep for notification_dispatch_logs (Notification Policy
| §4). Same shape as PruneSecurityEvents: a query-builder mass delete, which
| is the one sanctioned way rows leave this append-only table (it does not
| fire the model's deleting guard).
|--------------------------------------------------------------------------
*/
class PruneNotificationDispatchLogs extends Command
{
    use LogsJobRun;

    protected $signature   = 'notifications:prune-dispatch-logs';
    protected $description = 'Delete notification_dispatch_logs rows older than the configured retention window';

    public function handle(): int
    {
        $this->startJobRun($this->getName());

        try {
            $retentionDays = max(1, (int) config('notification_dispatch.retention_days', 180));
            $cutoff        = Carbon::now()->subDays($retentionDays);

            $deleted = NotificationDispatchLog::where('created_at', '<', $cutoff)->delete();

            Log::info('[PruneNotificationDispatchLogs] rows pruned', [
                'deleted_count'  => $deleted,
                'retention_days' => $retentionDays,
                'cutoff'         => $cutoff->toDateTimeString(),
            ]);

            $this->info("[PruneNotificationDispatchLogs] {$deleted} row(s) older than {$retentionDays} day(s) deleted.");

            $this->finishJobRun(self::SUCCESS, $deleted);
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->failJobRun($e);
            throw $e;
        }
    }
}
