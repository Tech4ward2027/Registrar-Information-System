<?php

namespace App\Console\Commands;

use App\Support\Health\AnomalyDetector;
use Illuminate\Console\Command;

/**
 * Phase 7 evaluation of the anomaly detector. Pure simulation: no database,
 * no network, no personal data. Seeded, so the numbers are reproducible.
 *
 * For each baseline level (mean failures/day) it builds many synthetic
 * histories with Poisson noise, injects spikes of known size at fixed days,
 * and replays the detector day by day with the same 28-day trailing baseline
 * the production service uses. It reports recall per spike size (95 % Wilson
 * interval) and false alarms per week on normal days.
 *
 * Time to detect is "same evaluated day": the detector sees a day's counts
 * as soon as the hourly rollup includes them (not simulated).
 *
 * LIMITS: Poisson noise is an assumption; real traffic has weekday patterns
 * and bursts. Count series only; the rate check is covered by unit tests.
 */
class EvaluateAnomalyDetector extends Command
{
    protected $signature = 'health:eval-detector
        {--seed=20261010 : RNG seed}
        {--trials=200 : synthetic histories per baseline level}
        {--days=120 : days per history (minimum 110)}';

    protected $description = 'Measure anomaly detector recall and false-alarm rate on simulated histories (no DB access)';

    private const LEVELS          = [1, 3, 8, 20];
    private const SPIKE_DAYS      = [40 => 2, 60 => 3, 80 => 5, 100 => 10];
    private const BASELINE_WINDOW = 28;

    public function handle(): int
    {
        $seed   = (int) $this->option('seed');
        $trials = max(1, (int) $this->option('trials'));
        $days   = max(110, (int) $this->option('days'));

        $detector = AnomalyDetector::fromConfig();

        $this->line(sprintf(
            'Detector from config: z>=%s, min_count=%s, min_baseline_days=%s. seed=%d, trials/level=%d, days=%d',
            config('system_health.z_threshold', 3.5), config('system_health.min_count', 5),
            config('system_health.min_baseline_days', 7), $seed, $trials, $days,
        ));
        $this->newLine();

        foreach (self::LEVELS as $lambda) {
            $hit         = array_fill_keys(array_values(self::SPIKE_DAYS), 0);
            $n           = array_fill_keys(array_values(self::SPIKE_DAYS), 0);
            $falseAlarms = 0;
            $normalDays  = 0;

            for ($t = 0; $t < $trials; $t++) {
                mt_srand($seed + $t * 1009 + $lambda);

                $series = [];
                for ($d = 0; $d < $days; $d++) {
                    $series[$d] = $this->poisson($lambda * (self::SPIKE_DAYS[$d] ?? 1));
                }

                for ($d = 7; $d < $days; $d++) {
                    $from     = max(0, $d - self::BASELINE_WINDOW);
                    $baseline = array_slice($series, $from, $d - $from);
                    $alert    = $series[$d] > 0 && $detector->detectCount($series[$d], $baseline)->anomalous;

                    if (isset(self::SPIKE_DAYS[$d])) {
                        $m = self::SPIKE_DAYS[$d];
                        $n[$m]++;
                        $hit[$m] += $alert ? 1 : 0;
                    } else {
                        $normalDays++;
                        $falseAlarms += $alert ? 1 : 0;
                    }
                }
            }

            $this->info(sprintf('Baseline mean = %d failures/day', $lambda));
            foreach ($n as $m => $count) {
                [$lo, $hi] = $this->wilson($hit[$m], $count);
                $this->line(sprintf(
                    '  spike x%-3d (~%d/day)  recall %5.1f%% [%.1f-%.1f]  n=%d',
                    $m, $lambda * $m, 100 * $hit[$m] / $count, 100 * $lo, 100 * $hi, $count,
                ));
            }
            [$lo, $hi] = $this->wilson($falseAlarms, $normalDays);
            $this->line(sprintf(
                '  false alarms: %d over %d normal days = %.2f per week  (daily rate %.2f%% [%.2f-%.2f])',
                $falseAlarms, $normalDays, 7 * $falseAlarms / $normalDays,
                100 * $falseAlarms / $normalDays, 100 * $lo, 100 * $hi,
            ));
            $this->newLine();
        }

        return self::SUCCESS;
    }

    private function poisson(float $lambda): int
    {
        $limit = exp(-$lambda);
        $k     = 0;
        $p     = 1.0;
        do {
            $k++;
            $p *= mt_rand() / mt_getrandmax();
        } while ($p > $limit);

        return $k - 1;
    }

    /** @return array{0:float,1:float} 95 % Wilson interval */
    private function wilson(int $successes, int $n): array
    {
        if ($n === 0) {
            return [0.0, 0.0];
        }
        $z      = 1.96;
        $p      = $successes / $n;
        $denom  = 1 + $z * $z / $n;
        $centre = ($p + $z * $z / (2 * $n)) / $denom;
        $half   = $z * sqrt($p * (1 - $p) / $n + $z * $z / (4 * $n * $n)) / $denom;

        return [max(0.0, $centre - $half), min(1.0, $centre + $half)];
    }
}
