<?php

use App\Models\SystemUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

// Item 9.10 — per-IP + per-account login limiters
// (App\Providers\AuthRateLimitServiceProvider, config/login_throttle.php).

beforeEach(function () {
    // The array cache store is not reset between tests the way the database
    // is, and rate-limiter counters live in it.
    Cache::flush();
});

/** POST to an auth endpoint as if coming from $ip. */
function loginThrottleAttempt($test, string $path, string $email, string $ip)
{
    return $test
        ->withServerVariables(['REMOTE_ADDR' => $ip])
        ->postJson($path, ['email' => $email, 'password' => 'WrongPassword1']);
}

test('local-login is throttled per account even when every attempt comes from a different IP', function () {
    config([
        'login_throttle.local_login.per_ip_per_minute'      => 1000,
        'login_throttle.local_login.per_account_per_minute' => 3,
        'login_throttle.local_login.per_account_per_hour'   => 1000,
    ]);

    foreach ([1, 2, 3] as $i) {
        expect(loginThrottleAttempt($this, '/api/v1/auth/local-login', 'victim@example.com', "10.1.0.$i")->status())
            ->not->toBe(429);
    }

    // 4th attempt from a brand-new address: the account bucket is exhausted.
    loginThrottleAttempt($this, '/api/v1/auth/local-login', 'victim@example.com', '10.1.0.99')
        ->assertStatus(429);
});

test('the account bucket ignores letter case in the e-mail', function () {
    config([
        'login_throttle.local_login.per_ip_per_minute'      => 1000,
        'login_throttle.local_login.per_account_per_minute' => 2,
        'login_throttle.local_login.per_account_per_hour'   => 1000,
    ]);

    // Case is normalised by the limiter itself.
    loginThrottleAttempt($this, '/api/v1/auth/local-login', 'Case@Example.com', '10.2.0.1');
    loginThrottleAttempt($this, '/api/v1/auth/local-login', 'case@example.com', '10.2.0.2');

    loginThrottleAttempt($this, '/api/v1/auth/local-login', 'CASE@EXAMPLE.COM', '10.2.0.3')
        ->assertStatus(429);
});

test('throttling one account does not block a different account', function () {
    config([
        'login_throttle.local_login.per_ip_per_minute'      => 1000,
        'login_throttle.local_login.per_account_per_minute' => 1,
        'login_throttle.local_login.per_account_per_hour'   => 1000,
    ]);

    loginThrottleAttempt($this, '/api/v1/auth/local-login', 'locked@example.com', '10.3.0.1');
    loginThrottleAttempt($this, '/api/v1/auth/local-login', 'locked@example.com', '10.3.0.2')
        ->assertStatus(429);

    expect(loginThrottleAttempt($this, '/api/v1/auth/local-login', 'other@example.com', '10.3.0.3')->status())
        ->not->toBe(429);
});

test('the per-IP bucket still caps a single source that rotates through many accounts', function () {
    config([
        'login_throttle.local_login.per_ip_per_minute'      => 2,
        'login_throttle.local_login.per_account_per_minute' => 1000,
        'login_throttle.local_login.per_account_per_hour'   => 1000,
    ]);

    loginThrottleAttempt($this, '/api/v1/auth/local-login', 'a@example.com', '10.4.0.1');
    loginThrottleAttempt($this, '/api/v1/auth/local-login', 'b@example.com', '10.4.0.1');

    loginThrottleAttempt($this, '/api/v1/auth/local-login', 'c@example.com', '10.4.0.1')
        ->assertStatus(429);
});

test('/login and /auth/local-login keep separate buckets', function () {
    config([
        'login_throttle.login.per_ip_per_minute'            => 1000,
        'login_throttle.login.per_account_per_minute'       => 1,
        'login_throttle.login.per_account_per_hour'         => 1000,
        'login_throttle.local_login.per_ip_per_minute'      => 1000,
        'login_throttle.local_login.per_account_per_minute' => 1000,
        'login_throttle.local_login.per_account_per_hour'   => 1000,
    ]);

    loginThrottleAttempt($this, '/api/v1/login', 'shared@example.com', '10.5.0.1');
    loginThrottleAttempt($this, '/api/v1/login', 'shared@example.com', '10.5.0.2')
        ->assertStatus(429);

    expect(loginThrottleAttempt($this, '/api/v1/auth/local-login', 'shared@example.com', '10.5.0.3')->status())
        ->not->toBe(429);
});
