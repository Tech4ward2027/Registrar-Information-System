<?php

use App\Models\SystemAlert;
use Carbon\CarbonImmutable;
use Database\Seeders\HealthDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'system_health.baseline_days'          => 28,
        'system_health.min_baseline_days'      => 7,
        'system_health.z_threshold'            => 3.5,
        'system_health.critical_z'             => 7.0,
        'system_health.min_count'              => 5,
        'system_health.flat_critical_count'    => 25,
        'system_health.rate_min_attempts'      => 10,
        'system_health.rate_min_baseline_days' => 5,
        'system_health.rate_min_delta'         => 0.15,
        'system_health.rate_critical_rate'     => 0.5,
        'system_health.count_series'           => [['cashier', 'failures'], ['labels', 'new_labels'], ['provisioning', 'failures']],
        'system_health.rate_series'            => [['source' => 'cashier', 'numerator' => 'failures', 'denominator' => 'attempts']],
    ]);
});

test('the demo seeder produces 90 days of history and alerts only on the injected day', function () {
    $this->seed(HealthDemoSeeder::class);

    $today  = CarbonImmutable::today()->toDateString();
    $alerts = SystemAlert::all();

    expect(DB::table('health_daily_metrics')->distinct()->count('metric_date'))->toBe(91)
        ->and($alerts)->not->toBeEmpty()
        ->and($alerts->every(fn ($a) => $a->window_end->toDateString() === $today))->toBeTrue()
        ->and($alerts->contains(fn ($a) => $a->source === 'cashier' && $a->metric === 'failures' && $a->severity === 'critical'))->toBeTrue()
        ->and($alerts->contains(fn ($a) => $a->source === 'provisioning'))->toBeTrue();
});

test('the demo seeder is repeatable and holds no personal data', function () {
    $this->seed(HealthDemoSeeder::class);
    $first = DB::table('health_daily_metrics')->count();

    $this->seed(HealthDemoSeeder::class);

    expect(DB::table('health_daily_metrics')->count())->toBe($first);
    foreach (SystemAlert::all() as $a) {
        expect(array_keys($a->context))->toEqualCanonicalizing(['reason', 'baseline_days']);
    }
});
