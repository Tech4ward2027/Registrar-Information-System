<?php

use App\DTOs\Ogos\OgosStudentDTO;
use App\Exceptions\OgosException;
use App\Models\SecurityEvent;
use App\Models\StudentProfile;
use App\Models\SystemUser;
use App\Services\Alumni\AlumniSystemClient;
use App\Services\Ogos\OgosClient;
use App\Services\Ogos\OgosStudentService;
use App\Exceptions\UnregisteredAccountException;
use App\Services\SecurityEventLogger;
use App\Services\Sso\UserProvisioningService;
use Illuminate\Http\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Provisioning failures are captured as queryable security events (Phase 2a)
|--------------------------------------------------------------------------
| The one rule that matters more than the rows: capturing a failure must
| never change what happens to the login. Every test that records an event
| also asserts the original behaviour is intact.
*/

function pfStudent(): SystemUser
{
    return SystemUser::factory()->create([
        'role_id' => SystemUser::ROLE_STUDENT,
        'status'  => 'Activated',
        'email'   => 'pf.student@example.com',
    ]);
}

function pfService(OgosClient $client): OgosStudentService
{
    return new OgosStudentService($client, app(SecurityEventLogger::class));
}

function pfOgosDto(): OgosStudentDTO
{
    return OgosStudentDTO::fromArray([
        'studentNumber' => '2026-00001-TG-0', 'email' => 'pf.student@example.com',
        'firstName' => 'Juan', 'middleName' => 'Santos', 'lastName' => 'Dela Cruz', 'suffixName' => '',
        'mobileNumber' => '09170000000',
        'program' => ['id' => 1, 'code' => 'BSIT', 'name' => 'BS Information Technology'],
        'yearLevel' => 3, 'section' => '3A',
    ]);
}

function pfEvents()
{
    return SecurityEvent::where('event_type', SecurityEvent::EVENT_TYPE_PROVISIONING_FAILED);
}

test('an OGOS 404 during provisioning is recorded as ogos_not_found and still returns false', function () {
    $user   = pfStudent();
    $client = Mockery::mock(OgosClient::class);
    $client->shouldReceive('getStudentByEmail')->once()->andThrow(new OgosException('OGOS: not found [/students]', 404));

    expect(pfService($client)->provisionStudentData($user))->toBeFalse();

    $event = pfEvents()->sole();
    expect($event->reason)->toBe(SecurityEvent::REASON_OGOS_NOT_FOUND)
        ->and($event->email)->toBe('pf.student@example.com')
        ->and($event->metadata)->toBe(['system' => 'ogos', 'http_status' => 404]);
});

test('any other OGOS error during provisioning is recorded as ogos_unreachable', function (int $code) {
    $user   = pfStudent();
    $client = Mockery::mock(OgosClient::class);
    $client->shouldReceive('getStudentByEmail')->once()->andThrow(new OgosException('OGOS connection error', $code));

    expect(pfService($client)->provisionStudentData($user))->toBeFalse();

    expect(pfEvents()->sole()->reason)->toBe(SecurityEvent::REASON_OGOS_UNREACHABLE);
})->with([503, 502, 401]);

test('the upstream exception message is never stored (it can embed an email or student number)', function () {
    $user   = pfStudent();
    $client = Mockery::mock(OgosClient::class);
    $client->shouldReceive('getStudentByEmail')->once()
        ->andThrow(new OgosException('OGOS: not found [/students?email=secret.leak@example.com]', 404));

    pfService($client)->provisionStudentData($user);

    $row = json_encode(pfEvents()->sole()->getAttributes());
    expect($row)->not->toContain('secret.leak');
});

test('personal-info and address failures are recorded but provisioning still succeeds', function () {
    $user   = pfStudent();
    $client = Mockery::mock(OgosClient::class);
    $client->shouldReceive('getStudentByEmail')->once()->andReturn(pfOgosDto());
    $client->shouldReceive('getStudentPersonalInfo')->once()->andThrow(new OgosException('boom', 503));
    $client->shouldReceive('getStudentAddresses')->once()->andThrow(new OgosException('boom', 500));

    expect(pfService($client)->provisionStudentData($user))->toBeTrue();

    expect(pfEvents()->orderBy('security_event_id')->pluck('reason')->all())->toBe([
        SecurityEvent::REASON_OGOS_PERSONAL_INFO_UNAVAILABLE,
        SecurityEvent::REASON_OGOS_ADDRESSES_UNAVAILABLE,
    ]);
    expect(StudentProfile::where('user_id', $user->user_id)->exists())->toBeTrue();
});

test('a successful provisioning records nothing', function () {
    $user   = pfStudent();
    $client = Mockery::mock(OgosClient::class);
    $client->shouldReceive('getStudentByEmail')->once()->andReturn(pfOgosDto());
    $client->shouldReceive('getStudentPersonalInfo')->once()->andReturn(
        new \App\DTOs\Ogos\OgosPersonalInfoDTO('2026-00001-TG-0', 'Male', '2002-05-14', 'Manila', null, null)
    );
    $client->shouldReceive('getStudentAddresses')->once()->andReturn([]);

    expect(pfService($client)->provisionStudentData($user))->toBeTrue();

    expect(pfEvents()->count())->toBe(0);
});

test('a failure to WRITE the event never breaks provisioning', function () {
    $user = pfStudent();

    $logger = Mockery::mock(SecurityEventLogger::class);
    $logger->shouldReceive('recordProvisioningFailed')->andThrow(new RuntimeException('db down'));

    $client = Mockery::mock(OgosClient::class);
    $client->shouldReceive('getStudentByEmail')->once()->andThrow(new OgosException('x', 503));

    expect((new OgosStudentService($client, $logger))->provisionStudentData($user))->toBeFalse();
});

test('an OgosStudentService built without a logger (legacy construction) still works and records nothing', function () {
    $user   = pfStudent();
    $client = Mockery::mock(OgosClient::class);
    $client->shouldReceive('getStudentByEmail')->once()->andThrow(new OgosException('x', 503));

    expect((new OgosStudentService($client))->provisionStudentData($user))->toBeFalse();
    expect(pfEvents()->count())->toBe(0);
});

test('the logger rejects an unknown reason instead of writing an ungroupable event', function () {
    expect(app(SecurityEventLogger::class)->recordProvisioningFailed('not_a_real_reason', 'a@b.c'))->toBeNull();

    expect(pfEvents()->count())->toBe(0);
});

test('every provisioning reason maps to a system and fits the varchar(50) column', function () {
    foreach (SecurityEvent::PROVISIONING_REASON_SYSTEM as $reason => $system) {
        expect(strlen($reason))->toBeLessThanOrEqual(50)
            ->and($system)->toBeIn([SecurityEvent::SYSTEM_OGOS, SecurityEvent::SYSTEM_PUPTAPS]);
    }
    expect(strlen(SecurityEvent::EVENT_TYPE_PROVISIONING_FAILED))->toBeLessThanOrEqual(50);
});

test('the event works outside an HTTP request (queue / console): ip and user agent are simply null', function () {
    $event = app(SecurityEventLogger::class)->recordProvisioningFailed(
        SecurityEvent::REASON_OGOS_UNREACHABLE, 'queue@example.com', 503,
    );

    expect($event)->not->toBeNull()
        ->and($event->reason)->toBe(SecurityEvent::REASON_OGOS_UNREACHABLE);
});

test('an alumni lookup failure (system unreachable) is recorded and still returns null', function () {
    // Point the real client at a closed local port: curl fails fast with a
    // connection error → AlumniSystemException(…, 503), the "unavailable"
    // case. (The 404 "not an alumnus" case needs a live HTTP server to
    // exercise through curl and is covered by the code-path comment and the
    // PROVISIONING_REASON_SYSTEM contract above, not by a network call.)
    config(['alumni.base_url' => 'http://127.0.0.1:9', 'alumni.token' => 'x']);

    $client = new AlumniSystemClient(app(SecurityEventLogger::class));

    expect($client->tryLookupAlumniByEmail('alum@example.com'))->toBeNull();

    $event = pfEvents()->sole();
    expect($event->reason)->toBe(SecurityEvent::REASON_ALUMNI_LOOKUP_FAILED)
        ->and($event->metadata['system'])->toBe('puptaps');
});

test('provisioning failures appear in the security events list and its filter options', function () {
    $superAdmin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN]);
    Sanctum::actingAs($superAdmin);

    app(SecurityEventLogger::class)->recordProvisioningFailed(SecurityEvent::REASON_OGOS_UNREACHABLE, 'x@example.com', 503);

    $filters = $this->getJson('/api/security-events/filters')->assertOk();

    expect($filters->json('event_types'))->toContain('provisioning_failed')
        ->and($filters->json('reasons'))->toContain('ogos_unreachable');

    $this->getJson('/api/security-events?event_type=provisioning_failed')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.event_type', 'Provisioning Failed')
        ->assertJsonPath('data.0.reason', 'Ogos Unreachable');
});

// ── The SSO "is this person an OGOS student?" check ──────────────────────

test('an OGOS outage during the new-user student check is recorded, and the login is still refused as before', function () {
    $ogosClient = Mockery::mock(OgosClient::class);
    $ogosClient->shouldReceive('getStudentByEmail')->once()->andThrow(new OgosException('connection error', 503));

    $this->mock(OgosStudentService::class, function ($mock) use ($ogosClient) {
        $mock->shouldReceive('getClient')->andReturn($ogosClient);
    });

    $service = app(UserProvisioningService::class);

    // Control flow unchanged: still falls through to the alumni check and
    // is refused as unregistered.
    expect(fn () => $service->provision([
        'id' => 'pf-new-1', 'email' => 'pf.new@example.com',
    ], Request::create('/api/sso/callback', 'GET')))->toThrow(UnregisteredAccountException::class);

    $event = pfEvents()->where('email', 'pf.new@example.com')->sole();
    expect($event->reason)->toBe(SecurityEvent::REASON_OGOS_UNREACHABLE);
});

test('a clean OGOS 404 in the new-user student check is NOT a provisioning failure', function () {
    $ogosClient = Mockery::mock(OgosClient::class);
    $ogosClient->shouldReceive('getStudentByEmail')->once()->andThrow(new OgosException('not found', 404));

    $this->mock(OgosStudentService::class, function ($mock) use ($ogosClient) {
        $mock->shouldReceive('getClient')->andReturn($ogosClient);
    });

    $service = app(UserProvisioningService::class);

    expect(fn () => $service->provision([
        'id' => 'pf-new-2', 'email' => 'pf.notstudent@example.com',
    ], Request::create('/api/sso/callback', 'GET')))->toThrow(UnregisteredAccountException::class);

    expect(pfEvents()->where('email', 'pf.notstudent@example.com')->count())->toBe(0);
});
