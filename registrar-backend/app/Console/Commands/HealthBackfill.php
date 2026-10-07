<?php

namespace App\Console\Commands;

use App\Services\Health\HealthRollupService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Manual, one-off history rebuild for the demo and the evaluation.
 *
 * Not scheduled (so it is deliberately absent from JobRunLog::JOBS).
 * Writes counts only; never sends notifications. To replay detection over
 * the rebuilt history without paging anyone:
 *
 *   php artisan health:backfill --days=90 --force
 *   php artisan health:detect --days=90 --no-notify --force
 */
class HealthBackfill extends Command
{
    protected $signature = 'health:backfill
                            {--days=90 : Number of days back from today to rebuild (1-400)}
                            {--force : Run even when the system_health feature flag is off}';

    protected $description = 'Rebuild System Health daily metrics for the last N days';

    public function handle(HealthRollupService $service): int
    {
        if (!config('features.system_health') && !$this->option('force')) {
            $this->warn('[health:backfill] system_health feature is off; pass --force to run anyway.');

            return self::SUCCESS;
        }

        $days  = max(1, min(400, (int) $this->option('days')));
        $today = CarbonImmutable::today();
        $rows  = 0;

        $bar = $this->output->createProgressBar($days);

        for ($i = $days - 1; $i >= 0; $i--) {
            $rows += $service->rollupDate($today->subDays($i));
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("[health:backfill] {$days} day(s) rebuilt, {$rows} metric row(s) written.");

        return self::SUCCESS;
    }
}
