<?php

use App\Models\AlumniAcademicRecord;
use App\Models\AlumniProfile;
use App\Models\Alumni;
use App\Models\AuditLog;
use App\Models\CertificationType;
use App\Models\DocumentType;
use App\Models\Policy;
use App\Models\RequestPurpose;
use App\Models\StudentAcademicRecord;
use App\Models\StudentProfile;
use App\Models\SystemUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * FESPEC-0008 — Free Document/Certificate Request.
 *
 * HTTP-layer tests for FreeRequestController: route/module gating,
 * response shape, and audit logging. Business-rule correctness
 * (eligibility, locking, override/verification enforcement) is already
 * covered in tests/Feature/FreeRequestServiceTest.php — these tests
 * confirm the controller wires that logic up correctly over real HTTP,
 * matching this codebase's own convention of splitting service-level
 * and controller-level tests (see CashierTest.php vs.
 * CashierOrFirstFlowIntegrationTest.php).
 */
function frcMakeAdmin(array $actions): SystemUser
{
    $policy = Policy::create([
        'name'        => 'Test Controller Free Requests ' . implode('-', $actions) . ' ' . uniqid(),
        'permissions' => ['free_requests' => $actions],
        'is_system'   => false,
    ]);

    $admin = SystemUser::factory()->create([
        'role_id'   => SystemUser::ROLE_ADMIN,
        'status'    => 'Activated',
        'policy_id' => $policy->policy_id,
    ]);

    Sanctum::actingAs($admin);

    return $admin;
}

function frcMakeAlumni(): SystemUser
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

function frcMakeStudent(): SystemUser
{
    $user    = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT, 'status' => 'Activated']);
    $profile = StudentProfile::factory()->create(['user_id' => $user->user_id]);
    StudentAcademicRecord::factory()->create(['student_profile_id' => $profile->student_profile_id]);

    return $user->fresh();
}

function frcMakeGraduateScopedCertType(int $limit = 1, int $accessId = 2): CertificationType
{
    return CertificationType::create([
        'certificate_name'    => 'Test Fixture COG (Controller)',
        'certificate_requirements'    => 'Test fixture requirements.',
        'certificate_process_period'  => '1 working day',
        'access_id'           => $accessId,
        'is_free_eligible'    => true,
        'free_issuance_limit' => $limit,
    ]);
}

function frcMakeUnlimitedDocType(int $accessId = 1): DocumentType
{
    return DocumentType::create([
        'document_name'           => 'Test Fixture LOA (Controller)',
        'document_description'    => '',
        'document_process_period' => '1 day',
        'access_id'               => $accessId,
        'is_free_eligible'        => true,
        'free_issuance_limit'     => null,
    ]);
}

function frcPurposeId(): int
{
    return RequestPurpose::query()->value('request_purpose_id')
        ?? RequestPurpose::create(['purpose_name' => 'Personal Copy'])->request_purpose_id;
}

// ── GET /free-requests/search-accounts ──────────────────────────────

test('search-accounts requires the free_requests View module grant', function () {
    $admin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN, 'status' => 'Activated']); // no policy attached => "No Access"
    Sanctum::actingAs($admin);

    $this->getJson('/api/free-requests/search-accounts?q=zen')->assertForbidden();
});

test('search-accounts returns matching accounts and writes an audit log entry', function () {
    frcMakeAdmin(['View']);
    $student = frcMakeStudent();
    $student->studentProfile->update(['first_name' => 'Zendaya']);

    $response = $this->getJson('/api/free-requests/search-accounts?q=Zendaya');

    $response->assertOk();
    $response->assertJsonFragment(['user_id' => $student->user_id]);

    expect(AuditLog::where('action', AuditLog::ACTION_FREE_REQUEST_ACCOUNT_SEARCHED)->exists())->toBeTrue();
});

test('search-accounts rejects a query shorter than 2 characters', function () {
    frcMakeAdmin(['View']);

    $this->getJson('/api/free-requests/search-accounts?q=z')->assertStatus(422);
});

test('search-accounts narrows a name match to the correct account using student_number', function () {
    frcMakeAdmin(['View']);

    $studentA = frcMakeStudent();
    $studentA->studentProfile->update(['first_name' => 'Juan', 'last_name' => 'Dela Cruz']);
    $studentA->academicRecord->update(['student_number' => '2021-00001']);

    $studentB = frcMakeStudent();
    $studentB->studentProfile->update(['first_name' => 'Juan', 'last_name' => 'Dela Cruz']);
    $studentB->academicRecord->update(['student_number' => '2021-00002']);

    // Both share the same name — a plain name search would return both.
    $unfiltered = $this->getJson('/api/free-requests/search-accounts?q=Dela Cruz');
    $unfiltered->assertOk();
    expect(count($unfiltered->json('data')))->toBe(2);

    // Adding student_number narrows to exactly one.
    $filtered = $this->getJson('/api/free-requests/search-accounts?q=Dela Cruz&student_number=2021-00002');
    $filtered->assertOk();
    $filtered->assertJsonFragment(['user_id' => $studentB->user_id]);
    expect(count($filtered->json('data')))->toBe(1);
});

test('search-accounts narrows a name match to the correct account using program', function () {
    frcMakeAdmin(['View']);

    $studentA = frcMakeStudent();
    $studentA->studentProfile->update(['first_name' => 'Maria', 'last_name' => 'Santos']);
    $studentA->academicRecord->update(['course' => 'BSIT']);

    $studentB = frcMakeStudent();
    $studentB->studentProfile->update(['first_name' => 'Maria', 'last_name' => 'Santos']);
    $studentB->academicRecord->update(['course' => 'BSCS']);

    $filtered = $this->getJson('/api/free-requests/search-accounts?q=Santos&program=BSCS');

    $filtered->assertOk();
    $filtered->assertJsonFragment(['user_id' => $studentB->user_id]);
    expect(count($filtered->json('data')))->toBe(1);
});

test('search-accounts filters are AND, not OR — a student_number that matches no result for this name returns empty', function () {
    frcMakeAdmin(['View']);

    $student = frcMakeStudent();
    $student->studentProfile->update(['first_name' => 'Pedro', 'last_name' => 'Reyes']);
    $student->academicRecord->update(['student_number' => '2021-00099']);

    $response = $this->getJson('/api/free-requests/search-accounts?q=Reyes&student_number=9999999');

    $response->assertOk();
    expect($response->json('data'))->toBeEmpty();
});

test('search-accounts matches an alumni by student_number carried on their alumni academic record', function () {
    frcMakeAdmin(['View']);

    $alumni = frcMakeAlumni(); // fixture default student_number is '2018-00001'

    $response = $this->getJson('/api/free-requests/search-accounts?q=Alumna&student_number=2018-00001');

    $response->assertOk();
    $response->assertJsonFragment(['user_id' => $alumni->user_id]);
});

// ── POST /free-requests/eligibility ─────────────────────────────────

test('eligibility check returns a per-item result without creating a request', function () {
    frcMakeAdmin(['View']);
    $alumni   = frcMakeAlumni();
    $certType = frcMakeGraduateScopedCertType();

    $response = $this->postJson('/api/free-requests/eligibility', [
        'target_user_id' => $alumni->user_id,
        'certificates'   => [['certificate_type_id' => $certType->certificate_type_id]],
    ]);

    $response->assertOk();
    $response->assertJsonPath('results.0.eligible', true);
    $response->assertJsonPath('results.0.requires_graduate_verification', true);
});

test('eligibility check requires the free_requests View module grant', function () {
    $admin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN, 'status' => 'Activated']);
    Sanctum::actingAs($admin);
    $alumni = frcMakeAlumni();

    $this->postJson('/api/free-requests/eligibility', ['target_user_id' => $alumni->user_id])
        ->assertForbidden();
});

// ── POST /free-requests ──────────────────────────────────────────────

test('filing a free request requires the free_requests File module grant (View alone is not enough)', function () {
    frcMakeAdmin(['View']); // no File
    $student = frcMakeStudent();
    $docType = frcMakeUnlimitedDocType(accessId: 1);

    $this->postJson('/api/free-requests', [
        'target_user_id'      => $student->user_id,
        'request_purpose_id'  => frcPurposeId(),
        'documents'           => [['document_type_id' => $docType->document_type_id, 'number_of_copies' => 1]],
    ])->assertForbidden();
});

test('filing a free LOA request end-to-end returns 201 with the loaded document_request and writes an audit log', function () {
    $actor   = frcMakeAdmin(['View', 'File']);
    $student = frcMakeStudent();
    $docType = frcMakeUnlimitedDocType(accessId: 1);

    $response = $this->postJson('/api/free-requests', [
        'target_user_id'     => $student->user_id,
        'request_purpose_id' => frcPurposeId(),
        'documents'          => [['document_type_id' => $docType->document_type_id, 'number_of_copies' => 1]],
    ]);

    $response->assertCreated();
    $response->assertJsonPath('graduate_verification_performed', false);
    $response->assertJsonPath('document_request.channel', 'admin_filed_free');

    // Per the original FESPEC-0008 test plan: "claim code generated" —
    // DocumentRequest::booted() assigns both uuid and claim_code on
    // creation for every channel, free-filed requests included, since
    // FreeRequestService::fileFreeRequest() delegates row creation to
    // the same DocumentRequestService::createRequest() the self-service
    // flow uses. "Appears on both dashboards" (the student/alumni
    // dashboard and the staff dashboard) is a consequence of this same
    // row being the one and only DocumentRequest for this filing — no
    // separate free-request table/pipeline exists — and is exercised
    // for the shared underlying query in this codebase's own dashboard
    // tests (e.g. GranularDashboardPermissionsTest), so it is not
    // re-verified here to avoid duplicating that coverage.
    expect($response->json('document_request.claim_code'))->not->toBeNull();
    expect($response->json('document_request.uuid'))->not->toBeNull();

    expect(
        AuditLog::where('action', AuditLog::ACTION_FREE_REQUEST_FILED)
            ->where('user_id', $actor->user_id)
            ->exists()
    )->toBeTrue();
});

test('filing a free COG request without verification confirmation returns 422', function () {
    frcMakeAdmin(['View', 'File', 'Verify']);
    $alumni   = frcMakeAlumni();
    $certType = frcMakeGraduateScopedCertType();

    $this->postJson('/api/free-requests', [
        'target_user_id'     => $alumni->user_id,
        'request_purpose_id' => frcPurposeId(),
        'certificates'       => [['certificate_type_id' => $certType->certificate_type_id]],
    ])->assertStatus(422);
});

test('filing a free COG request with verification confirmation returns 201 and logs the graduate verification', function () {
    $actor    = frcMakeAdmin(['View', 'File', 'Verify']);
    $alumni   = frcMakeAlumni();
    $certType = frcMakeGraduateScopedCertType();

    $response = $this->postJson('/api/free-requests', [
        'target_user_id'     => $alumni->user_id,
        'request_purpose_id' => frcPurposeId(),
        'certificates'       => [['certificate_type_id' => $certType->certificate_type_id]],
        'verification'       => ['credentials_verified' => true, 'records_checked' => true],
    ]);

    $response->assertCreated();
    $response->assertJsonPath('graduate_verification_performed', true);

    expect(
        AuditLog::where('action', AuditLog::ACTION_FREE_REQUEST_GRADUATE_VERIFIED)
            ->where('user_id', $actor->user_id)
            ->exists()
    )->toBeTrue();
});

test('filing a duplicate claimed free COG request is rejected with a structured 422 payload', function () {
    $actor    = frcMakeAdmin(['View', 'File', 'Verify']);
    $alumni   = frcMakeAlumni();
    $certType = frcMakeGraduateScopedCertType(limit: 1);

    $payload = [
        'target_user_id'     => $alumni->user_id,
        'request_purpose_id' => frcPurposeId(),
        'certificates'       => [['certificate_type_id' => $certType->certificate_type_id]],
        'verification'       => ['credentials_verified' => true, 'records_checked' => true],
    ];

    $first = $this->postJson('/api/free-requests', $payload)->assertCreated();

    // Simulate the graduate having actually claimed the first copy.
    $requestId = $first->json('document_request.request_id');
    \App\Models\DocumentRequest::where('request_id', $requestId)
        ->update(['status_id' => \App\Enums\RequestStatusEnum::Completed->value]);
    \App\Models\RequestCertificate::where('request_id', $requestId)
        ->update(['status_id' => \App\Enums\RequestStatusEnum::Completed->value]);

    $second = $this->postJson('/api/free-requests', $payload);

    $second->assertStatus(422);
    $second->assertJsonStructure(['message', 'errors' => [['eligible', 'reason_code']]]);
    $second->assertJsonPath('errors.0.reason_code', \App\DTOs\FreeRequest\FreeRequestEligibilityResult::REASON_LIMIT_REACHED);
});

test('overriding an ineligible item without an override_reason returns a 422 validation error', function () {
    frcMakeAdmin(['View', 'File', 'Override']);
    $alumni   = frcMakeAlumni();
    $certType = CertificationType::create([
        'certificate_name'    => 'Test Fixture Not Eligible (Controller)',
        'certificate_requirements'    => 'Test fixture requirements.',
        'certificate_process_period'  => '1 working day',
        'access_id'           => 2,
        'is_free_eligible'    => false,
        'free_issuance_limit' => null,
    ]);

    $this->postJson('/api/free-requests', [
        'target_user_id'     => $alumni->user_id,
        'request_purpose_id' => frcPurposeId(),
        'certificates'       => [['certificate_type_id' => $certType->certificate_type_id]],
        'override'           => true,
    ])->assertStatus(422);
});

test('a student/alumni account cannot reach the free-requests endpoints at all (role gate)', function () {
    $student = frcMakeStudent();
    Sanctum::actingAs($student);

    $this->getJson('/api/free-requests/search-accounts?q=zen')->assertForbidden();
});