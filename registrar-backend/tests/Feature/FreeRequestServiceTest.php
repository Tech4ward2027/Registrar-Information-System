<?php

use App\Enums\RequestChannelEnum;
use App\Enums\RequestStatusEnum;
use App\Exceptions\FreeRequestIneligibleException;
use App\Models\AlumniAcademicRecord;
use App\Models\AlumniProfile;
use App\Models\Alumni;
use App\Models\CertificationType;
use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Models\Policy;
use App\Models\RequestPurpose;
use App\Models\StudentAcademicRecord;
use App\Models\StudentProfile;
use App\Models\SystemUser;
use App\Services\FreeRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

/**
 * FESPEC-0008 — Free Document/Certificate Request.
 *
 * Feature-level tests for FreeRequestService::fileFreeRequest() /
 * checkEligibility() / searchAccounts() — exercised against the real DB
 * (transactions, row locking, the graduate_verifications write) rather
 * than mocked, since the whole point of this class is the transactional
 * behaviour. HTTP/controller-layer concerns (routes, audit logging,
 * JSON shape) are covered separately in FreeRequestControllerTest.
 *
 * NOTE on exception type: FreeRequestService uses Laravel's abort()
 * helper for every shape/authorization failure that doesn't need the
 * full structured eligibility payload. abort($code, $message) throws
 * Symfony\Component\HttpKernel\Exception\HttpException (NOT
 * Illuminate\Http\Exceptions\HttpResponseException — that class is only
 * thrown when abort() is passed a Response/Responsable instance
 * directly, which this service never does). Asserting on HttpException
 * plus its getStatusCode() is what actually pins down "this failed with
 * a 403" vs "this failed with a 422", which matters here since both
 * codes are used for different failure modes throughout this class.
 */
function frsService(): FreeRequestService
{
    return app(FreeRequestService::class);
}

/**
 * Calls $callback and asserts it throws Laravel's abort()-style
 * HttpException with the given status code.
 */
function frsExpectAbort(callable $callback, int $expectedStatus): void
{
    try {
        $callback();
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe($expectedStatus);
        return;
    }

    // Static PHPUnit::fail() rather than Pest's test()->fail() / $this->fail()
    // — this function is called from inside a test closure but is not
    // itself one, and the static form works regardless of how Pest has
    // bound the calling closure.
    \PHPUnit\Framework\Assert::fail("Expected an HttpException with status {$expectedStatus} to be thrown, none was.");
}

/**
 * An admin actor holding the given free_requests actions. Mirrors
 * tests/Feature/PolicyModuleAccessTest.php's makeAdmin() pattern
 * (Policy::create() + policy_id on a fresh admin SystemUser) rather than
 * reusing that file's global makeAdmin() by name, to avoid colliding
 * with its already-declared global function of the same name.
 *
 * @param string[] $actions subset of ['View','File','Verify','Override']
 */
function frsMakeAdmin(array $actions): SystemUser
{
    $policy = Policy::create([
        'name'        => 'Test Free Requests ' . implode('-', $actions) . ' ' . uniqid(),
        'permissions' => ['free_requests' => $actions],
        'is_system'   => false,
    ]);

    return SystemUser::factory()->create([
        'role_id'   => SystemUser::ROLE_ADMIN,
        'status'    => 'Activated',
        'policy_id' => $policy->policy_id,
    ]);
}

function frsMakeStudent(): SystemUser
{
    $user = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT, 'status' => 'Activated']);
    $profile = StudentProfile::factory()->create(['user_id' => $user->user_id]);
    StudentAcademicRecord::factory()->create(['student_profile_id' => $profile->student_profile_id]);

    return $user->fresh();
}

function frsMakeAlumni(): SystemUser
{
    $user   = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ALUMNI, 'status' => 'Activated']);
    $alumni = Alumni::create(['user_id' => $user->user_id, 'alumni_type_id' => 1]);
    $profile = AlumniProfile::create([
        'alumni_id'     => $alumni->alumni_id,
        'first_name'    => 'Fixture',
        'last_name'     => 'Alumna',
        'date_of_birth' => '1998-01-01',
        'sex_at_birth'  => 'Female',
    ]);
    AlumniAcademicRecord::create([
        'alumni_profile_id'  => $profile->alumni_profile_id,
        'student_number'     => '2018-00001',
        'year_of_graduation' => 2022,
        'course'             => 'BSIT',
    ]);

    return $user->fresh();
}

function frsMakeUnlimitedDocType(int $accessId = 1): DocumentType
{
    return DocumentType::create([
        'document_name'           => 'Test Fixture LOA',
        'document_description'    => '',
        'document_process_period' => '1 day',
        'access_id'               => $accessId,
        'is_free_eligible'        => true,
        'free_issuance_limit'     => null,
    ]);
}

function frsMakeGraduateScopedCertType(int $limit = 1, int $accessId = 2): CertificationType
{
    return CertificationType::create([
        'certificate_name'    => 'Test Fixture COG',
        'certificate_requirements'    => 'Test fixture requirements.',
        'certificate_process_period'  => '1 working day',
        'access_id'           => $accessId,
        'is_free_eligible'    => true,
        'free_issuance_limit' => $limit,
    ]);
}

function frsNotFreeEligibleCertType(): CertificationType
{
    return CertificationType::create([
        'certificate_name'    => 'Test Fixture Not Eligible',
        'certificate_requirements'    => 'Test fixture requirements.',
        'certificate_process_period'  => '1 working day',
        'access_id'           => 2,
        'is_free_eligible'    => false,
        'free_issuance_limit' => null,
    ]);
}

function frsPurposeId(): int
{
    return RequestPurpose::query()->value('request_purpose_id')
        ?? RequestPurpose::create(['purpose_name' => 'Personal Copy'])->request_purpose_id;
}

// ── Happy paths ──────────────────────────────────────────────────────

test('filing a free LOA request for a student needs no graduate verification and creates no graduate_verifications row', function () {
    $actor   = frsMakeAdmin(['View', 'File']);
    $student = frsMakeStudent();
    $docType = frsMakeUnlimitedDocType(accessId: 1);

    $result = frsService()->fileFreeRequest(
        actor: $actor,
        targetUser: $student,
        validated: [
            'request_purpose_id' => frsPurposeId(),
            'documents'          => [['document_type_id' => $docType->document_type_id, 'number_of_copies' => 1]],
            'certificates'       => [],
        ],
    );

    expect($result->graduateVerificationPerformed)->toBeFalse();
    expect($result->graduateVerification)->toBeNull();
    expect($result->wasOverridden)->toBeFalse();
    expect($result->documentRequest->channel)->toBe(RequestChannelEnum::AdminFiledFree->value);
    expect($result->documentRequest->fresh()->graduateVerification)->toBeNull();
});

test('filing a free COG request for an alumnus with Verify capability creates a graduate_verifications row', function () {
    $actor    = frsMakeAdmin(['View', 'File', 'Verify']);
    $alumni   = frsMakeAlumni();
    $certType = frsMakeGraduateScopedCertType(limit: 1, accessId: 2);

    $result = frsService()->fileFreeRequest(
        actor: $actor,
        targetUser: $alumni,
        validated: [
            'request_purpose_id' => frsPurposeId(),
            'documents'          => [],
            'certificates'       => [['certificate_type_id' => $certType->certificate_type_id, 'number_of_copies' => 1]],
        ],
        options: ['verification' => ['credentials_verified' => true, 'records_checked' => true]],
    );

    expect($result->graduateVerificationPerformed)->toBeTrue();
    expect($result->graduateVerification)->not->toBeNull();
    expect($result->graduateVerification->credentials_verified_by)->toBe($actor->user_id);
    expect($result->graduateVerification->records_checked_by)->toBe($actor->user_id);
    expect($result->documentRequest->fresh()->graduateVerification)->not->toBeNull();
});

// ── Capability gating ────────────────────────────────────────────────

test('filing a COG request without the Verify capability is rejected with 403, even with confirmation flags set', function () {
    $actor    = frsMakeAdmin(['View', 'File']); // no Verify
    $alumni   = frsMakeAlumni();
    $certType = frsMakeGraduateScopedCertType(limit: 1, accessId: 2);

    frsExpectAbort(fn () => frsService()->fileFreeRequest(
        actor: $actor,
        targetUser: $alumni,
        validated: [
            'request_purpose_id' => frsPurposeId(),
            'documents'          => [],
            'certificates'       => [['certificate_type_id' => $certType->certificate_type_id, 'number_of_copies' => 1]],
        ],
        options: ['verification' => ['credentials_verified' => true, 'records_checked' => true]],
    ), 403);
});

test('filing a COG request with Verify capability but incomplete verification confirmation is rejected with 422', function () {
    $actor    = frsMakeAdmin(['View', 'File', 'Verify']);
    $alumni   = frsMakeAlumni();
    $certType = frsMakeGraduateScopedCertType(limit: 1, accessId: 2);

    frsExpectAbort(fn () => frsService()->fileFreeRequest(
        actor: $actor,
        targetUser: $alumni,
        validated: [
            'request_purpose_id' => frsPurposeId(),
            'documents'          => [],
            'certificates'       => [['certificate_type_id' => $certType->certificate_type_id, 'number_of_copies' => 1]],
        ],
        options: ['verification' => ['credentials_verified' => true, 'records_checked' => false]],
    ), 422);
});

test('an override without the Override capability is rejected with 403', function () {
    $actor    = frsMakeAdmin(['View', 'File']); // no Override
    $alumni   = frsMakeAlumni();
    $certType = frsNotFreeEligibleCertType();

    frsExpectAbort(fn () => frsService()->fileFreeRequest(
        actor: $actor,
        targetUser: $alumni,
        validated: [
            'request_purpose_id' => frsPurposeId(),
            'documents'          => [],
            'certificates'       => [['certificate_type_id' => $certType->certificate_type_id, 'number_of_copies' => 1]],
        ],
        options: ['override' => true, 'override_reason' => 'Confirmed eligible via manual records check.'],
    ), 403);
});

test('an override with the Override capability but no reason is rejected with 422', function () {
    $actor    = frsMakeAdmin(['View', 'File', 'Override']);
    $alumni   = frsMakeAlumni();
    $certType = frsNotFreeEligibleCertType();

    frsExpectAbort(fn () => frsService()->fileFreeRequest(
        actor: $actor,
        targetUser: $alumni,
        validated: [
            'request_purpose_id' => frsPurposeId(),
            'documents'          => [],
            'certificates'       => [['certificate_type_id' => $certType->certificate_type_id, 'number_of_copies' => 1]],
        ],
        options: ['override' => true, 'override_reason' => ''],
    ), 422);
});

test('a valid override by a capable actor files the request and records the override reason/labels', function () {
    $actor    = frsMakeAdmin(['View', 'File', 'Override']);
    $alumni   = frsMakeAlumni();
    $certType = frsNotFreeEligibleCertType();

    $result = frsService()->fileFreeRequest(
        actor: $actor,
        targetUser: $alumni,
        validated: [
            'request_purpose_id' => frsPurposeId(),
            'documents'          => [],
            'certificates'       => [['certificate_type_id' => $certType->certificate_type_id, 'number_of_copies' => 1]],
        ],
        options: ['override' => true, 'override_reason' => 'Confirmed eligible via manual records check.'],
    );

    expect($result->wasOverridden)->toBeTrue();
    expect($result->overrideReason)->toBe('Confirmed eligible via manual records check.');
    expect($result->overriddenTypeLabels)->toContain('Test Fixture Not Eligible');
    expect($result->documentRequest->channel)->toBe(RequestChannelEnum::AdminFiledFree->value);
});

// ── Ineligibility without override ──────────────────────────────────

test('filing an ineligible item without override throws FreeRequestIneligibleException carrying every item\'s result', function () {
    $actor    = frsMakeAdmin(['View', 'File']);
    $alumni   = frsMakeAlumni();
    $certType = frsNotFreeEligibleCertType();

    try {
        frsService()->fileFreeRequest(
            actor: $actor,
            targetUser: $alumni,
            validated: [
                'request_purpose_id' => frsPurposeId(),
                'documents'          => [],
                'certificates'       => [['certificate_type_id' => $certType->certificate_type_id, 'number_of_copies' => 1]],
            ],
        );
        \PHPUnit\Framework\Assert::fail('Expected FreeRequestIneligibleException to be thrown.');
    } catch (FreeRequestIneligibleException $e) {
        expect($e->results)->toHaveCount(1);
        expect($e->results[0]->eligible)->toBeFalse();
    }
});

// ── Input/shape guards ───────────────────────────────────────────────

test('filing with no documents and no certificates is rejected with 422', function () {
    $actor  = frsMakeAdmin(['View', 'File']);
    $alumni = frsMakeAlumni();

    frsExpectAbort(fn () => frsService()->fileFreeRequest(
        actor: $actor,
        targetUser: $alumni,
        validated: ['request_purpose_id' => frsPurposeId(), 'documents' => [], 'certificates' => []],
    ), 422);
});

test('filing on behalf of a non-student/alumni target is rejected with 422', function () {
    $actor      = frsMakeAdmin(['View', 'File']);
    $otherAdmin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN, 'status' => 'Activated']);
    $docType    = frsMakeUnlimitedDocType(accessId: 3);

    frsExpectAbort(fn () => frsService()->fileFreeRequest(
        actor: $actor,
        targetUser: $otherAdmin,
        validated: [
            'request_purpose_id' => frsPurposeId(),
            'documents'          => [['document_type_id' => $docType->document_type_id, 'number_of_copies' => 1]],
            'certificates'       => [],
        ],
    ), 422);
});

// ── The one-free-copy guarantee (row-locked re-check at filing time) ──

test('filing the same graduate-scoped item twice: the second filing sees the first\'s now-committed claim and is rejected', function () {
    $actor    = frsMakeAdmin(['View', 'File', 'Verify']);
    $alumni   = frsMakeAlumni();
    $certType = frsMakeGraduateScopedCertType(limit: 1, accessId: 2);

    $validated = [
        'request_purpose_id' => frsPurposeId(),
        'documents'          => [],
        'certificates'       => [['certificate_type_id' => $certType->certificate_type_id, 'number_of_copies' => 1]],
    ];
    $options = ['verification' => ['credentials_verified' => true, 'records_checked' => true]];

    $first = frsService()->fileFreeRequest($actor, $alumni, $validated, $options);

    // Simulate the graduate actually claiming their first copy (the real
    // "Completed" transition normally happens via DocumentRequestService::
    // claimRequest(); writing status_id directly here is the same
    // shortcut this codebase's own factories/fixtures use elsewhere to
    // stand up a specific terminal state without exercising the whole
    // claim flow).
    $first->documentRequest->update(['status_id' => RequestStatusEnum::Completed->value]);
    $first->documentRequest->certificates()->update(['status_id' => RequestStatusEnum::Completed->value]);

    expect(fn () => frsService()->fileFreeRequest($actor, $alumni, $validated, $options))
        ->toThrow(FreeRequestIneligibleException::class);
});

test('checkEligibility is read-only and does not create a document_request row', function () {
    $alumni   = frsMakeAlumni();
    $certType = frsMakeGraduateScopedCertType(limit: 1, accessId: 2);

    $countBefore = DocumentRequest::count();

    $results = frsService()->checkEligibility($alumni, [], [['certificate_type_id' => $certType->certificate_type_id]]);

    expect($results)->toHaveCount(1);
    expect(DocumentRequest::count())->toBe($countBefore);
});

// ── Account search ───────────────────────────────────────────────────

test('searchAccounts finds an activated student/alumni account by name or email prefix, scoped to those two roles', function () {
    $student = frsMakeStudent();
    $student->studentProfile->update(['first_name' => 'Zendaya', 'last_name' => 'Fixture']);

    $staff = SystemUser::factory()->create([
        'role_id' => SystemUser::ROLE_ADMIN,
        'status'  => 'Activated',
        'email'   => 'zendaya.staff@example.com',
    ]);

    $results = frsService()->searchAccounts('Zendaya');

    expect($results->pluck('user_id'))->toContain($student->user_id);
    expect($results->pluck('user_id'))->not->toContain($staff->user_id);
});
