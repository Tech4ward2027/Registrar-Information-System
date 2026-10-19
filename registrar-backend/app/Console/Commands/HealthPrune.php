<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\LogsJobRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Retention for the System Health tables, driven by
 * config('system_health.retention.*'). Mass deletes via the query builder
 * (one statement per table), same approach as PruneSecurityEvents.
 */
class HealthPrune extends Command
{
    use LogsJobRun;

    protected $signature   = 'health:prune';
    protected $description = 'Delete System Health rollups and alerts older than their retention windows';

    public function handle(): int
    {
        $this->startJobRun($this->getName());

        try {
            $metricsDays = max(1, (int) config('system_health.retention.daily_metrics_days', 400));
            $alertsDays  = max(1, (int) config('system_health.retention.alerts_days', 365));

            $metrics = DB::table('health_daily_metrics')
                ->where('metric_date', '<', now()->subDays($metricsDays)->toDateString())
                ->delete();

            $alerts = DB::table('system_alerts')
                ->where('created_at', '<', now()->subDays($alertsDays))
                ->delete();

            $this->info("[health:prune] {$metrics} metric row(s) and {$alerts} alert(s) deleted.");
            $this->finishJobRun(self::SUCCESS, $metrics + $alerts);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->failJobRun($e);
            throw $e;
        }
    }
}
