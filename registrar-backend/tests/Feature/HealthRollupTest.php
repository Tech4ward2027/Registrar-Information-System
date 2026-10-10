<?php

use App\Models\AuditLog;
use App\Models\HealthDailyMetric;
use App\Models\SecurityEvent;
use App\Models\SystemAlert;
use App\Models\UnmatchedCashierItem;
use App\Models\SystemUser;
use App\Services\Health\HealthRollupService;
use App\Services\Health\RepeatFailureWatch;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Helpers are p3-prefixed because Pest helper functions are global.

function p3Audit(string $action, array $meta, CarbonImmutable $at, ?int $userId = null): void
{
    AuditLog::create([
        'user_id'    => $userId,
        'email'      => 'system@ris.local',
        'role_name'  => 'student',
        'action'     => $action,
        'metadata'   => $meta,
        'prev_hash'  => null,
        'hash'       => str_repeat('0', 64),
        'created_at' => $at,
    ]);
}

function p3Verification(CarbonImmutable $at, bool $approved, ?string $reason = null, ?int $userId = null, array $extra = []): void
{
    p3Audit(AuditLog::ACTION_CASHIER_VERIFICATION, array_merge([
        'or_number'      => '12345',
        'attempts'       => [],
        'final_approved' => $approved,
        'is_mock'        => false,
        'failure_reason' => $approved ? null : $reason,
    ], $extra), $at, $userId);
}

function p3Metric(string $date, string $source, string $metric, string $dimension = ''): ?int
{
    $row = HealthDailyMetric::where('metric_date', $date)
        ->where('source', $source)->where('metric', $metric)->where('dimension', $dimension)->first();

    return $row?->count;
}

it('counts cashier attempts, failures, reasons and overrides, excluding mock rows', function () {
    $day = CarbonImmutable::today()->subDays(3)->setTime(12, 0);

    p3Verification($day, true);
    p3Verification($day, false, 'NOT_FOUND');
    p3Verification($day, false, 'NOT_FOUND');
    p3Verification($day, false, 'API_ERROR');
    p3Verification($day, false, null);                               // pre-Phase-2b row
    p3Verification($day, true, null, null, ['is_mock' => true]);     // excluded
    p3Audit(AuditLog::ACTION_CASHIER_VERIFICATION, [
        'method' => 'admin_override', 'final_approved' => true, 'is_mock' => false,
    ], $day);                                                        // override, not an attempt

    app(HealthRollupService::class)->rollupDate($day);

    $d = $day->toDateString();
    expect(p3Metric($d, 'cashier', 'attempts'))->toBe(5)
        ->and(p3Metric($d, 'cashier', 'failures'))->toBe(4)
        ->and(p3Metric($d, 'cashier', 'overrides'))->toBe(1)
        ->and(p3Metric($d, 'cashier', 'failures_by_reason', 'NOT_FOUND'))->toBe(2)
        ->and(p3Metric($d, 'cashier', 'failures_by_reason', 'API_ERROR'))->toBe(1)
        ->and(p3Metric($d, 'cashier', 'failures_by_reason', 'UNKNOWN'))->toBe(1);
});

it('counts each diagnosis code once per enrichment row', function () {
    $day = CarbonImmutable::today()->subDay()->setTime(9, 0);

    p3Audit(AuditLog::ACTION_CASHIER_VERIFICATION_ENRICHED,
        ['diagnosis_codes' => ['PROFILE_DRIFT', 'FMT_NON_ASCII', 'PROFILE_DRIFT']], $day);
    p3Audit(AuditLog::ACTION_CASHIER_VERIFICATION_ENRICHED,
        ['diagnosis_codes' => [['code' => 'PROFILE_DRIFT']]], $day);

    app(HealthRollupService::class)->rollupDate($day);

    expect(p3Metric($day->toDateString(), 'cashier', 'diagnosis_code', 'PROFILE_DRIFT'))->toBe(2)
        ->and(p3Metric($day->toDateString(), 'cashier', 'diagnosis_code', 'FMT_NON_ASCII'))->toBe(1);
});

it('is idempotent and absorbs late-arriving rows', function () {
    $day = CarbonImmutable::today()->setTime(8, 0);
    $svc = app(HealthRollupService::class);

    p3Verification($day, false, 'NOT_FOUND');
    $svc->rollupDate($day);
    $first = HealthDailyMetric::count();
    $svc->rollupDate($day);

    expect(HealthDailyMetric::count())->toBe($first)
        ->and(p3Metric($day->toDateString(), 'cashier', 'failures'))->toBe(1);

    p3Verification($day->addHour(), false, 'NOT_FOUND');   // late arrival
    $svc->rollupDate($day);

    expect(HealthDailyMetric::count())->toBe($first)
        ->and(p3Metric($day->toDateString(), 'cashier', 'failures'))->toBe(2);
});

it('writes zero rows for a quiet day so history start is recognised', function () {
    $day = CarbonImmutable::today()->subDays(5);

    app(HealthRollupService::class)->rollupDate($day);

    $d = $day->toDateString();
    expect(p3Metric($d, 'cashier', 'attempts'))->toBe(0)
        ->and(p3Metric($d, 'cashier', 'failures'))->toBe(0)
        ->and(p3Metric($d, 'provisioning', 'failures', 'ogos'))->toBe(0)
        ->and(p3Metric($d, 'provisioning', 'failures', 'puptaps'))->toBe(0)
        ->and(p3Metric($d, 'labels', 'new_labels'))->toBe(0);
});

it('groups provisioning failures by system and reason, and stores no personal data', function () {
    $day = CarbonImmutable::today()->subDay()->setTime(10, 0);

    foreach ([
        [SecurityEvent::REASON_OGOS_UNREACHABLE, 'a@example.com'],
        [SecurityEvent::REASON_OGOS_UNREACHABLE, 'b@example.com'],
        [SecurityEvent::REASON_OGOS_NOT_FOUND, 'c@example.com'],
        [SecurityEvent::REASON_ALUMNI_LOOKUP_FAILED, 'd@example.com'],
    ] as [$reason, $email]) {
        SecurityEvent::create([
            'event_type' => SecurityEvent::EVENT_TYPE_PROVISIONING_FAILED,
            'reason'     => $reason,
            'email'      => $email,
            'created_at' => $day,
        ]);
    }

    app(HealthRollupService::class)->rollupDate($day);

    $d = $day->toDateString();
    expect(p3Metric($d, 'provisioning', 'failures', 'ogos'))->toBe(3)
        ->and(p3Metric($d, 'provisioning', 'failures', 'puptaps'))->toBe(1)
        ->and(p3Metric($d, 'provisioning', 'failures_by_reason', 'ogos_unreachable'))->toBe(2);

    expect(HealthDailyMetric::where('dimension', 'like', '%@%')->count())->toBe(0);
});

it('counts new unmatched labels by first-seen day', function () {
    $day = CarbonImmutable::today()->subDays(2)->setTime(11, 0);

    UnmatchedCashierItem::create([
        'raw_label' => 'Cert of Enrollmnt', 'normalised_label' => 'cert of enrollmnt',
        'occurrence_count' => 1, 'first_seen_at' => $day, 'last_seen_at' => $day,
    ]);

    app(HealthRollupService::class)->rollupDate($day);

    expect(p3Metric($day->toDateString(), 'labels', 'new_labels'))->toBe(1);
});

it('does nothing while the system_health flag is off, and works with --force', function () {
    config(['features.system_health' => false]);

    $this->artisan('health:rollup')->assertSuccessful();
    expect(HealthDailyMetric::count())->toBe(0);

    $this->artisan('health:rollup --force')->assertSuccessful();
    expect(HealthDailyMetric::count())->toBeGreaterThan(0);
});

it('backfill then detect raises exactly the injected spike and nothing on normal days', function () {
    $today = CarbonImmutable::today();

    // 30 normal days: 10 attempts, 1 failure. Then a spike today: 10 attempts, 9 failures.
    for ($i = 30; $i >= 1; $i--) {
        $at = $today->subDays($i)->setTime(12, 0);
        p3Verification($at, false, 'NOT_FOUND');
        for ($k = 0; $k < 9; $k++) {
            p3Verification($at, true);
        }
    }
    $at = $today->setTime(6, 0);
    for ($k = 0; $k < 9; $k++) {
        p3Verification($at, false, 'NOT_FOUND');
    }
    p3Verification($at, true);

    $this->artisan('health:backfill --days=31 --force')->assertSuccessful();
    $this->artisan('health:detect --days=31 --no-notify --force')->assertSuccessful();

    expect(SystemAlert::where('window_end', '<', $today->toDateString())->count())->toBe(0)
        ->and(SystemAlert::where('window_end', $today->toDateString())->where('source', 'cashier')->count())
        ->toBeGreaterThan(0);
});

describe('repeat-failure watch', function () {
    it('alerts once per user at the threshold, with user id and counts only', function () {
        $user  = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT, 'status' => 'Activated']);
        $today = CarbonImmutable::today();

        foreach ([1, 2, 3] as $daysAgo) {
            p3Verification($today->subDays($daysAgo)->setTime(10, 0), false, 'NOT_FOUND', $user->user_id);
        }
        // Not failures: an override, a mock row and a success.
        p3Audit(AuditLog::ACTION_CASHIER_VERIFICATION, ['method' => 'admin_override', 'final_approved' => true], $today->setTime(9, 0), $user->user_id);
        p3Verification($today->setTime(9, 5), true, null, $user->user_id);

        $watch = app(RepeatFailureWatch::class);

        expect($watch->evaluate($today, false))->toBe(1)
            ->and($watch->evaluate($today, false))->toBe(0);   // deduped

        $alert = SystemAlert::where('type', SystemAlert::TYPE_REPEAT_FAILURE)->sole();

        expect($alert->context)->toBe([
            'user_id'               => $user->user_id,
            'cashier_failures'      => 3,
            'provisioning_failures' => 0,
            'window_days'           => 7,
        ])->and($alert->dimension)->toBe('user:' . $user->user_id);
    });

    it('does not alert below the threshold or outside the window', function () {
        $user  = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT, 'status' => 'Activated']);
        $today = CarbonImmutable::today();

        p3Verification($today->subDay()->setTime(10, 0), false, 'NOT_FOUND', $user->user_id);
        p3Verification($today->subDays(2)->setTime(10, 0), false, 'NOT_FOUND', $user->user_id);
        p3Verification($today->subDays(30)->setTime(10, 0), false, 'NOT_FOUND', $user->user_id); // outside window

        expect(app(RepeatFailureWatch::class)->evaluate($today, false))->toBe(0)
            ->and(SystemAlert::count())->toBe(0);
    });
});