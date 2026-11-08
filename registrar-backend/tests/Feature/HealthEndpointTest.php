<?php

use App\Models\JobRunLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    // No result caching, so each test probes live; no token unless a test sets one.
    config([
        'health.cache_ttl'            => 0,
        'health.token'                => null,
        'health.disk_min_free_percent' => 0, // never let a full CI disk flake the suite
    ]);
});

function healthToken(): string
{
    return str_repeat('a', 40);
}

test('public probe is reachable without authentication and reveals nothing else', function () {
    $response = $this->getJson('/api/health')
        ->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonStructure(['status', 'time'])
        ->assertJsonMissingPath('checks');

    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

test('detailed view is off when no token is configured', function () {
    $this->getJson('/api/health', ['X-Health-Token' => healthToken()])
        ->assertOk()
        ->assertJsonMissingPath('checks');
});

test('detailed view stays off for a configured token shorter than 32 characters', function () {
    config(['health.token' => 'short']);

    $this->getJson('/api/health', ['X-Health-Token' => 'short'])
        ->assertOk()
        ->assertJsonMissingPath('checks');
});

test('detailed view rejects a wrong token', function () {
    config(['health.token' => healthToken()]);

    $this->getJson('/api/health', ['X-Health-Token' => str_repeat('b', 40)])
        ->assertOk()
        ->assertJsonMissingPath('checks');
});

test('detailed view with the right token reports every check and flags missing job history', function () {
    config(['health.token' => healthToken()]);

    $this->getJson('/api/health', ['X-Health-Token' => healthToken()])
        ->assertOk()
        // No scheduled job has ever run in a fresh test database -> advisory warn.
        ->assertJsonPath('status', 'degraded')
        ->assertJsonPath('checks.database.status', 'ok')
        ->assertJsonPath('checks.cache.status', 'ok')
        ->assertJsonPath('checks.queue.status', 'ok')
        ->assertJsonPath('checks.scheduler.status', 'warn')
        ->assertJsonPath('checks.audit_chain.status', 'warn')
        ->assertJsonPath('checks.disk.status', 'ok');
});

test('detailed view is ok when the scheduler and audit verification ran recently', function () {
    config(['health.token' => healthToken()]);

    foreach (['audit:verify', 'role-assignments:expire'] as $job) {
        JobRunLog::create([
            'job_name'    => $job,
            'status'      => JobRunLog::STATUS_SUCCESS,
            'started_at'  => now()->subMinutes(5),
            'finished_at' => now()->subMinutes(4),
        ]);
    }

    $this->getJson('/api/health', ['X-Health-Token' => healthToken()])
        ->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('checks.audit_chain.last_result', JobRunLog::STATUS_SUCCESS);
});

test('a failed audit chain verification is reported as fail but does not take the site out of rotation', function () {
    config(['health.token' => healthToken()]);

    JobRunLog::create([
        'job_name'    => 'audit:verify',
        'status'      => JobRunLog::STATUS_FAILED,
        'started_at'  => now()->subMinutes(5),
        'finished_at' => now()->subMinutes(4),
    ]);

    $this->getJson('/api/health', ['X-Health-Token' => healthToken()])
        ->assertOk() // advisory checks never change the HTTP status
        ->assertJsonPath('status', 'degraded')
        ->assertJsonPath('checks.audit_chain.status', 'fail');
});

test('returns 503 when the database is unreachable, without leaking the error', function () {
    DB::partialMock()->shouldReceive('select')->andThrow(new RuntimeException('SQLSTATE[HY000] secret-host:3306 refused'));

    $response = $this->getJson('/api/health')
        ->assertStatus(503)
        ->assertJsonPath('status', 'unavailable');

    expect($response->getContent())->not->toContain('secret-host');
});

test('returns 503 when the cache store is unreachable', function () {
    // A real failure, not a mock: point the default cache at a Redis that
    // refuses connections immediately (port 1). Facade mocks of the cache
    // manager also break unrelated framework code that resolves it.
    config([
        'cache.default'                 => 'redis',
        'database.redis.client'         => 'predis',
        'database.redis.cache.host'     => '127.0.0.1',
        'database.redis.cache.port'     => 1,
        'database.redis.cache.password' => null,
    ]);

    $this->getJson('/api/health')
        ->assertStatus(503)
        ->assertJsonPath('status', 'unavailable');
});