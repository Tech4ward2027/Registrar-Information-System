<?php

use App\Contracts\AlumniSystemClientInterface;
use App\DTOs\Alumni\AlumniDTO;
use App\Exceptions\OgosException;
use App\Exceptions\UnregisteredAccountException;
use App\Models\Alumni;
use App\Models\AlumniAcademicRecord;
use App\Models\AlumniProfile;
use App\Models\SystemUser;
use App\Services\Ogos\OgosClient;
use App\Services\Ogos\OgosStudentService;
use App\Services\Sso\UserProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

// ── Local helpers ──────────────────────────────────────────────────────────
// Deliberately self-contained (not reused from UserProvisioningServiceTest.php)
// so this file never depends on PHPUnit/Pest's file discovery order to have
// another test file's global function already loaded. Shared helpers that
// need to be safe across files belong in tests/Pest.php, not in a sibling
// test file.

function alumniSsoRequest(): Request
{
    return Request::create('/api/auth/callback', 'POST');
}

/**
 * Mocks OgosStudentService so the "not pre-registered" branch in
 * UserProvisioningService::provision() falls through past the OGOS check
 * (as if this email has no OGOS student record) and reaches the PUPTAPS
 * check next.
 */
function mockOgosMiss(Tests\TestCase $test, string $email): void
{
    $ogosClient = Mockery::mock(OgosClient::class);
    $ogosClient->shouldReceive('getStudentByEmail')
        ->once()
        ->with($email)
        ->andThrow(new OgosException('not found in OGOS'));

    $ogosStudentService = Mockery::mock(OgosStudentService::class);
    $ogosStudentService->shouldReceive('getClient')->once()->andReturn($ogosClient);

    // TestCase::mock() is protected, so it can't be called from this
    // global-scope helper function even with a $test instance in hand —
    // PHP's protected visibility is enforced by the calling *scope*, not
    // by whether the caller happens to hold a valid instance. $test->app
    // is protected too, for the same reason. app() is a plain global
    // helper function (not a TestCase member), so it sidesteps the
    // visibility issue entirely while binding into the same container.
    app()->instance(OgosStudentService::class, $ogosStudentService);
}

function sampleAlumniDto(string $email, array $overrides = []): AlumniDTO
{
    $defaults = [
        'alumniId'      => 501,
        'studNumber'    => '2018-00123-MN-0',
        'lastName'      => 'Dela Cruz',
        'firstName'     => 'Juan',
        'middleName'    => 'Reyes',
        'suffix'        => null,
        'courseId'      => 'BSIT',
        'courseDesc'    => 'Bachelor of Science in Information Technology',
        'batch'         => 2022,
        'yearGraduated' => '2022',
        // /alumni/lookup never returns these — the login-time token isn't
        // granted the alumni:sensitive ability. Real callers always get
        // null here; tests should not fabricate values for these two.
        'sex'           => null,
        'birthday'      => null,
        'email'         => $email,
        'number'        => '09171234567',
        'profileStatus' => 'Verified',
    ];

    $fields = array_merge($defaults, $overrides);

    return new AlumniDTO(...$fields);
}

// ═════════════════════════════════════════════════════════════════════════════
// First-time login: brand-new alumnus confirmed by PUPTAPS
// ═════════════════════════════════════════════════════════════════════════════

test('a new alumnus confirmed by PUPTAPS is auto-registered as ROLE_ALUMNI with profile and academic data', function () {
    $email = 'juan.delacruz@example.com';

    mockOgosMiss($this, $email);

    $dto = sampleAlumniDto($email);

    $alumniClient = Mockery::mock(AlumniSystemClientInterface::class);
    $alumniClient->shouldReceive('tryLookupAlumniByEmail')
        ->once()
        ->with($email)
        ->andReturn($dto);
    $this->app->instance(AlumniSystemClientInterface::class, $alumniClient);

    $result = app(UserProvisioningService::class)->provision(
        ['id' => 'idp-alumni-501', 'email' => $email],
        alumniSsoRequest(),
    );

    expect($result->user->role_id)->toBe(SystemUser::ROLE_ALUMNI);
    expect($result->needsOnboarding)->toBeTrue();

    $alumni = Alumni::where('user_id', $result->user->user_id)->first();
    expect($alumni)->not->toBeNull();
    // Confirmed by PUPTAPS -> SIS, not the NON_SIS default firstOrCreate() writes.
    expect($alumni->alumni_type_id)->toBe(Alumni::TYPE_SIS);

    $profile = AlumniProfile::where('alumni_id', $alumni->alumni_id)->first();
    expect($profile)->not->toBeNull();
    expect($profile->first_name)->toBe('Juan');
    expect($profile->middle_name)->toBe('Reyes');
    expect($profile->last_name)->toBe('Dela Cruz');

    $record = AlumniAcademicRecord::where('alumni_profile_id', $profile->alumni_profile_id)->first();
    expect($record)->not->toBeNull();
    expect($record->student_number)->toBe('2018-00123-MN-0');
    // year_of_graduation is written from $dto->batch (int), not
    // $dto->yearGraduated (string) — see AlumniProvisioningService::
    // resolveYearOfGraduation()'s doc comment for why. Assert the int.
    expect($record->year_of_graduation)->toBe(2022);
    // courseDesc is preferred over the raw courseId when present.
    expect($record->course)->toBe('Bachelor of Science in Information Technology');
});

test('PUPTAPS is only called once for a new alumnus login, not once for the existence check and again for provisioning', function () {
    $email = 'once-only@example.com';

    mockOgosMiss($this, $email);

    $dto = sampleAlumniDto($email, ['alumniId' => 777]);

    $alumniClient = Mockery::mock(AlumniSystemClientInterface::class);
    $alumniClient->shouldReceive('tryLookupAlumniByEmail')
        ->once() // the whole point of $prefetchedAlumniDto — regression guard
        ->with($email)
        ->andReturn($dto);
    $this->app->instance(AlumniSystemClientInterface::class, $alumniClient);

    app(UserProvisioningService::class)->provision(
        ['id' => 'idp-once-only', 'email' => $email],
        alumniSsoRequest(),
    );

    // Mockery::once() above already fails the test if this was called twice
    // or zero times; this assertion just documents the expected outcome.
    expect(true)->toBeTrue();
});

test('date_of_birth and sex_at_birth are written as documented placeholders, never left null, on first alumni provisioning', function () {
    // Regression guard for the NOT NULL bug: alumni_profile.date_of_birth
    // and sex_at_birth have no default and are not nullable in the schema.
    // /alumni/lookup never returns real values for either field.
    $email = 'placeholder-check@example.com';

    mockOgosMiss($this, $email);

    $alumniClient = Mockery::mock(AlumniSystemClientInterface::class);
    $alumniClient->shouldReceive('tryLookupAlumniByEmail')
        ->once()
        ->andReturn(sampleAlumniDto($email));
    $this->app->instance(AlumniSystemClientInterface::class, $alumniClient);

    $result = app(UserProvisioningService::class)->provision(
        ['id' => 'idp-placeholder', 'email' => $email],
        alumniSsoRequest(),
    );

    $alumni  = Alumni::where('user_id', $result->user->user_id)->first();
    $profile = AlumniProfile::where('alumni_id', $alumni->alumni_id)->first();

    expect($profile->date_of_birth)->not->toBeNull();
    expect($profile->sex_at_birth)->not->toBeNull();
    expect($profile->sex_at_birth)->toBeIn(['Male', 'Female']); // must satisfy the DB enum
});

// ═════════════════════════════════════════════════════════════════════════════
// Not found anywhere: neither RIS, OGOS, nor PUPTAPS know this email
// ═════════════════════════════════════════════════════════════════════════════

test('an email not found in RIS, OGOS, or PUPTAPS is rejected and nothing is written', function () {
    $email = 'nobody-knows-this-one@example.com';

    mockOgosMiss($this, $email);

    $alumniClient = Mockery::mock(AlumniSystemClientInterface::class);
    $alumniClient->shouldReceive('tryLookupAlumniByEmail')
        ->once()
        ->with($email)
        ->andReturn(null);
    $this->app->instance(AlumniSystemClientInterface::class, $alumniClient);

    expect(fn () => app(UserProvisioningService::class)->provision(
        ['id' => 'idp-nobody', 'email' => $email],
        alumniSsoRequest(),
    ))->toThrow(UnregisteredAccountException::class);

    $this->assertDatabaseMissing('users', ['email' => $email]);
    expect(Alumni::count())->toBe(0);
});

// ═════════════════════════════════════════════════════════════════════════════
// Deny-by-default regression guard: admin-tier IdP accounts must never reach
// the PUPTAPS check, mirroring the existing OGOS regression test.
// ═════════════════════════════════════════════════════════════════════════════

test('an admin-tier IdP login with no matching RIS record is denied before PUPTAPS is ever consulted', function () {
    $this->mock(OgosStudentService::class, function ($mock) {
        $mock->shouldNotReceive('getClient');
    });

    $alumniClient = Mockery::mock(AlumniSystemClientInterface::class);
    $alumniClient->shouldNotReceive('tryLookupAlumniByEmail');
    $this->app->instance(AlumniSystemClientInterface::class, $alumniClient);

    expect(fn () => app(UserProvisioningService::class)->provision([
        'id'    => 'sysadmin-with-puptaps-email',
        'email' => 'coincidental-alumnus@example.com',
        'roles' => 'Admin',
    ], alumniSsoRequest()))->toThrow(UnregisteredAccountException::class);
});

// ═════════════════════════════════════════════════════════════════════════════
// Re-login: existing alumnus, PUPTAPS confirms them again on a later login
// ═════════════════════════════════════════════════════════════════════════════

test('re-login for an existing alumnus with a real date_of_birth does not clobber it back to the placeholder', function () {
    $user = SystemUser::factory()->create([
        'role_id'     => SystemUser::ROLE_ALUMNI,
        'status'      => 'Activated',
        'idp_user_id' => 'existing-alumnus-idp',
    ]);

    $alumni = Alumni::create([
        'user_id'        => $user->user_id,
        'alumni_type_id' => Alumni::TYPE_SIS,
    ]);

    // Simulates a real value that was captured by a later, separate,
    // on-demand call to PUPTAPS's alumni:sensitive-tier endpoint — NOT a
    // value that ever came from /alumni/lookup.
    $realDob = '1998-04-12';
    AlumniProfile::create([
        'alumni_id'     => $alumni->alumni_id,
        'first_name'    => 'Maria',
        'last_name'     => 'Santos',
        'date_of_birth' => $realDob,
        'sex_at_birth'  => 'Female',
    ]);

    // roleId already resolves from the existing SystemUser row, so the
    // not-pre-registered branch (and therefore OGOS/PUPTAPS existence
    // checks) never runs — provisionAlumniProfile() calls PUPTAPS directly
    // with no prefetched DTO.
    $alumniClient = Mockery::mock(AlumniSystemClientInterface::class);
    $alumniClient->shouldReceive('tryLookupAlumniByEmail')
        ->once()
        ->with($user->email)
        ->andReturn(sampleAlumniDto($user->email, [
            'firstName' => 'Maria',
            'lastName'  => 'Santos',
        ]));
    $this->app->instance(AlumniSystemClientInterface::class, $alumniClient);

    app(UserProvisioningService::class)->provision(
        ['id' => 'existing-alumnus-idp', 'email' => $user->email],
        alumniSsoRequest(),
    );

    $profile = AlumniProfile::where('alumni_id', $alumni->alumni_id)->first();
    expect($profile->date_of_birth)->toBe($realDob);
    expect($profile->sex_at_birth)->toBe('Female');
});

// ═════════════════════════════════════════════════════════════════════════════
// Graceful degradation: PUPTAPS unreachable, mirroring the OGOS-down
// fallback contract in provisionStudentProfile().
// ═════════════════════════════════════════════════════════════════════════════

test('a pre-registered alumni whose first login hits an unreachable PUPTAPS still logs in with a placeholder stub profile', function () {
    $user = SystemUser::factory()->create([
        'role_id'     => SystemUser::ROLE_ALUMNI,
        'status'      => 'Activated',
        'idp_user_id' => 'pre-registered-alumnus',
    ]);

    // AlumniSystemClientInterface contract: never throws, returns null when
    // the source is unreachable — simulate that directly rather than an
    // exception, since the interface guarantees callers never see one.
    $alumniClient = Mockery::mock(AlumniSystemClientInterface::class);
    $alumniClient->shouldReceive('tryLookupAlumniByEmail')
        ->once()
        ->andReturn(null);
    $this->app->instance(AlumniSystemClientInterface::class, $alumniClient);

    $result = app(UserProvisioningService::class)->provision(
        ['id' => 'pre-registered-alumnus', 'email' => $user->email],
        alumniSsoRequest(),
    );

    // Login must still succeed.
    expect($result->user->user_id)->toBe($user->user_id);

    $alumni  = Alumni::where('user_id', $user->user_id)->first();
    $profile = AlumniProfile::where('alumni_id', $alumni->alumni_id)->first();

    expect($profile)->not->toBeNull();
    expect($profile->date_of_birth)->not->toBeNull();
    expect($profile->sex_at_birth)->not->toBeNull();
});

test('a subsequent login while PUPTAPS is unreachable leaves an already-provisioned profile untouched', function () {
    $user = SystemUser::factory()->create([
        'role_id'     => SystemUser::ROLE_ALUMNI,
        'status'      => 'Activated',
        'idp_user_id' => 'already-provisioned-alumnus',
    ]);

    $alumni = Alumni::create([
        'user_id'        => $user->user_id,
        'alumni_type_id' => Alumni::TYPE_SIS,
    ]);

    AlumniProfile::create([
        'alumni_id'     => $alumni->alumni_id,
        'first_name'    => 'Existing',
        'last_name'     => 'Alumnus',
        'date_of_birth' => '1999-01-01',
        'sex_at_birth'  => 'Male',
    ]);

    $alumniClient = Mockery::mock(AlumniSystemClientInterface::class);
    $alumniClient->shouldReceive('tryLookupAlumniByEmail')->once()->andReturn(null);
    $this->app->instance(AlumniSystemClientInterface::class, $alumniClient);

    app(UserProvisioningService::class)->provision(
        ['id' => 'already-provisioned-alumnus', 'email' => $user->email],
        alumniSsoRequest(),
    );

    expect(AlumniProfile::where('alumni_id', $alumni->alumni_id)->count())->toBe(1);
    $profile = AlumniProfile::where('alumni_id', $alumni->alumni_id)->first();
    expect($profile->first_name)->toBe('Existing'); // untouched, not overwritten with a stub
});

// ═════════════════════════════════════════════════════════════════════════════
// Non-SIS alumnus: PUPTAPS confirms them (post-verification) but with no
// student number — regression coverage for the AlumniDTO null-stud_number
// fix, exercised through the real provisioning path an actual login takes,
// not just the DTO in isolation.
// ═════════════════════════════════════════════════════════════════════════════

test('a verified Non-SIS alumnus with no PUPTAPS student number provisions successfully with an empty student_number', function () {
    $email = 'non.sis.alumnus@example.com';

    mockOgosMiss($this, $email);

    // Built via AlumniDTO::fromArray() from a raw, snake_case payload
    // shaped exactly like a real PUPTAPS HTTP response — not the
    // sampleAlumniDto() constructor helper — since the null-to-''
    // coalescing this test guards lives in fromArray(), not the
    // constructor itself (which intentionally stays a strict
    // non-nullable string; PUPTAPS JSON is the only untrusted boundary).
    $dto = AlumniDTO::fromArray([
        'alumni_id'      => 909,
        'stud_number'    => null,
        'last_name'      => 'Dela Cruz',
        'first_name'     => 'Juan',
        'middle_name'    => 'Reyes',
        'suffix'         => null,
        'course_id'      => 'BSIT',
        'course_desc'    => 'Bachelor of Science in Information Technology',
        'batch'          => 2022,
        'year_graduated' => '2022',
        'sex'            => null,
        'birthday'       => null,
        'email'          => $email,
        'number'         => '09171234567',
        'profile_status' => 'Verified',
    ]);

    $alumniClient = Mockery::mock(AlumniSystemClientInterface::class);
    $alumniClient->shouldReceive('tryLookupAlumniByEmail')
        ->once()
        ->with($email)
        ->andReturn($dto);
    $this->app->instance(AlumniSystemClientInterface::class, $alumniClient);

    $result = app(UserProvisioningService::class)->provision(
        ['id' => 'idp-non-sis-909', 'email' => $email],
        alumniSsoRequest(),
    );

    expect($result->user->role_id)->toBe(SystemUser::ROLE_ALUMNI);

    $alumni = Alumni::where('user_id', $result->user->user_id)->first();
    expect($alumni->alumni_type_id)->toBe(Alumni::TYPE_SIS); // PUPTAPS confirmed them, regardless of stud number

    $profile = AlumniProfile::where('alumni_id', $alumni->alumni_id)->first();
    $record  = AlumniAcademicRecord::where('alumni_profile_id', $profile->alumni_profile_id)->first();

    expect($record)->not->toBeNull();
    // '' — the FakeAlumniSystemClient convention for "no student number",
    // not the TypeError this used to throw before AlumniDTO::fromArray()
    // coalesced a null stud_number.
    expect($record->student_number)->toBe('');
});