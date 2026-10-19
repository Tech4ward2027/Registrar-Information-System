<?php

use App\Contracts\NotificationServiceInterface;
use App\Models\JobRunLog;
use App\Models\SystemAlert;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Pin every tunable so a host .env can never change the outcome.
    config([
        'system_health.baseline_days'        => 28,
        'system_health.min_baseline_days'    => 7,
        'system_health.z_threshold'          => 3.5,
        'system_health.critical_z'           => 7.0,
        'system_health.min_count'            => 5,
        'system_health.flat_critical_count'  => 25,
        'system_health.rate_min_attempts'    => 10,
        'system_health.rate_min_baseline_days' => 5,
        'system_health.rate_min_delta'       => 0.15,
        'system_health.rate_critical_rate'   => 0.5,
        'system_health.count_series'         => [['cashier', 'failures'], ['labels', 'new_labels']],
        'system_health.rate_series'          => [['source' => 'cashier', 'numerator' => 'failures', 'denominator' => 'attempts']],
    ]);
});

function p3Put(string $date, string $source, string $metric, int $count, string $dimension = ''): void
{
    DB::table('health_daily_metrics')->insert([
        'metric_date' => $date, 'source' => $source, 'metric' => $metric,
        'dimension'   => $dimension, 'count' => $count, 'computed_at' => now(),
    ]);
}

/** 45 normal days ending yesterday, then an injected spike today. */
function p3SeedSeriesWithSpike(int $spike = 40): CarbonImmutable
{
    $today   = CarbonImmutable::today();
    $pattern = [3, 4, 2, 5, 3, 4, 3];

    for ($i = 45; $i >= 1; $i--) {
        p3Put($today->subDays($i)->toDateString(), 'cashier', 'failures', $pattern[$i % 7]);
    }
    p3Put($today->toDateString(), 'cashier', 'failures', $spike);

    return $today;
}

function p3Notifier(int $times): void
{
    $mock = Mockery::mock(NotificationServiceInterface::class);
    $times === 0
        ? $mock->shouldReceive('sendToSuperAdmins')->never()
        : $mock->shouldReceive('sendToSuperAdmins')->times($times)
            ->with('system_health_alert', Mockery::on(fn ($d) => isset($d['severity'], $d['summary'], $d['alert_id'])));

    app()->instance(NotificationServiceInterface::class, $mock);
}

test('replaying history raises exactly the injected spike and nothing on normal days', function () {
    $today = p3SeedSeriesWithSpike();
    p3Notifier(1);

    $service = app(\App\Services\Health\HealthDetectionService::class);

    for ($i = 30; $i >= 0; $i--) {
        $service->evaluateDate($today->subDays($i));
    }

    $alerts = SystemAlert::all();

    expect($alerts)->toHaveCount(1)
        ->and($alerts->first()->window_end->toDateString())->toBe($today->toDateString())
        ->and($alerts->first()->type)->toBe(SystemAlert::TYPE_COUNT_SPIKE)
        ->and($alerts->first()->source)->toBe('cashier')
        ->and($alerts->first()->severity)->toBe('critical')
        ->and($alerts->first()->status)->toBe('open');
});

test('re-running detection does not duplicate the alert or re-notify', function () {
    $today = p3SeedSeriesWithSpike();
    p3Notifier(1);

    $service = app(\App\Services\Health\HealthDetectionService::class);

    $first  = $service->evaluateDate($today);
    $second = $service->evaluateDate($today);

    expect($first['raised'])->toBe(1)
        ->and($second['raised'])->toBe(0)
        ->and(SystemAlert::count())->toBe(1);
});

test('notify=false creates the alert without notifying anyone', function () {
    $today = p3SeedSeriesWithSpike();
    p3Notifier(0);

    app(\App\Services\Health\HealthDetectionService::class)->evaluateDate($today, notify: false);

    expect(SystemAlert::count())->toBe(1);
});

test('a notification failure never loses the alert', function () {
    $today = p3SeedSeriesWithSpike();

    $mock = Mockery::mock(NotificationServiceInterface::class);
    $mock->shouldReceive('sendToSuperAdmins')->andThrow(new RuntimeException('queue down'));
    app()->instance(NotificationServiceInterface::class, $mock);

    app(\App\Services\Health\HealthDetectionService::class)->evaluateDate($today);

    expect(SystemAlert::count())->toBe(1);
});

test('a series without enough history cannot alert', function () {
    $today = CarbonImmutable::today();
    foreach ([3, 2, 1] as $i) {
        p3Put($today->subDays($i)->toDateString(), 'cashier', 'failures', 3);
    }
    p3Put($today->toDateString(), 'cashier', 'failures', 60);
    p3Notifier(0);

    app(\App\Services\Health\HealthDetectionService::class)->evaluateDate($today);

    expect(SystemAlert::count())->toBe(0);
});

test('a failure-rate spike raises a rate alert', function () {
    $today = CarbonImmutable::today();

    for ($i = 30; $i >= 1; $i--) {
        $date = $today->subDays($i)->toDateString();
        p3Put($date, 'cashier', 'attempts', 20);
        p3Put($date, 'cashier', 'failures', $i % 2 === 0 ? 2 : 3);
    }
    p3Put($today->toDateString(), 'cashier', 'attempts', 30);
    p3Put($today->toDateString(), 'cashier', 'failures', 15);
    p3Notifier(2);   // rate spike + the matching count spike

    app(\App\Services\Health\HealthDetectionService::class)->evaluateDate($today);

    $rate = SystemAlert::where('type', SystemAlert::TYPE_RATE_SPIKE)->first();

    expect($rate)->not->toBeNull()
        ->and($rate->metric)->toBe('failures_rate')
        ->and($rate->observed)->toBe(0.5);
});

test('alert context holds no personal data', function () {
    $today = p3SeedSeriesWithSpike();
    p3Notifier(1);

    app(\App\Services\Health\HealthDetectionService::class)->evaluateDate($today);

    expect(array_keys(SystemAlert::first()->context))->toEqualCanonicalizing(['reason', 'baseline_days']);
});

test('health:detect is a logged no-op while the feature flag is off', function () {
    p3SeedSeriesWithSpike();
    config(['features.system_health' => false]);

    expect(Artisan::call('health:detect', ['--days' => 1]))->toBe(0);
    expect(SystemAlert::count())->toBe(0);

    $run = JobRunLog::where('job_name', 'health:detect')->latest('job_run_id')->first();
    expect($run->status)->toBe(JobRunLog::STATUS_SUCCESS);
});

test('health:detect with the flag on raises the alert and logs the run', function () {
    p3SeedSeriesWithSpike();
    config(['features.system_health' => true]);

    expect(Artisan::call('health:detect', ['--days' => 1, '--no-notify' => true]))->toBe(0);
    expect(SystemAlert::count())->toBe(1);

    $run = JobRunLog::where('job_name', 'health:detect')->latest('job_run_id')->first();
    expect($run->status)->toBe(JobRunLog::STATUS_SUCCESS)->and($run->rows_affected)->toBe(1);
});

test('health:detect --force runs even when the flag is off', function () {
    p3SeedSeriesWithSpike();
    config(['features.system_health' => false]);

    Artisan::call('health:detect', ['--days' => 1, '--no-notify' => true, '--force' => true]);

    expect(SystemAlert::count())->toBe(1);
});

test('health:prune removes only rows past their retention windows', function () {
    config(['system_health.retention.daily_metrics_days' => 30, 'system_health.retention.alerts_days' => 30]);

    p3Put(now()->subDays(60)->toDateString(), 'cashier', 'failures', 1);
    p3Put(now()->subDays(5)->toDateString(), 'cashier', 'failures', 1);

    foreach ([['old', 60], ['recent', 5]] as [$key, $age]) {
        DB::table('system_alerts')->insert([
            'alert_key' => $key, 'type' => 'count_spike', 'severity' => 'warning',
            'source' => 'cashier', 'metric' => 'failures', 'dimension' => '',
            'window_start' => now()->toDateString(), 'window_end' => now()->toDateString(),
            'observed' => 9, 'status' => 'open', 'created_at' => now()->subDays($age),
        ]);
    }

    expect(Artisan::call('health:prune'))->toBe(0);
    expect(DB::table('health_daily_metrics')->count())->toBe(1);
    expect(DB::table('system_alerts')->pluck('alert_key')->all())->toBe(['recent']);
});

test('system_health_alert notification type exists for the super admin audience', function () {
    $this->assertDatabaseHas('notification_types', [
        'trigger_event' => 'system_health_alert',
        'audience'      => 'super_admin',
        'is_active'     => 1,
    ]);
});

test('the new jobs are registered in Scheduled Jobs Health', function () {
    foreach (['health:detect', 'health:prune'] as $job) {
        expect(JobRunLog::JOBS)->toHaveKey($job)
            ->and(JobRunLog::EXPECTED_INTERVAL_MINUTES)->toHaveKey($job);
    }
});
