<?php

use App\DTOs\FreeRequest\FreeRequestEligibilityResult;
use App\Enums\FreeRequestItemKindEnum;
use App\Enums\RequestChannelEnum;
use App\Enums\RequestStatusEnum;
use App\Models\AlumniAcademicRecord;
use App\Models\AlumniProfile;
use App\Models\Alumni;
use App\Models\CertificationType;
use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Models\StudentAcademicRecord;
use App\Models\StudentProfile;
use App\Models\SystemUser;
use App\Services\FreeRequestEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * FESPEC-0008 — Free Document/Certificate Request.
 *
 * Pure input/output tests against FreeRequestEligibilityService::check(),
 * with no mocking beyond factory/fixture rows — this class has no side
 * effects (no writes, no Auth:: calls), so every case here is a plain
 * assertion against its return value. Placed in tests/Unit per the
 * FESPEC-0008 Phase 6 plan; still extends the framework TestCase (via
 * tests/Pest.php's uses() mapping) since Eloquent/DB access is required
 * to resolve document_type / certificate_type / document_request rows —
 * this mirrors how DocumentRequestServiceInterface-adjacent logic is
 * tested elsewhere in this codebase (there is no separate "no database
 * at all" unit-testing convention here).
 *
 * Covers every case named in the FESPEC-0008 Phase 6 test plan:
 *   - eligible graduate (COG/TOR-style, finite limit, alumni target)
 *   - eligible student (LOA-style, unlimited, student target)
 *   - ineligible document type (is_free_eligible = false)
 *   - non-graduate (student target on a graduate-scoped type)
 *   - already-claimed graduate → ineligible (status must be Completed,
 *     not merely filed/Processing/ReadyToClaim)
 *   - filed-then-forfeited graduate → still eligible for resubmission
 *     (validates the revised "consumed upon claim" rule — a Forfeited
 *     prior attempt does NOT count against the limit)
 *   - LOA with a prior claimed LOA → still eligible (unlimited)
 * plus the additional real-schema branches this codebase's
 * FreeRequestEligibilityService actually has (type not found, wrong
 * target role entirely, type not visible to the target's role) and the
 * checkMany()/anyRequiresGraduateVerification() batch helpers Phase 3
 * relies on.
 */
function frMakeService(): FreeRequestEligibilityService
{
    return app(FreeRequestEligibilityService::class);
}

/**
 * A free-eligible, graduate-scoped (finite limit) certificate type —
 * stands in for "Certificate of Graduation" (certificate_type_id = 6,
 * access_id = 2/Alumni) without depending on production seed data ever
 * flipping is_free_eligible for real (see the Phase 1 migration's
 * docblock — that flip is a deliberate Phase 9 data change, not part of
 * schema/seeding, so tests must not assume it has happened).
 */
function frMakeGraduateScopedCertType(int $limit = 1, int $accessId = 2): CertificationType
{
    return CertificationType::create([
        'certificate_name' => 'Test Fixture Certificate of Graduation',
        'certificate_requirements'    => 'Test fixture requirements.',
        'certificate_process_period'  => '1 working day',
        'access_id'        => $accessId,
        'is_free_eligible' => true,
        'free_issuance_limit' => $limit,
    ]);
}

/**
 * A free-eligible, unlimited (NULL limit) document type — stands in for
 * "Leave of Absence" (document_type_id = 17, access_id = 1/Student).
 */
function frMakeUnlimitedDocType(int $accessId = 1): DocumentType
{
    return DocumentType::create([
        'document_name'         => 'Test Fixture Leave of Absence',
        'document_description'  => '',
        'document_process_period' => '1 day',
        'access_id'             => $accessId,
        'is_free_eligible'      => true,
        'free_issuance_limit'   => null,
    ]);
}

function frMakeStudent(): SystemUser
{
    $user = SystemUser::factory()->create([
        'role_id' => SystemUser::ROLE_STUDENT,
        'status'  => 'Activated',
    ]);

    $profile = StudentProfile::factory()->create(['user_id' => $user->user_id]);
    StudentAcademicRecord::factory()->create(['student_profile_id' => $profile->student_profile_id]);

    return $user->fresh();
}

function frMakeAlumni(): SystemUser
{
    $user = SystemUser::factory()->create([
        'role_id' => SystemUser::ROLE_ALUMNI,
        'status'  => 'Activated',
    ]);

    $alumni = Alumni::create([
        'user_id'        => $user->user_id,
        'alumni_type_id' => \App\Models\AlumniType::query()->value('alumni_type_id') ?? 1,
    ]);

    $profile = AlumniProfile::create([
        'alumni_id'      => $alumni->alumni_id,
        'first_name'     => 'Fixture',
        'last_name'      => 'Alumna',
        'date_of_birth'  => '1998-01-01',
        'sex_at_birth'   => 'Female',
    ]);

    AlumniAcademicRecord::create([
        'alumni_profile_id'  => $profile->alumni_profile_id,
        'student_number'     => '2018-00001',
        'year_of_graduation' => 2022,
        'course'             => 'BSIT',
    ]);

    return $user->fresh();
}

/**
 * A prior admin_filed_free request for $targetUser covering the given
 * certificate type, at the given status. Used to exercise the
 * "already claimed" / "filed then forfeited" limit-counting branches.
 */
function frMakePriorFreeCertRequest(SystemUser $targetUser, CertificationType $certType, RequestStatusEnum $status): DocumentRequest
{
    $request = DocumentRequest::factory()->create([
        'user_id'   => $targetUser->user_id,
        'status_id' => $status->value,
        'channel'   => RequestChannelEnum::AdminFiledFree->value,
    ]);

    $request->certificates()->create([
        'certificate_type_id' => $certType->certificate_type_id,
        'number_of_copies'    => 1,
        'status_id'           => $status->value,
    ]);

    return $request;
}

function frMakePriorFreeDocRequest(SystemUser $targetUser, DocumentType $docType, RequestStatusEnum $status): DocumentRequest
{
    $request = DocumentRequest::factory()->create([
        'user_id'   => $targetUser->user_id,
        'status_id' => $status->value,
        'channel'   => RequestChannelEnum::AdminFiledFree->value,
    ]);

    $request->documents()->create([
        'document_type_id' => $docType->document_type_id,
        'number_of_copies' => 1,
        'status_id'        => $status->value,
    ]);

    return $request;
}

// ── Happy paths ──────────────────────────────────────────────────────

test('eligible graduate: alumni target, graduate-scoped type, no prior claims → eligible', function () {
    $alumni   = frMakeAlumni();
    $certType = frMakeGraduateScopedCertType(limit: 1, accessId: 2);

    $result = frMakeService()->check($alumni, FreeRequestItemKindEnum::Certificate, $certType->certificate_type_id);

    expect($result->eligible)->toBeTrue();
    expect($result->requiresGraduateVerification)->toBeTrue();
    expect($result->freeIssuanceLimit)->toBe(1);
    expect($result->remaining)->toBe(1);
    expect($result->reasonCode)->toBeNull();
});

test('eligible student: student target, unlimited type, no prior claims → eligible', function () {
    $student = frMakeStudent();
    $docType = frMakeUnlimitedDocType(accessId: 1);

    $result = frMakeService()->check($student, FreeRequestItemKindEnum::Document, $docType->document_type_id);

    expect($result->eligible)->toBeTrue();
    expect($result->requiresGraduateVerification)->toBeFalse();
    expect($result->freeIssuanceLimit)->toBeNull();
    expect($result->remaining)->toBeNull();
});

// ── Rule failures ────────────────────────────────────────────────────

test('ineligible document type: is_free_eligible = false → ineligible with REASON_NOT_FREE_ELIGIBLE', function () {
    $alumni = frMakeAlumni();

    $certType = CertificationType::create([
        'certificate_name'    => 'Test Fixture Not Free Eligible',
        'certificate_requirements'    => 'Test fixture requirements.',
        'certificate_process_period'  => '1 working day',
        'access_id'           => 2,
        'is_free_eligible'    => false,
        'free_issuance_limit' => null,
    ]);

    $result = frMakeService()->check($alumni, FreeRequestItemKindEnum::Certificate, $certType->certificate_type_id);

    expect($result->eligible)->toBeFalse();
    expect($result->reasonCode)->toBe(FreeRequestEligibilityResult::REASON_NOT_FREE_ELIGIBLE);
});

test('non-graduate: student target on a graduate-scoped (finite-limit) type → ineligible with REASON_NOT_GRADUATE', function () {
    // A graduate-scoped type is, by construction, visible to Both (access_id=3)
    // or Alumni so a student can even reach the role-visibility check.
    $student  = frMakeStudent();
    $certType = frMakeGraduateScopedCertType(limit: 1, accessId: 3);

    $result = frMakeService()->check($student, FreeRequestItemKindEnum::Certificate, $certType->certificate_type_id);

    expect($result->eligible)->toBeFalse();
    expect($result->reasonCode)->toBe(FreeRequestEligibilityResult::REASON_NOT_GRADUATE);
    expect($result->requiresGraduateVerification)->toBeTrue();
});

test('type not found → ineligible with REASON_TYPE_NOT_FOUND', function () {
    $alumni = frMakeAlumni();

    $result = frMakeService()->check($alumni, FreeRequestItemKindEnum::Certificate, 999999);

    expect($result->eligible)->toBeFalse();
    expect($result->reasonCode)->toBe(FreeRequestEligibilityResult::REASON_TYPE_NOT_FOUND);
    expect($result->typeLabel)->toBeNull();
});

test('invalid target role: target is staff, not student/alumni → ineligible with REASON_INVALID_TARGET_ROLE', function () {
    $admin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN, 'status' => 'Activated']);
    $docType = frMakeUnlimitedDocType(accessId: 3);

    $result = frMakeService()->check($admin, FreeRequestItemKindEnum::Document, $docType->document_type_id);

    expect($result->eligible)->toBeFalse();
    expect($result->reasonCode)->toBe(FreeRequestEligibilityResult::REASON_INVALID_TARGET_ROLE);
});

test('type not visible to role: student-only type requested for an alumni target → ineligible with REASON_NOT_VISIBLE_TO_ROLE', function () {
    $alumni  = frMakeAlumni();
    $docType = frMakeUnlimitedDocType(accessId: 1); // Student-only

    $result = frMakeService()->check($alumni, FreeRequestItemKindEnum::Document, $docType->document_type_id);

    expect($result->eligible)->toBeFalse();
    expect($result->reasonCode)->toBe(FreeRequestEligibilityResult::REASON_NOT_VISIBLE_TO_ROLE);
});

// ── Consumption / limit counting (the revised "upon claim" rule) ───────

test('already-claimed graduate: prior Completed free request for this type → ineligible with REASON_LIMIT_REACHED', function () {
    $alumni   = frMakeAlumni();
    $certType = frMakeGraduateScopedCertType(limit: 1, accessId: 2);

    frMakePriorFreeCertRequest($alumni, $certType, RequestStatusEnum::Completed);

    $result = frMakeService()->check($alumni, FreeRequestItemKindEnum::Certificate, $certType->certificate_type_id);

    expect($result->eligible)->toBeFalse();
    expect($result->reasonCode)->toBe(FreeRequestEligibilityResult::REASON_LIMIT_REACHED);
    expect($result->remaining)->toBe(0);
});

test('a merely Processing (not yet claimed) prior free request does NOT count against the limit', function () {
    $alumni   = frMakeAlumni();
    $certType = frMakeGraduateScopedCertType(limit: 1, accessId: 2);

    frMakePriorFreeCertRequest($alumni, $certType, RequestStatusEnum::Processing);

    $result = frMakeService()->check($alumni, FreeRequestItemKindEnum::Certificate, $certType->certificate_type_id);

    expect($result->eligible)->toBeTrue();
    expect($result->remaining)->toBe(1);
});

test('filed-then-forfeited graduate is still eligible for resubmission (validates the revised consumption-upon-claim rule)', function () {
    $alumni   = frMakeAlumni();
    $certType = frMakeGraduateScopedCertType(limit: 1, accessId: 2);

    frMakePriorFreeCertRequest($alumni, $certType, RequestStatusEnum::Forfeited);

    $result = frMakeService()->check($alumni, FreeRequestItemKindEnum::Certificate, $certType->certificate_type_id);

    expect($result->eligible)->toBeTrue();
    expect($result->remaining)->toBe(1);
});

test('LOA-style unlimited type with a prior Completed claim is still eligible (NULL limit is never exhausted)', function () {
    $student = frMakeStudent();
    $docType = frMakeUnlimitedDocType(accessId: 1);

    frMakePriorFreeDocRequest($student, $docType, RequestStatusEnum::Completed);
    frMakePriorFreeDocRequest($student, $docType, RequestStatusEnum::Completed);

    $result = frMakeService()->check($student, FreeRequestItemKindEnum::Document, $docType->document_type_id);

    expect($result->eligible)->toBeTrue();
    expect($result->freeIssuanceLimit)->toBeNull();
    expect($result->remaining)->toBeNull();
});

test('a prior claim on a DIFFERENT type does not count against this type\'s limit', function () {
    $alumni    = frMakeAlumni();
    $certTypeA = frMakeGraduateScopedCertType(limit: 1, accessId: 2);
    $certTypeB = frMakeGraduateScopedCertType(limit: 1, accessId: 2);

    frMakePriorFreeCertRequest($alumni, $certTypeA, RequestStatusEnum::Completed);

    $result = frMakeService()->check($alumni, FreeRequestItemKindEnum::Certificate, $certTypeB->certificate_type_id);

    expect($result->eligible)->toBeTrue();
});

test('a prior claim via the self_service channel does not count against the free-channel limit', function () {
    $alumni   = frMakeAlumni();
    $certType = frMakeGraduateScopedCertType(limit: 1, accessId: 2);

    $paidRequest = DocumentRequest::factory()->create([
        'user_id'   => $alumni->user_id,
        'status_id' => RequestStatusEnum::Completed->value,
        'channel'   => RequestChannelEnum::SelfService->value,
    ]);
    $paidRequest->certificates()->create([
        'certificate_type_id' => $certType->certificate_type_id,
        'number_of_copies'    => 1,
        'status_id'           => RequestStatusEnum::Completed->value,
    ]);

    $result = frMakeService()->check($alumni, FreeRequestItemKindEnum::Certificate, $certType->certificate_type_id);

    expect($result->eligible)->toBeTrue();
});

// ── Batch helpers (checkMany / anyRequiresGraduateVerification) ────────

test('checkMany returns one result per item, in documents-then-certificates order', function () {
    $alumni   = frMakeAlumni();
    $docType  = frMakeUnlimitedDocType(accessId: 3);
    $certType = frMakeGraduateScopedCertType(limit: 1, accessId: 2);

    $results = frMakeService()->checkMany(
        $alumni,
        documents: [['document_type_id' => $docType->document_type_id]],
        certificates: [['certificate_type_id' => $certType->certificate_type_id]],
    );

    expect($results)->toHaveCount(2);
    expect($results[0]->kind)->toBe(FreeRequestItemKindEnum::Document);
    expect($results[1]->kind)->toBe(FreeRequestItemKindEnum::Certificate);
});

test('anyRequiresGraduateVerification is true when at least one item is graduate-scoped', function () {
    $alumni   = frMakeAlumni();
    $docType  = frMakeUnlimitedDocType(accessId: 3);
    $certType = frMakeGraduateScopedCertType(limit: 1, accessId: 2);

    $results = frMakeService()->checkMany(
        $alumni,
        documents: [['document_type_id' => $docType->document_type_id]],
        certificates: [['certificate_type_id' => $certType->certificate_type_id]],
    );

    expect(frMakeService()->anyRequiresGraduateVerification($results))->toBeTrue();
});

test('anyRequiresGraduateVerification is false when every item is unlimited (LOA-only filing)', function () {
    $student = frMakeStudent();
    $docType = frMakeUnlimitedDocType(accessId: 1);

    $results = frMakeService()->checkMany($student, documents: [['document_type_id' => $docType->document_type_id]], certificates: []);

    expect(frMakeService()->anyRequiresGraduateVerification($results))->toBeFalse();
});
