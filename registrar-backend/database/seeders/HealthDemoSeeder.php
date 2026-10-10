<?php

namespace Database\Seeders;

use App\Models\SecurityEvent;
use App\Models\SystemAlert;
use App\Services\Health\HealthDetectionService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * LOCAL / TEST ONLY. Builds 90 days of synthetic System Health history
 * (counts only, no personal data) with ONE injected incident on today:
 *
 *   - a Cashier API outage: failures jump from ~3/day to 38 (35 API_ERROR),
 *     and the failure rate from ~10 % to ~63 %;
 *   - an OGOS outage: ogos provisioning failures jump from 0-1/day to 22.
 *
 * Normal days use a fixed repeating pattern (no randomness), so the history
 * is identical on every run and cannot raise a false alarm by chance.
 * Detection is then replayed over the last 45 days with notifications off, so
 * the Overview tab shows the real alerts the detector produced.
 *
 * It REPLACES health_daily_metrics and system_alerts in the target database.
 * Run it only against a development database:
 *   php artisan db:seed --class=HealthDemoSeeder
 * Re-run it on the morning of the demo so the incident is "today".
 */
class HealthDemoSeeder extends Seeder
{
    private const DAYS = 90;

    public function run(HealthDetectionService $detection): void
    {
        if (!app()->environment(['local', 'testing'])) {
            $this->command?->error('HealthDemoSeeder refused to run: APP_ENV must be "local" or "testing".');

            return;
        }

        $today    = CarbonImmutable::today();
        $failures = [3, 4, 2, 5, 3, 4, 3];
        $attempts = [30, 34, 28, 36, 32, 31, 29];
        $labels   = [0, 1, 0, 2, 0, 1, 0];
        $ogos     = [0, 1, 0, 0, 1, 0, 0];

        $rows = [];
        $put  = function (CarbonImmutable $d, string $source, string $metric, string $dim, int $n) use (&$rows): void {
            $rows[] = [
                'metric_date' => $d->toDateString(), 'source' => $source, 'metric' => $metric,
                'dimension'   => $dim, 'count' => $n, 'computed_at' => now(),
            ];
        };

        for ($i = self::DAYS; $i >= 0; $i--) {
            $d       = $today->subDays($i);
            $k       = $i % 7;
            $isToday = $i === 0;

            $f   = $isToday ? 38 : $failures[$k];
            $api = $isToday ? 35 : 0;
            $a   = $isToday ? 60 : $attempts[$k];
            $o   = $isToday ? 22 : $ogos[$k];

            $put($d, 'cashier', 'attempts', '', $a);
            $put($d, 'cashier', 'failures', '', $f);
            $put($d, 'cashier', 'overrides', '', $k === 3 ? 1 : 0);
            $put($d, 'cashier', 'failures_by_reason', 'NOT_FOUND', $f - $api);
            if ($api > 0) {
                $put($d, 'cashier', 'failures_by_reason', 'API_ERROR', $api);
            }
            if ($f - $api >= 3) {
                $put($d, 'cashier', 'diagnosis_code', 'PROFILE_DRIFT', 1);
            }

            $put($d, 'provisioning', 'failures', SecurityEvent::SYSTEM_OGOS, $o);
            $put($d, 'provisioning', 'failures', SecurityEvent::SYSTEM_PUPTAPS, 0);
            if ($o > 0) {
                $put($d, 'provisioning', 'failures_by_reason', $isToday ? 'ogos_unreachable' : 'ogos_personal_info_unavailable', $o);
            }

            $put($d, 'labels', 'new_labels', '', $labels[$k]);
        }

        DB::transaction(function () use ($rows) {
            DB::table('system_alerts')->delete();
            DB::table('health_daily_metrics')->delete();

            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table('health_daily_metrics')->insert($chunk);
            }
        });

        // Replay detection exactly as the hourly job would have, silently.
        for ($i = 45; $i >= 0; $i--) {
            $detection->evaluateDate($today->subDays($i), notify: false);
        }

        $this->command?->info(sprintf(
            'Seeded %d metric rows over %d days; %d alert(s) raised (expected: only on %s).',
            count($rows), self::DAYS + 1, SystemAlert::count(), $today->toDateString(),
        ));
    }
}
