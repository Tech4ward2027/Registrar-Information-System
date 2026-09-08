<?php

use App\Enums\RequestStatusEnum;
use App\Models\Alumni;
use App\Models\AlumniAcademicRecord;
use App\Models\AlumniProfile;
use App\Models\CertificationType;
use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Models\Policy;
use App\Models\RequestHistory;
use App\Models\RequestPurpose;
use App\Models\StudentAcademicRecord;
use App\Models\SystemUser;
use App\Services\FreeRequestReportService;
use App\Services\FreeRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * FESPEC-0008 — Free Document/Certificate Request.
 * Phase 8 — Observability: the free-issuance monthly volume report.
 *
 * Deliberately self-contained: every fixture helper below uses a unique
 * `frv` prefix, following the same per-file-prefix convention already
 * established by FreeRequestEligibilityServiceTest (frMake*),
 * FreeRequestServiceTest (frs*), FreeRequestControllerTest (frc*),
 * FreeRequestSecurityHardeningTest (frh*), and FreeRequestRegressionTest
 * (frr*) — so this file can run alongside all five in the same Pest
 * process with no risk of "cannot redeclare function". (`frr` was
 * already taken by FreeRequestRegressionTest — `frv`, for "volume", is
 * the prefix actually used throughout this file.)
 *
 * Central fixture concern this file has that the others don't: getting
 * a free request into a genuinely CLAIMED state, with a controllable
 * claimed-at timestamp. FreeRequestReportService reads that timestamp
 * from request_history.changed_at (see that class's docblock for why),
 * not from any column on document_request itself — so frvClaimAt()
 * writes both status_id AND a request_history row, exactly mirroring
 * what DocumentRequestService::claimRequest() → recordHistory() would
 * have written for a real QR/claim_code scan, rather than relying on
 * document_request.status_id alone the way the lighter-weight
 * assertions in FreeRequestServiceTest do.
 */
function frvService(): FreeRequestService
{
    return app(FreeRequestService::class);
}

function frvReportService(): FreeRequestReportService
{
    return app(FreeRequestReportService::class);
}

function frvMakeAdmin(array $actions): SystemUser
{
    $policy = Policy::create([
        'name'        => 'Test Report Free Requests '.implode('-', $actions).' '.uniqid(),
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

function frvMakeAlumni(): SystemUser
{
    $user   = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ALUMNI, 'status' => 'Activated']);
    $alumni = Alumni::create([
        'user_id'        => $user->user_id,
        'alumni_type_id' => \App\Models\AlumniType::query()->value('alumni_type_id') ?? 1,
    ]);

    $profile = AlumniProfile::create([
        'alumni_id'     => $alumni->alumni_id,
        'first_name'    => 'Fixture',
        'last_name'     => 'Alumna',
        'date_of_birth' => '1998-01-01',
        'sex_at_birth'  => 'Female',
    ]);

    AlumniAcademicRecord::create([
        'alumni_profile_id'  => $profile->alumni_profile_id,
        'student_number'     => '2018-00099',
        'year_of_graduation' => 2022,
        'course'             => 'BSIT',
    ]);

    return $user->fresh();
}

function frvMakeGraduateScopedCertType(int $limit = 5, int $accessId = 2): CertificationType
{
    return CertificationType::create([
        'certificate_name'           => 'Test Fixture COG (Report)',
        'certificate_requirements'   => 'Test fixture requirements.',
        'certificate_process_period' => '1 working day',
        'access_id'                  => $accessId,
        'is_free_eligible'           => true,
        'free_issuance_limit'        => $limit,
    ]);
}

function frvMakeUnlimitedDocType(int $accessId = 1): DocumentType
{
    return DocumentType::create([
        'document_name'           => 'Test Fixture LOA (Report)',
        'document_description'    => '',
        'document_process_period' => '1 day',
        'access_id'               => $accessId,
        'is_free_eligible'        => true,
        'free_issuance_limit'     => null,
    ]);
}

function frvPurposeId(): int
{
    return RequestPurpose::query()->value('request_purpose_id')
        ?? RequestPurpose::create(['purpose_name' => 'Personal Copy'])->request_purpose_id;
}

/**
 * Advance $documentRequest to Completed and write the request_history
 * row a real claim would produce, backdated to $changedAt — the only
 * way to control "which month was this claimed in" for this report,
 * since request_history is an append-only log of when things actually
 * happened, not a value the report itself can be told directly.
 *
 * $changedAt is accepted in whatever timezone is convenient for the
 * test to reason in (Asia/Manila, per every call site below), but is
 * explicitly normalized to UTC before being written. `changed_at` is
 * a naive UTC DATETIME column (see RequestHistory's docblock and
 * config/app.php) populated in production exclusively via now(), which
 * is already in app.timezone (UTC) — Eloquent's `datetime` cast does
 * NOT convert a Carbon instance's timezone on save, it simply formats
 * the instant as-is. Passing a Manila-zoned Carbon straight through
 * would silently persist Manila wall-clock digits into a column every
 * reader (including this report's own UTC-window query) interprets as
 * UTC, shifting every timestamp 8 hours later than intended.
 */
function frvClaimAt(DocumentRequest $documentRequest, Carbon $changedAt): void
{
    $documentRequest->documents()->update(['status_id' => RequestStatusEnum::Completed->value]);
    $documentRequest->certificates()->update(['status_id' => RequestStatusEnum::Completed->value]);
    $documentRequest->update(['status_id' => RequestStatusEnum::Completed->value]);

    RequestHistory::create([
        'request_id'    => $documentRequest->request_id,
        'old_status_id' => RequestStatusEnum::ReadyToClaim->value,
        'new_status_id' => RequestStatusEnum::Completed->value,
        'changed_at'    => $changedAt->copy()->utc(),
        'changed_by'    => null, // matches ShredExpiredRequests/system-style rows; actor identity isn't this report's concern
    ]);
}

// ── Core aggregation ──────────────────────────────────────────────────

test('a claimed LOA request is counted in its claimed month under its own type label', function () {
    $actor   = frvMakeAdmin(['View', 'File']);
    $student = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT, 'status' => 'Activated']);
    $profile = \App\Models\StudentProfile::factory()->create(['user_id' => $student->user_id]);
    StudentAcademicRecord::factory()->create(['student_profile_id' => $profile->student_profile_id]);
    $docType = frvMakeUnlimitedDocType();

    $result = frvService()->fileFreeRequest($actor, $student->fresh(), [
        'request_purpose_id' => frvPurposeId(),
        'documents'          => [['document_type_id' => $docType->document_type_id, 'number_of_copies' => 1]],
        'certificates'       => [],
    ]);

    frvClaimAt($result->documentRequest, Carbon::create(2026, 3, 15, 9, 0, 0, 'Asia/Manila'));

    $rows = frvReportService()->monthlyVolume(2026);

    expect($rows->firstWhere('type_label', $docType->document_name))
        ->toMatchArray(['month' => '2026-03', 'type_label' => $docType->document_name, 'count' => 1]);
});

test('two claims of the same type in the same month are summed into one row', function () {
    $actor    = frvMakeAdmin(['View', 'File']);
    $docType  = frvMakeUnlimitedDocType();
    $students = [];

    for ($i = 0; $i < 2; $i++) {
        $student = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT, 'status' => 'Activated']);
        $profile = \App\Models\StudentProfile::factory()->create(['user_id' => $student->user_id]);
        StudentAcademicRecord::factory()->create(['student_profile_id' => $profile->student_profile_id]);

        $result = frvService()->fileFreeRequest($actor, $student->fresh(), [
            'request_purpose_id' => frvPurposeId(),
            'documents'          => [['document_type_id' => $docType->document_type_id, 'number_of_copies' => 1]],
            'certificates'       => [],
        ]);

        frvClaimAt($result->documentRequest, Carbon::create(2026, 5, 10 + $i, 9, 0, 0, 'Asia/Manila'));
    }

    $rows = frvReportService()->monthlyVolume(2026);
    $row  = $rows->firstWhere('type_label', $docType->document_name);

    expect($row['month'])->toBe('2026-05');
    expect($row['count'])->toBe(2);
});

test('a filed but never-claimed (Forfeited) free request contributes nothing', function () {
    $actor   = frvMakeAdmin(['View', 'File']);
    $alumni  = frvMakeAlumni();
    $docType = frvMakeUnlimitedDocType(accessId: 3);

    $result = frvService()->fileFreeRequest($actor, $alumni, [
        'request_purpose_id' => frvPurposeId(),
        'documents'          => [['document_type_id' => $docType->document_type_id, 'number_of_copies' => 1]],
        'certificates'       => [],
    ]);

    // Forfeited, never Completed — no request_history row into Completed
    // is ever written, matching how the automated shredder actually
    // leaves an unclaimed request.
    $result->documentRequest->update(['status_id' => RequestStatusEnum::Forfeited->value]);

    $rows = frvReportService()->monthlyVolume(now('Asia/Manila')->year);

    expect($rows->firstWhere('type_label', $docType->document_name))->toBeNull();
});

test('a self-service (paid) claimed request is excluded from the free-issuance report', function () {
    $selfServiceRequest = DocumentRequest::factory()->create(['channel' => 'self_service']);

    frvClaimAt($selfServiceRequest, Carbon::create(2026, 3, 20, 9, 0, 0, 'Asia/Manila'));

    $rows = frvReportService()->monthlyVolume(2026);

    // The self-service request has no request_document/request_certificate
    // rows in this fixture, so its absence alone doesn't prove the
    // channel filter works — assert directly that the underlying query
    // never even considers it.
    expect(DocumentRequest::query()->adminFiledFree()->whereKey($selfServiceRequest->request_id)->exists())
        ->toBeFalse();
    expect($rows)->toHaveCount(0);
});

test('a claim outside the requested year is excluded', function () {
    $actor   = frvMakeAdmin(['View', 'File']);
    $alumni  = frvMakeAlumni();
    $docType = frvMakeUnlimitedDocType(accessId: 3);

    $result = frvService()->fileFreeRequest($actor, $alumni, [
        'request_purpose_id' => frvPurposeId(),
        'documents'          => [['document_type_id' => $docType->document_type_id, 'number_of_copies' => 1]],
        'certificates'       => [],
    ]);

    // Claimed in December 2025 — querying for 2026 must not pick this up.
    frvClaimAt($result->documentRequest, Carbon::create(2025, 12, 31, 23, 0, 0, 'Asia/Manila'));

    $rows2026 = frvReportService()->monthlyVolume(2026);
    $rows2025 = frvReportService()->monthlyVolume(2025);

    expect($rows2026->firstWhere('type_label', $docType->document_name))->toBeNull();
    expect($rows2025->firstWhere('type_label', $docType->document_name)['count'])->toBe(1);
});

test('a claim just after a Manila calendar-year boundary in UTC is attributed to the correct Manila year', function () {
    // 2026-01-01 07:00 Asia/Manila = 2025-12-31 23:00 UTC. A naive
    // whereYear('changed_at', ...) against the raw UTC column would
    // wrongly bucket this into 2025 — this is exactly the boundary bug
    // AuditLogController::resolveDateBoundary() already exists to avoid
    // for audit log filters, and FreeRequestReportService::monthlyVolume()
    // must get right the same way.
    $actor   = frvMakeAdmin(['View', 'File']);
    $alumni  = frvMakeAlumni();
    $docType = frvMakeUnlimitedDocType(accessId: 3);

    $result = frvService()->fileFreeRequest($actor, $alumni, [
        'request_purpose_id' => frvPurposeId(),
        'documents'          => [['document_type_id' => $docType->document_type_id, 'number_of_copies' => 1]],
        'certificates'       => [],
    ]);

    frvClaimAt($result->documentRequest, Carbon::create(2026, 1, 1, 7, 0, 0, 'Asia/Manila'));

    $rows = frvReportService()->monthlyVolume(2026);
    $row  = $rows->firstWhere('type_label', $docType->document_name);

    expect($row['month'])->toBe('2026-01');
});

test('an ineligible-but-overridden filing is still counted once claimed, same as any other free issuance', function () {
    // limit: 0 makes this a graduate-scoped (COG/TOR-like) cert type —
    // free_issuance_limit !== null — so per FreeRequestService's
    // capability rules it needs BOTH Override (to bypass the
    // already-exhausted eligibility check) AND Verify (graduate
    // verification is still required for this type regardless of the
    // override), plus a completed verification confirmation. Mirrors
    // the same combination FreeRequestServiceTest's COG-with-Verify
    // tests exercise individually.
    $actor    = frvMakeAdmin(['View', 'File', 'Verify', 'Override']);
    $alumni   = frvMakeAlumni();
    $certType = frvMakeGraduateScopedCertType(limit: 0); // 0 remaining — forces an override

    $result = frvService()->fileFreeRequest(
        actor: $actor,
        targetUser: $alumni,
        validated: [
            'request_purpose_id' => frvPurposeId(),
            'documents'          => [],
            'certificates'       => [['certificate_type_id' => $certType->certificate_type_id, 'number_of_copies' => 1]],
        ],
        options: [
            'override'        => true,
            'override_reason' => 'Test fixture override.',
            'verification'    => ['credentials_verified' => true, 'records_checked' => true],
        ],
    );

    frvClaimAt($result->documentRequest, Carbon::create(2026, 7, 1, 9, 0, 0, 'Asia/Manila'));

    $rows = frvReportService()->monthlyVolume(2026);

    expect($rows->firstWhere('type_label', $certType->certificate_name))
        ->toMatchArray(['month' => '2026-07', 'type_label' => $certType->certificate_name, 'count' => 1]);
});

// ── HTTP layer ─────────────────────────────────────────────────────────

test('the report endpoint requires the free_requests View capability', function () {
    frvMakeAdmin([]); // no actions at all

    $this->getJson('/api/free-requests/reports/monthly-volume?year=2026')
        ->assertStatus(403);
});

test('the report endpoint returns claimed volume grouped by month and type', function () {
    $actor   = frvMakeAdmin(['View', 'File']);
    $docType = frvMakeUnlimitedDocType(accessId: 3);
    $alumni  = frvMakeAlumni();

    $result = frvService()->fileFreeRequest($actor, $alumni, [
        'request_purpose_id' => frvPurposeId(),
        'documents'          => [['document_type_id' => $docType->document_type_id, 'number_of_copies' => 1]],
        'certificates'       => [],
    ]);
    frvClaimAt($result->documentRequest, Carbon::create(2026, 4, 5, 9, 0, 0, 'Asia/Manila'));

    $response = $this->getJson('/api/free-requests/reports/monthly-volume?year=2026')
        ->assertStatus(200)
        ->assertJson(['year' => 2026]);

    $data = $response->json('data');

    expect(collect($data)->firstWhere('type_label', $docType->document_name))
        ->toMatchArray(['month' => '2026-04', 'type_label' => $docType->document_name, 'count' => 1]);
});

test('an out-of-range year is rejected with a validation error, not silently clamped', function () {
    frvMakeAdmin(['View']);

    $this->getJson('/api/free-requests/reports/monthly-volume?year=1899')
        ->assertStatus(422);
});