<?php

use App\Models\AuditLog;
use App\Models\SystemAlert;
use App\Models\SystemUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// Helpers are shAlert-prefixed because Pest helper functions are global.

beforeEach(fn () => config(['features.system_health' => true]));

function shActAs(int $roleId): SystemUser
{
    $user = SystemUser::factory()->create(['role_id' => $roleId, 'status' => 'Activated']);
    Sanctum::actingAs($user);

    return $user;
}

function shAlert(array $overrides = []): SystemAlert
{
    return SystemAlert::create(array_merge([
        'alert_key'       => 'count_spike:cashier:failures:-:' . uniqid(),
        'type'            => SystemAlert::TYPE_COUNT_SPIKE,
        'severity'        => SystemAlert::SEVERITY_WARNING,
        'source'          => 'cashier',
        'metric'          => 'failures',
        'dimension'       => '',
        'window_start'    => today()->toDateString(),
        'window_end'      => today()->toDateString(),
        'observed'        => 12,
        'baseline_median' => 1,
        'baseline_mad'    => 0,
        'score'           => null,
        'status'          => SystemAlert::STATUS_OPEN,
        'context'         => ['reason' => 'flat_baseline', 'baseline_days' => 28],
        'created_at'      => now(),
    ], $overrides));
}

$endpoints = [
    '/api/system-analytics/cashier-trend',
    '/api/system-analytics/provisioning-health',
    '/api/system-analytics/alerts',
];

test('system health endpoints reject unauthenticated, student and plain-admin callers', function () use ($endpoints) {
    foreach ($endpoints as $url) {
        $this->getJson($url)->assertUnauthorized();
    }

    shActAs(SystemUser::ROLE_STUDENT);
    foreach ($endpoints as $url) {
        $this->getJson($url)->assertForbidden();
    }

    shActAs(SystemUser::ROLE_ADMIN);
    foreach ($endpoints as $url) {
        $this->getJson($url)->assertForbidden();
    }
});

test('a plain admin cannot acknowledge an alert', function () {
    $alert = shAlert();
    shActAs(SystemUser::ROLE_ADMIN);

    $this->postJson("/api/system-analytics/alerts/{$alert->system_alert_id}/acknowledge")->assertForbidden();
    expect($alert->fresh()->status)->toBe(SystemAlert::STATUS_OPEN);
});

test('every system health route is 404 while the feature flag is off', function () use ($endpoints) {
    config(['features.system_health' => false]);
    $alert = shAlert();
    shActAs(SystemUser::ROLE_SUPER_ADMIN);

    foreach ($endpoints as $url) {
        $this->getJson($url)->assertNotFound();
    }
    $this->postJson("/api/system-analytics/alerts/{$alert->system_alert_id}/acknowledge")->assertNotFound();
});

test('alerts lists open alerts by default and filters by status', function () {
    shActAs(SystemUser::ROLE_SUPER_ADMIN);
    shAlert();
    shAlert(['status' => SystemAlert::STATUS_ACKNOWLEDGED]);

    $open = $this->getJson('/api/system-analytics/alerts')->assertOk();
    expect($open->json('data'))->toHaveCount(1)
        ->and($open->json('meta.total'))->toBe(1);

    $all = $this->getJson('/api/system-analytics/alerts?status=all')->assertOk();
    expect($all->json('data'))->toHaveCount(2);

    $this->getJson('/api/system-analytics/alerts?status=bogus')->assertUnprocessable();
});

test('acknowledge moves an open alert to acknowledged, records who, and writes an audit row', function () {
    $admin = shActAs(SystemUser::ROLE_SUPER_ADMIN);
    $alert = shAlert();

    $this->postJson("/api/system-analytics/alerts/{$alert->system_alert_id}/acknowledge")
        ->assertOk()
        ->assertJsonPath('data.status', SystemAlert::STATUS_ACKNOWLEDGED);

    $fresh = $alert->fresh();
    expect($fresh->status)->toBe(SystemAlert::STATUS_ACKNOWLEDGED)
        ->and($fresh->acknowledged_by)->toBe($admin->user_id)
        ->and($fresh->acknowledged_at)->not->toBeNull();

    $audit = AuditLog::where('action', AuditLog::ACTION_SYSTEM_ALERT_ACKNOWLEDGED)->sole();
    expect($audit->metadata)->toMatchArray(['alert_id' => $alert->system_alert_id, 'source' => 'cashier'])
        ->and($audit->metadata)->not->toHaveKey('email');
});

test('acknowledging twice, or an unknown alert, is rejected cleanly', function () {
    shActAs(SystemUser::ROLE_SUPER_ADMIN);
    $alert = shAlert();

    $this->postJson("/api/system-analytics/alerts/{$alert->system_alert_id}/acknowledge")->assertOk();
    $this->postJson("/api/system-analytics/alerts/{$alert->system_alert_id}/acknowledge")->assertStatus(409);
    $this->postJson('/api/system-analytics/alerts/999999/acknowledge')->assertNotFound();

    expect(AuditLog::where('action', AuditLog::ACTION_SYSTEM_ALERT_ACKNOWLEDGED)->count())->toBe(1);
});

test('cashier-trend returns a zero-filled series for the requested window and clamps days', function () {
    shActAs(SystemUser::ROLE_SUPER_ADMIN);

    $r = $this->getJson('/api/system-analytics/cashier-trend?days=7')->assertOk();
    expect($r->json('data.series'))->toHaveCount(7)
        ->and($r->json('data.series.0.failure_rate'))->toBeNull()
        ->and($r->json('data.unresolved_labels'))->toBe(0);

    $big = $this->getJson('/api/system-analytics/cashier-trend?days=5000')->assertOk();
    expect($big->json('data.series'))->toHaveCount(90);
});

test('provisioning-health returns per-system series', function () {
    shActAs(SystemUser::ROLE_SUPER_ADMIN);

    $r = $this->getJson('/api/system-analytics/provisioning-health?days=5')->assertOk();
    expect($r->json('data.series'))->toHaveCount(5)
        ->and($r->json('data.series.0'))->toHaveKeys(['date', 'ogos', 'puptaps']);
});
