<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\LogsJobRun;
use App\Services\Health\HealthDetectionService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Runs anomaly detection over the rollups (health_daily_metrics). Scheduled
 * hourly, after the rollup. A no-op while the system_health flag is off, so
 * an environment that has not opted in does no work and sends nothing; the
 * run is still logged so Scheduled Jobs Health shows it as alive.
 *
 * --days=N evaluates the N most recent days ending today (default 2:
 * yesterday + today, covering late-arriving data). Use --no-notify with a
 * large --days to replay history (evaluation / demo) without paging anyone.
 */
class HealthDetect extends Command
{
    use LogsJobRun;

    protected $signature = 'health:detect
                            {--days=2 : Most recent days (ending today) to evaluate}
                            {--no-notify : Create alerts but do not notify Super Admins}
                            {--force : Run even when the system_health feature flag is off}';

    protected $description = 'Detect anomalies in the System Health rollups and raise alerts';

    public function handle(HealthDetectionService $service): int
    {
        $this->startJobRun($this->getName());

        try {
            if (!config('features.system_health') && !$this->option('force')) {
                $this->info('[health:detect] system_health feature is off; nothing to do.');
                $this->finishJobRun(self::SUCCESS, 0);

                return self::SUCCESS;
            }

            $days   = max(1, min(120, (int) $this->option('days')));
            $notify = !$this->option('no-notify');
            $today  = CarbonImmutable::today();
            $raised = 0;

            for ($i = $days - 1; $i >= 0; $i--) {
                $raised += $service->evaluateDate($today->subDays($i), $notify)['raised'];
            }

            $this->info("[health:detect] {$days} day(s) evaluated, {$raised} alert(s) raised.");
            $this->finishJobRun(self::SUCCESS, $raised);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->failJobRun($e);
            throw $e;
        }
    }
}
