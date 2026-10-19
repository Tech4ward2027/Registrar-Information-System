<?php

use App\Contracts\NotificationServiceInterface;
use App\Models\SecurityEvent;
use App\Models\SystemUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

// Same lesson learned in SuperAdminAnalyticsControllerTest: the array
// cache store is not reset between tests within a process the way the
// database is by RefreshDatabase, and Cache::add()'s lock (used by
// SecurityEventLogger::maybeAlertOnBurst) is exactly the kind of
// cross-test state that bites. Flush before every test for isolation.
beforeEach(function () {
    Cache::flush();
});

// ═════════════════════════════════════════════════════════════════════════════
// LocalAuthService::attempt() — every failure branch writes a security_events row
// ═════════════════════════════════════════════════════════════════════════════

test('a bad password on local login is recorded as a security event', function () {
    $user = SystemUser::factory()->create([
        'role_id'            => SystemUser::ROLE_SUPER_ADMIN,
        'status'             => 'Activated',
        'password'           => bcrypt('CorrectPass1'),
        'local_auth_enabled' => 1,
    ]);

    $this->postJson('/api/v1/auth/local-login', [
        'email'    => $user->email,
        'password' => 'WrongPassword1',
    ])->assertStatus(401);

    $this->assertDatabaseHas('security_events', [
        'event_type' => SecurityEvent::EVENT_TYPE_LOGIN_FAILED,
        'reason'     => SecurityEvent::REASON_BAD_PASSWORD,
        'email'      => $user->email,
    ]);
});

test('a login attempt against a non-existent email is recorded without a target user', function () {
    $this->postJson('/api/v1/auth/local-login', [
        'email'    => 'nobody@example.com',
        'password' => 'whatever123',
    ])->assertStatus(401);

    $this->assertDatabaseHas('security_events', [
        'event_type' => SecurityEvent::EVENT_TYPE_LOGIN_FAILED,
        'reason'     => SecurityEvent::REASON_USER_NOT_FOUND,
        'email'      => 'nobody@example.com',
    ]);
});

test('a login attempt against an inactive account is recorded with the correct reason', function () {
    $user = SystemUser::factory()->create([
        'role_id'            => SystemUser::ROLE_SUPER_ADMIN,
        'status'             => 'Deactivated',
        'password'           => bcrypt('CorrectPass1'),
        'local_auth_enabled' => 1,
    ]);

    $this->postJson('/api/v1/auth/local-login', [
        'email'    => $user->email,
        'password' => 'CorrectPass1',
    ])->assertStatus(403);

    $this->assertDatabaseHas('security_events', [
        'event_type' => SecurityEvent::EVENT_TYPE_LOGIN_FAILED,
        'reason'     => SecurityEvent::REASON_INACTIVE_ACCOUNT,
        'email'      => $user->email,
    ]);
});

test('a successful local login does not write a security event', function () {
    $user = SystemUser::factory()->create([
        'role_id'            => SystemUser::ROLE_SUPER_ADMIN,
        'status'             => 'Activated',
        'password'           => bcrypt('CorrectPass1'),
        'local_auth_enabled' => 1,
    ]);

    $this->postJson('/api/v1/auth/local-login', [
        'email'    => $user->email,
        'password' => 'CorrectPass1',
    ])->assertOk();

    $this->assertDatabaseCount('security_events', 0);
});

// ═════════════════════════════════════════════════════════════════════════════
// Burst alerting (Phase 3e)
// ═════════════════════════════════════════════════════════════════════════════

test('crossing the failed-login threshold for one email fires exactly one alert', function () {
    config(['security_events.alert_threshold' => 3, 'security_events.alert_window_minutes' => 10]);

    $email = 'target@example.com';

    $this->mock(NotificationServiceInterface::class, function ($mock) use ($email) {
        $mock->shouldReceive('sendToAllExcept')
            ->once()
            ->withArgs(function ($excludedRoleIds, $triggerEvent, $data) use ($email) {
                return $triggerEvent === 'security_alert_failed_login_burst'
                    && in_array(SystemUser::ROLE_STUDENT, $excludedRoleIds, true)
                    && in_array(SystemUser::ROLE_ALUMNI, $excludedRoleIds, true)
                    && $data['email'] === $email
                    && $data['attempt_count'] >= 3;
            });
    });

    // 3 failed attempts against the same nonexistent email — threshold is 3,
    // so the alert should fire once on the 3rd attempt and NOT again on any
    // further attempt within the same window.
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/v1/auth/local-login', [
            'email'    => $email,
            'password' => 'wrong',
        ])->assertStatus(401);
    }

    $this->assertDatabaseCount('security_events', 5);
});

test('failed attempts below the threshold do not fire an alert', function () {
    config(['security_events.alert_threshold' => 5, 'security_events.alert_window_minutes' => 10]);

    $this->mock(NotificationServiceInterface::class, function ($mock) {
        $mock->shouldReceive('sendToAllExcept')->never();
    });

    for ($i = 0; $i < 2; $i++) {
        $this->postJson('/api/v1/auth/local-login', [
            'email'    => 'below-threshold@example.com',
            'password' => 'wrong',
        ])->assertStatus(401);
    }
});

test('failed attempts against different emails do not combine toward one alert', function () {
    config(['security_events.alert_threshold' => 3, 'security_events.alert_window_minutes' => 10]);

    $this->mock(NotificationServiceInterface::class, function ($mock) {
        $mock->shouldReceive('sendToAllExcept')->never();
    });

    foreach (['a@example.com', 'b@example.com', 'c@example.com'] as $email) {
        $this->postJson('/api/v1/auth/local-login', [
            'email'    => $email,
            'password' => 'wrong',
        ])->assertStatus(401);
    }
});

// ═════════════════════════════════════════════════════════════════════════════
// SecurityEvent model — write-once enforcement
// ═════════════════════════════════════════════════════════════════════════════

test('a security_events row cannot be updated', function () {
    $event = SecurityEvent::create([
        'event_type' => SecurityEvent::EVENT_TYPE_LOGIN_FAILED,
        'reason'     => SecurityEvent::REASON_BAD_PASSWORD,
        'email'      => 'test@example.com',
        'created_at' => now(),
    ]);

    expect(fn () => $event->update(['reason' => SecurityEvent::REASON_USER_NOT_FOUND]))
        ->toThrow(RuntimeException::class);
});

test('a security_events row cannot be individually deleted', function () {
    $event = SecurityEvent::create([
        'event_type' => SecurityEvent::EVENT_TYPE_LOGIN_FAILED,
        'reason'     => SecurityEvent::REASON_BAD_PASSWORD,
        'email'      => 'test@example.com',
        'created_at' => now(),
    ]);

    expect(fn () => $event->delete())->toThrow(RuntimeException::class);
});

test('a mass-delete query bypasses the individual-delete guard, as the retention job relies on', function () {
    SecurityEvent::create([
        'event_type' => SecurityEvent::EVENT_TYPE_LOGIN_FAILED,
        'reason'     => SecurityEvent::REASON_BAD_PASSWORD,
        'email'      => 'old@example.com',
        'created_at' => now()->subDays(100),
    ]);

    $deleted = SecurityEvent::where('created_at', '<', now()->subDays(90))->delete();

    expect($deleted)->toBe(1);
    $this->assertDatabaseCount('security_events', 0);
});

// ═════════════════════════════════════════════════════════════════════════════
// PruneSecurityEvents command (Phase 3h)
// ═════════════════════════════════════════════════════════════════════════════

test('the prune command deletes only rows older than the retention window', function () {
    config(['security_events.retention_days' => 90]);

    SecurityEvent::create([
        'event_type' => SecurityEvent::EVENT_TYPE_LOGIN_FAILED,
        'email'      => 'old@example.com',
        'created_at' => now()->subDays(100),
    ]);

    SecurityEvent::create([
        'event_type' => SecurityEvent::EVENT_TYPE_LOGIN_FAILED,
        'email'      => 'recent@example.com',
        'created_at' => now()->subDays(10),
    ]);

    Artisan::call('security-events:prune');

    $this->assertDatabaseMissing('security_events', ['email' => 'old@example.com']);
    $this->assertDatabaseHas('security_events', ['email' => 'recent@example.com']);
});

// ═════════════════════════════════════════════════════════════════════════════
// SecurityEventController — access control (role:4 only)
// ═════════════════════════════════════════════════════════════════════════════

test('a non-super-admin cannot list security events', function () {
    $admin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN]);

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/security-events')
        ->assertStatus(403);
});

test('an unauthenticated request cannot list security events', function () {
    $this->getJson('/api/v1/security-events')->assertStatus(401);
});

test('a super admin can list security events', function () {
    $superAdmin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN]);

    SecurityEvent::create([
        'event_type' => SecurityEvent::EVENT_TYPE_LOGIN_FAILED,
        'reason'     => SecurityEvent::REASON_BAD_PASSWORD,
        'email'      => 'someone@example.com',
        'created_at' => now(),
    ]);

    $this->actingAs($superAdmin, 'sanctum')
        ->getJson('/api/v1/security-events')
        ->assertOk()
        ->assertJsonPath('data.0.email', 'someone@example.com')
        ->assertJsonPath('meta.total', 1);
});

// ═════════════════════════════════════════════════════════════════════════════
// AuthController — IDP-unreachable fallback records a security event
// ═════════════════════════════════════════════════════════════════════════════

test('an IDP-unreachable fallback records a security event regardless of the local-auth outcome', function () {
    $this->mock(\App\Services\Sso\SsoAuthService::class, function ($mock) {
        $mock->shouldReceive('loginWithCredentials')
            ->once()
            ->andThrow(new \App\Exceptions\IdpUnavailableException('Connection refused'));
    });

    $this->postJson('/api/v1/login', [
        'email'    => 'someone@example.com',
        'password' => 'whatever123',
    ])->assertStatus(401);

    $this->assertDatabaseHas('security_events', [
        'event_type' => SecurityEvent::EVENT_TYPE_IDP_UNREACHABLE,
        'email'      => 'someone@example.com',
    ]);
});
