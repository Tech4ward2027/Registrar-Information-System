<?php

use App\DTOs\FreeRequest\FreeRequestEligibilityResult;
use App\Enums\FreeRequestItemKindEnum;
use App\Models\AlumniAcademicRecord;
use App\Models\AlumniProfile;
use App\Models\Alumni;
use App\Models\CertificationType;
use App\Models\DocumentType;
use App\Models\Policy;
use App\Models\RequestPurpose;
use App\Models\StudentAcademicRecord;
use App\Models\StudentProfile;
use App\Models\SystemUser;
use App\Services\FreeRequestEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * FESPEC-0008 — Free Document/Certificate Request.
 * Phase 7 — Security Hardening regression tests.
 *
 * Covers the three concrete gaps closed in this phase:
 *
 *   1. Quantity-vs-remaining (the real finding): before this phase,
 *      FreeRequestEligibilityService::check() only asked "has the
 *      free_issuance_limit already been fully claimed" — it never
 *      looked at THIS filing's own number_of_copies. A single filing
 *      for a graduate-scoped item (COG/TOR) with remaining = 1 but
 *      number_of_copies = 5 would previously sail through as eligible
 *      and issue 5 free copies in one shot, bypassing the First Copy
 *      Free Issuance Policy's one-time cap. See check()'s "Rule 5"
 *      docblock.
 *   2. Line-item array-size cap on both the Store and the Check
 *      (pre-check) FormRequests — fileFreeRequest() holds a row lock on
 *      the target user for the duration of the eligibility loop, so an
 *      uncapped documents/certificates array is a lock-contention/DoS
 *      vector.
 *   3. Rate limiting on POST /free-requests itself — previously the
 *      only free-request endpoint with no throttle at all, despite
 *      being the one that performs a real write.
 *
 * Deliberately self-contained: every fixture helper below uses a unique
 * `frh` prefix so this file can be loaded alongside
 * FreeRequestEligibilityServiceTest.php / FreeRequestServiceTest.php /
 * FreeRequestControllerTest.php / FreeRequestRegressionTest.php in the
 * same Pest run without risking a "cannot redeclare function" collision
 * — those files already establish this per-file-prefix convention
 * (frMake*, frsMake*, frcMake*) rather than sharing helpers across
 * files, and this file follows the same rule rather than assuming any
 * particular test-run ordering or process isolation.
 */
function frhMakeService(): FreeRequestEligibilityService
{
    return app(FreeRequestEligibilityService::class);
}

function frhMakeGraduateScopedCertType(int $limit = 1, int $accessId = 2): CertificationType
{
    return CertificationType::create([
        'certificate_name'           => 'Test Fixture COG (Hardening)',
        'certificate_requirements'   => 'Test fixture requirements.',
        'certificate_process_period' => '1 working day',
        'access_id'                  => $accessId,
        'is_free_eligible'           => true,
        'free_issuance_limit'        => $limit,
    ]);
}

function frhMakeUnlimitedDocType(int $accessId = 1): DocumentType
{
    return DocumentType::create([
        'document_name'           => 'Test Fixture LOA (Hardening)',
        'document_description'    => '',
        'document_process_period' => '1 day',
        'access_id'               => $accessId,
        'is_free_eligible'        => true,
        'free_issuance_limit'     => null,
    ]);
}

function frhMakeAlumni(): SystemUser
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
        'student_number'     => '2018-00001',
        'year_of_graduation' => 2022,
        'course'             => 'BSIT',
    ]);

    return $user->fresh();
}

function frhMakeStudent(): SystemUser
{
    $user    = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT, 'status' => 'Activated']);
    $profile = StudentProfile::factory()->create(['user_id' => $user->user_id]);
    StudentAcademicRecord::factory()->create(['student_profile_id' => $profile->student_profile_id]);

    return $user->fresh();
}

function frhMakeAdmin(array $actions): SystemUser
{
    $policy = Policy::create([
        'name'        => 'Test Hardening Free Requests ' . implode('-', $actions) . ' ' . uniqid(),
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

function frhPurposeId(): int
{
    return RequestPurpose::query()->value('request_purpose_id')
        ?? RequestPurpose::create(['purpose_name' => 'Personal Copy'])->request_purpose_id;
}

// ── 1. Quantity vs. remaining (FreeRequestEligibilityService::check()) ──

test('requesting more copies than remain for a graduate-scoped type is ineligible', function () {
    $alumni   = frhMakeAlumni();
    $certType = frhMakeGraduateScopedCertType(limit: 1); // exactly 1 remains, 0 prior claims

    $result = frhMakeService()->check(
        $alumni,
        FreeRequestItemKindEnum::Certificate,
        $certType->certificate_type_id,
        quantity: 2, // asking for 2 when only 1 is available
    );

    expect($result->eligible)->toBeFalse();
    expect($result->reasonCode)->toBe(FreeRequestEligibilityResult::REASON_QUANTITY_EXCEEDS_REMAINING);
    expect($result->remaining)->toBe(1);
});

test('requesting exactly the remaining quantity for a graduate-scoped type is still eligible', function () {
    $alumni   = frhMakeAlumni();
    $certType = frhMakeGraduateScopedCertType(limit: 1);

    $result = frhMakeService()->check(
        $alumni,
        FreeRequestItemKindEnum::Certificate,
        $certType->certificate_type_id,
        quantity: 1, // exactly what's left
    );

    expect($result->eligible)->toBeTrue();
    expect($result->remaining)->toBe(1);
});

test('a quantity omitted from checkMany() defaults to 1, matching every pre-Phase-7 call site', function () {
    $alumni   = frhMakeAlumni();
    $certType = frhMakeGraduateScopedCertType(limit: 1);

    $results = frhMakeService()->checkMany(
        $alumni,
        documents: [],
        certificates: [['certificate_type_id' => $certType->certificate_type_id]], // no number_of_copies key at all
    );

    expect($results[0]->eligible)->toBeTrue();
});

test('an unlimited type (LOA-style, free_issuance_limit = NULL) has no quantity cap at all', function () {
    $student = frhMakeStudent();
    $docType = frhMakeUnlimitedDocType();

    $result = frhMakeService()->check(
        $student,
        FreeRequestItemKindEnum::Document,
        $docType->document_type_id,
        quantity: 500, // absurdly large — still fine, LOA has no cap by policy
    );

    expect($result->eligible)->toBeTrue();
    expect($result->remaining)->toBeNull();
});

test('the real-world bypass this closes: 5 copies of a 1-remaining COG-style cert is rejected end-to-end via checkMany()', function () {
    $alumni   = frhMakeAlumni();
    $certType = frhMakeGraduateScopedCertType(limit: 1);

    $results = frhMakeService()->checkMany(
        $alumni,
        documents: [],
        certificates: [['certificate_type_id' => $certType->certificate_type_id, 'number_of_copies' => 5]],
    );

    expect($results[0]->eligible)->toBeFalse();
    expect($results[0]->reasonCode)->toBe(FreeRequestEligibilityResult::REASON_QUANTITY_EXCEEDS_REMAINING);
});

// ── 2. Line-item array-size cap ──────────────────────────────────────

test('filing with more than 20 certificate line items is rejected with a 422 validation error', function () {
    frhMakeAdmin(['View', 'File']);
    $alumni  = frhMakeAlumni();
    $certType = frhMakeGraduateScopedCertType(limit: 100, accessId: 2);

    // 21 line items referencing the same type — the point is the array
    // SIZE, not the content of each item.
    $certificates = array_fill(0, 21, ['certificate_type_id' => $certType->certificate_type_id, 'number_of_copies' => 1]);

    $this->postJson('/api/free-requests', [
        'target_user_id'     => $alumni->user_id,
        'request_purpose_id' => frhPurposeId(),
        'certificates'       => $certificates,
    ])->assertStatus(422)
      ->assertJsonValidationErrors(['certificates']);
});

test('the eligibility pre-check applies the same 20-item cap as filing', function () {
    frhMakeAdmin(['View']);
    $alumni   = frhMakeAlumni();
    $certType = frhMakeGraduateScopedCertType(limit: 100, accessId: 2);

    $certificates = array_fill(0, 21, ['certificate_type_id' => $certType->certificate_type_id]);

    $this->postJson('/api/free-requests/eligibility', [
        'target_user_id' => $alumni->user_id,
        'certificates'   => $certificates,
    ])->assertStatus(422)
      ->assertJsonValidationErrors(['certificates']);
});

// ── 3. Rate limiting on POST /free-requests ──────────────────────────

test('filing more than 10 free requests within a minute is throttled with a 429', function () {
    frhMakeAdmin(['View', 'File']);
    $student = frhMakeStudent();
    $docType = frhMakeUnlimitedDocType(); // unlimited, so every request keeps succeeding until the throttle itself intervenes

    $payload = [
        'target_user_id'     => $student->user_id,
        'request_purpose_id' => frhPurposeId(),
        'documents'          => [['document_type_id' => $docType->document_type_id, 'number_of_copies' => 1]],
    ];

    for ($i = 0; $i < 10; $i++) {
        $this->postJson('/api/free-requests', $payload)->assertCreated();
    }

    // The 11th request in the same minute, from the same admin, must be throttled.
    $this->postJson('/api/free-requests', $payload)->assertStatus(429);
});
