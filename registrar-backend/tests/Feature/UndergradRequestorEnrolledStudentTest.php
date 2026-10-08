<?php

use App\Contracts\UndergradEnrollmentLookupClientInterface;
use App\DTOs\Ogos\OgosEnrollmentLookupResult;
use App\DTOs\Ogos\OgosStudentDTO;
use App\Exceptions\AccountPendingVerificationException;
use App\Exceptions\AccountRejectedException;
use App\Models\AuditLog;
use App\Models\RoleAssignment;
use App\Models\SystemUser;
use App\Models\UndergradRequestorProfile;
use App\Models\UndergradRequestorVerification;
use App\Services\Ogos\OgosStudentService;
use App\Services\Sso\UserProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Currently-enrolled students who used the Undergrad Requestor form
|--------------------------------------------------------------------------
|
| Covers the two layers that stop such a student being locked out of RIS:
|
|   1. UserProvisioningService::provision() converts a stalled
|      (Pending Verification / Expired) requestor to a Student when OGOS
|      positively reports the email as currently enrolled, and leaves every
|      other case exactly as it was (fail closed).
|   2. UndergradRequestorRegistrationService::register() refuses the
|      submission up front when OGOS reports the email as enrolled
|      (fail open when OGOS cannot be asked).
|
| OGOS is always faked — nothing here makes a network call.
*/

function esrRequest(): Request
{
    return Request::create('/api/auth/callback', 'POST');
}

function esrFakeLookup(OgosEnrollmentLookupResult $result): void
{
    app()->instance(
        UndergradEnrollmentLookupClientInterface::class,
        new class ($result) implements UndergradEnrollmentLookupClientInterface {
            public function __construct(private OgosEnrollmentLookupResult $result) {}

            public function lookup(?string $studentNumber, ?string $email): OgosEnrollmentLookupResult
            {
                return $this->result;
            }
        }
    );
}

function esrEnrolledResult(string $email): OgosEnrollmentLookupResult
{
    return OgosEnrollmentLookupResult::match(
        OgosStudentDTO::fromArray([
            'studentNumber' => '2024-00456-TG-0',
            'email'         => $email,
            'firstName'     => 'Maria',
            'lastName'      => 'Santos',
        ])
    );
}

/** Student profile provisioning is OGOS-backed and out of scope here. */
function esrMockStudentProvisioning(): void
{
    test()->mock(OgosStudentService::class, function ($mock) {
        $mock->shouldReceive('provisionStudentData')->andReturn(true);
    });
}

function esrStalledRequestor(array $overrides = []): SystemUser
{
    $user = SystemUser::factory()->create(array_merge([
        'role_id'            => SystemUser::ROLE_UNDERGRAD_REQUESTOR,
        'status'             => 'Pending Verification',
        'idp_user_id'        => null,
        'password'           => null,
        'local_auth_enabled' => 0,
        'pending_expires_at' => now()->addDays(14),
    ], $overrides));

    UndergradRequestorProfile::factory()->create(['user_id' => $user->user_id]);
    UndergradRequestorVerification::factory()->create(['user_id' => $user->user_id]);

    return $user;
}

// ═════════════════════════════════════════════════════════════════════════════
// provision() — conversion
// ═════════════════════════════════════════════════════════════════════════════

test('a Pending requestor that OGOS reports as enrolled is converted to a Student on login', function () {
    $user = esrStalledRequestor();
    esrFakeLookup(esrEnrolledResult($user->email));
    esrMockStudentProvisioning();

    $result = app(UserProvisioningService::class)->provision([
        'id'    => 'idp-enrolled-student',
        'email' => $user->email,
    ], esrRequest());

    $user->refresh();
    expect($user->role_id)->toBe(SystemUser::ROLE_STUDENT);
    expect($user->status)->toBe('Activated');
    expect($user->idp_user_id)->toBe('idp-enrolled-student');
    expect($user->pending_expires_at)->toBeNull();
    expect($user->password)->not->toBeNull();
    expect($result->user->user_id)->toBe($user->user_id);

    // The mistaken submission's PII and review record are disposed of.
    expect(UndergradRequestorProfile::where('user_id', $user->user_id)->exists())->toBeFalse();
    expect(UndergradRequestorVerification::where('user_id', $user->user_id)->exists())->toBeFalse();

    // A Student baseline role assignment exists (and no requestor one).
    $roleIds = RoleAssignment::where('user_id', $user->user_id)->pluck('role_id')->all();
    expect($roleIds)->toContain(SystemUser::ROLE_STUDENT);
    expect($roleIds)->not->toContain(SystemUser::ROLE_UNDERGRAD_REQUESTOR);

    $this->assertDatabaseHas('audit_logs', [
        'action'         => AuditLog::ACTION_UNDERGRAD_REQUESTOR_RECLASSIFIED,
        'target_user_id' => $user->user_id,
        'user_id'        => $user->user_id,
    ]);
});

test('an Expired requestor that OGOS reports as enrolled is also converted', function () {
    $user = esrStalledRequestor([
        'status'             => 'Expired',
        'pending_expires_at' => now()->subDays(3),
    ]);
    esrFakeLookup(esrEnrolledResult($user->email));
    esrMockStudentProvisioning();

    app(UserProvisioningService::class)->provision([
        'id'    => 'idp-enrolled-expired',
        'email' => $user->email,
    ], esrRequest());

    $user->refresh();
    expect($user->role_id)->toBe(SystemUser::ROLE_STUDENT);
    expect($user->status)->toBe('Activated');
    expect($user->pending_expires_at)->toBeNull();
});

test('a Pending requestor past the 14-day window is converted instead of expired when enrolled', function () {
    $user = esrStalledRequestor(['pending_expires_at' => now()->subDay()]);
    esrFakeLookup(esrEnrolledResult($user->email));
    esrMockStudentProvisioning();

    app(UserProvisioningService::class)->provision([
        'id'    => 'idp-enrolled-late',
        'email' => $user->email,
    ], esrRequest());

    expect($user->fresh()->status)->toBe('Activated');
    expect(AuditLog::where('action', AuditLog::ACTION_ADMIN_EXPIRED)->count())->toBe(0);
});

test('logging in again after conversion is a normal student login and is not re-converted', function () {
    $user = esrStalledRequestor();
    esrFakeLookup(esrEnrolledResult($user->email));
    esrMockStudentProvisioning();

    $service = app(UserProvisioningService::class);
    $payload = ['id' => 'idp-enrolled-twice', 'email' => $user->email];

    $service->provision($payload, esrRequest());
    $service->provision($payload, esrRequest());

    expect(AuditLog::where('action', AuditLog::ACTION_UNDERGRAD_REQUESTOR_RECLASSIFIED)->count())->toBe(1);
    expect($user->fresh()->role_id)->toBe(SystemUser::ROLE_STUDENT);
});

// ═════════════════════════════════════════════════════════════════════════════
// provision() — everything else is unchanged (fail closed)
// ═════════════════════════════════════════════════════════════════════════════

test('a Pending requestor OGOS does not know stays blocked and untouched', function () {
    $user = esrStalledRequestor();
    esrFakeLookup(OgosEnrollmentLookupResult::noMatch());

    expect(fn () => app(UserProvisioningService::class)->provision([
        'id'    => 'idp-genuine-former-student',
        'email' => $user->email,
    ], esrRequest()))->toThrow(AccountPendingVerificationException::class);

    $user->refresh();
    expect($user->role_id)->toBe(SystemUser::ROLE_UNDERGRAD_REQUESTOR);
    expect($user->status)->toBe('Pending Verification');
    expect(UndergradRequestorProfile::where('user_id', $user->user_id)->exists())->toBeTrue();
});

test('an OGOS outage never converts an account', function () {
    $user = esrStalledRequestor();
    esrFakeLookup(OgosEnrollmentLookupResult::unavailable('OGOS was unreachable at review time.'));

    expect(fn () => app(UserProvisioningService::class)->provision([
        'id'    => 'idp-ogos-down',
        'email' => $user->email,
    ], esrRequest()))->toThrow(AccountPendingVerificationException::class);

    expect($user->fresh()->role_id)->toBe(SystemUser::ROLE_UNDERGRAD_REQUESTOR);
    expect(AuditLog::where('action', AuditLog::ACTION_UNDERGRAD_REQUESTOR_RECLASSIFIED)->count())->toBe(0);
});

test('a Rejected requestor is never converted, even if OGOS reports them as enrolled', function () {
    $user = esrStalledRequestor(['status' => 'Rejected']);
    $user->undergradRequestorVerification()->update(['status' => 'Rejected']);
    esrFakeLookup(esrEnrolledResult($user->email));

    expect(fn () => app(UserProvisioningService::class)->provision([
        'id'    => 'idp-rejected-enrolled',
        'email' => $user->email,
    ], esrRequest()))->toThrow(AccountRejectedException::class);

    $user->refresh();
    expect($user->role_id)->toBe(SystemUser::ROLE_UNDERGRAD_REQUESTOR);
    expect($user->status)->toBe('Rejected');
});

test('an Approved requestor is not affected by the enrollment lookup', function () {
    $user = SystemUser::factory()->create([
        'role_id'            => SystemUser::ROLE_UNDERGRAD_REQUESTOR,
        'status'             => 'Pending Activation',
        'idp_user_id'        => null,
        'password'           => null,
        'pending_expires_at' => null,
    ]);
    UndergradRequestorVerification::factory()->approved()->create(['user_id' => $user->user_id]);
    esrFakeLookup(esrEnrolledResult($user->email));

    app(UserProvisioningService::class)->provision([
        'id'    => 'idp-approved',
        'email' => $user->email,
    ], esrRequest());

    $user->refresh();
    expect($user->role_id)->toBe(SystemUser::ROLE_UNDERGRAD_REQUESTOR);
    expect($user->status)->toBe('Activated');
    expect(AuditLog::where('action', AuditLog::ACTION_UNDERGRAD_REQUESTOR_RECLASSIFIED)->count())->toBe(0);
});

// ═════════════════════════════════════════════════════════════════════════════
// register() — prevention
// ═════════════════════════════════════════════════════════════════════════════

function esrRegistrationPayload(array $overrides = []): array
{
    return array_merge([
        'email'                     => 'enrolled' . uniqid() . '@example.com',
        'first_name'                => 'Maria',
        'middle_name'               => null,
        'last_name'                 => 'Santos',
        'suffix'                    => null,
        'student_number'            => '2019-00123-MN-0',
        'program'                   => 'BS Information Technology',
        'last_school_year_attended' => '2021-2022',
        'date_of_birth'             => '2000-05-15',
        'present_address'           => '123 Sample St., Taguig City',
        'reason_for_non_enrollment' => null,
        'phone'                     => '09171234567',
        'data_privacy_consent'      => true,
    ], $overrides);
}

test('registration is refused when OGOS reports the email as currently enrolled', function () {
    Mail::fake();
    $payload = esrRegistrationPayload();
    esrFakeLookup(esrEnrolledResult($payload['email']));

    $this->postJson('/api/undergrad-requestors/register', $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);

    $this->assertDatabaseMissing('users', ['email' => $payload['email']]);
    Mail::assertNothingQueued();
});

test('registration still succeeds when OGOS does not know the email', function () {
    Mail::fake();
    $payload = esrRegistrationPayload();
    esrFakeLookup(OgosEnrollmentLookupResult::noMatch());

    $this->postJson('/api/undergrad-requestors/register', $payload)->assertCreated();

    $this->assertDatabaseHas('users', ['email' => $payload['email']]);
});

test('registration fails open when OGOS cannot be asked', function () {
    Mail::fake();
    $payload = esrRegistrationPayload();
    esrFakeLookup(OgosEnrollmentLookupResult::unavailable('OGOS was unreachable at review time.'));

    $this->postJson('/api/undergrad-requestors/register', $payload)->assertCreated();

    $this->assertDatabaseHas('users', ['email' => $payload['email']]);
});
