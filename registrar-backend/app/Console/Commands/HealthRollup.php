<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\LogsJobRun;
use App\Services\Health\HealthRollupService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Hourly rollup of operational signals into health_daily_metrics.
 *
 * Recomputes today AND yesterday on every run (--days=2) so late-arriving
 * rows (queued enrichment jobs, clock skew around midnight) are absorbed.
 * A no-op while the system_health flag is off; the run is still logged so
 * Scheduled Jobs Health shows it as alive.
 */
class HealthRollup extends Command
{
    use LogsJobRun;

    protected $signature = 'health:rollup
                            {--days=2 : Most recent days (ending today) to recompute}
                            {--force : Run even when the system_health feature flag is off}';

    protected $description = 'Aggregate System Health daily metrics (idempotent)';

    public function handle(HealthRollupService $service): int
    {
        $this->startJobRun($this->getName());

        try {
            if (!config('features.system_health') && !$this->option('force')) {
                $this->info('[health:rollup] system_health feature is off; nothing to do.');
                $this->finishJobRun(self::SUCCESS, 0);

                return self::SUCCESS;
            }

            $days  = max(1, min(120, (int) $this->option('days')));
            $today = CarbonImmutable::today();
            $rows  = 0;

            for ($i = $days - 1; $i >= 0; $i--) {
                $rows += $service->rollupDate($today->subDays($i));
            }

            $this->info("[health:rollup] {$days} day(s) recomputed, {$rows} metric row(s) written.");
            $this->finishJobRun(self::SUCCESS, $rows);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->failJobRun($e);
            throw $e;
        }
    }
}
