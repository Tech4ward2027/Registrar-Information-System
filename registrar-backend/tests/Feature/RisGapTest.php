<?php

use App\Models\Alumni;
use App\Models\AlumniAcademicRecord;
use App\Models\AlumniProfile;
use App\Models\AlumniType;
use App\Models\Announcement;
use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Models\RequestPurpose;
use App\Models\RequestStatus;
use App\Models\StudentAcademicRecord;
use App\Models\StudentProfile;
use App\Models\SystemUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// ── Helpers ───────────────────────────────────────────────────────────────────

function gapMakeUser(int $roleId): SystemUser
{
    $user = SystemUser::factory()->create(['role_id' => $roleId, 'status' => 'Activated']);

    // See tests/Pest.php::grantFullDashboardAccess() — a plain admin has
    // zero dashboard access without an attached policy since Work Item #1.
    grantFullDashboardAccess($user);

    Sanctum::actingAs($user);
    return $user;
}

function gapSeedStatuses(): void
{
    foreach ([1 => 'Processing', 2 => 'Ready to Claim', 3 => 'Completed', 4 => 'Forfeited', 5 => 'Cancelled'] as $id => $name) {
        RequestStatus::firstOrCreate(['status_id' => $id], ['status_name' => $name]);
    }
}

function gapSeedRefData(): array
{
    gapSeedStatuses();
    $purpose = RequestPurpose::firstOrCreate(['request_purpose_id' => 1], ['purpose_name' => 'DFA']);
    $docType = DocumentType::firstOrCreate(
        ['document_type_id' => 1],
        ['document_name' => 'Transcript of Records', 'document_description' => '', 'document_process_period' => 5, 'access_id' => 1]
    );
    return compact('purpose', 'docType');
}

/**
 * Create an alumni user with the full chain:
 *   alumni_type → alumni → alumni_profile → alumni_academic_record
 * The service aborts(400) if profile or academic record is missing.
 */
function gapMakeAlumniWithProfile(array $nameOverrides = []): array
{
    $user = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ALUMNI, 'status' => 'Activated']);

    $alumniType = AlumniType::firstOrCreate(['alumni_type_id' => 1], ['alumni_type' => 'Regular']);

    $alumni = Alumni::create([
        'user_id'        => $user->user_id,
        'alumni_type_id' => $alumniType->alumni_type_id,
    ]);

    $profile = AlumniProfile::create(array_merge([
        'alumni_id'     => $alumni->alumni_id,
        'first_name'    => 'Maria',
        'last_name'     => 'Santos',
        'middle_name'   => 'Cruz',
        'suffix'        => '',
        'date_of_birth' => '1995-06-15',
        'sex_at_birth'  => 'Female',
    ], $nameOverrides));

    AlumniAcademicRecord::create([
        'alumni_profile_id'  => $profile->alumni_profile_id,
        'year_of_graduation' => 2018,
        'course'             => 'BS Information Technology',
    ]);

    Sanctum::actingAs($user);

    return compact('user', 'alumni', 'profile');
}

// ═════════════════════════════════════════════════════════════════════════════
// GAP 1 — DocumentRequestPolicy: view isolation
//
// The show route uses {id} (not {documentRequest}), so Laravel resolves the
// model by the table's PK. DocumentRequest declares $primaryKey = 'request_id',
// so route model binding still finds the right row — but the JSON response key
// is also 'request_id', not 'id'.
// ═════════════════════════════════════════════════════════════════════════════

test('student cannot view another students document request', function () {
    gapSeedStatuses();

    $owner        = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT, 'status' => 'Activated']);
    $ownerProfile = StudentProfile::factory()->create(['user_id' => $owner->user_id]);
    StudentAcademicRecord::factory()->create(['student_profile_id' => $ownerProfile->student_profile_id]);

    $docRequest = DocumentRequest::factory()->create(['user_id' => $owner->user_id]);

    // Different student tries to read it
    $other        = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT, 'status' => 'Activated']);
    $otherProfile = StudentProfile::factory()->create(['user_id' => $other->user_id]);
    StudentAcademicRecord::factory()->create(['student_profile_id' => $otherProfile->student_profile_id]);
    Sanctum::actingAs($other);

    $this->getJson("/api/v1/document-requests/{$docRequest->request_id}")
         ->assertStatus(403);
});

test('student can view their own document request', function () {
    gapSeedStatuses();

    $user    = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT, 'status' => 'Activated']);
    $profile = StudentProfile::factory()->create(['user_id' => $user->user_id]);
    StudentAcademicRecord::factory()->create(['student_profile_id' => $profile->student_profile_id]);

    $docRequest = DocumentRequest::factory()->create(['user_id' => $user->user_id]);
    Sanctum::actingAs($user);

    $this->getJson("/api/v1/document-requests/{$docRequest->request_id}")
         ->assertOk()
         ->assertJsonPath('request_id', $docRequest->request_id);
});

test('admin can view any students document request', function () {
    gapSeedStatuses();

    $owner      = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT, 'status' => 'Activated']);
    $docRequest = DocumentRequest::factory()->create(['user_id' => $owner->user_id]);

    gapMakeUser(SystemUser::ROLE_ADMIN);

    $this->getJson("/api/v1/document-requests/{$docRequest->request_id}")
         ->assertOk()
         // The JSON uses 'request_id' (the model's $primaryKey), not 'id'
         ->assertJsonPath('request_id', $docRequest->request_id);
});

// ═════════════════════════════════════════════════════════════════════════════
// GAP 2 — Alumni submission path
// DocumentRequestService::createRequest aborts(400) when alumni_profile or
// alumni_academic_record is missing, so both must be seeded.
// ═════════════════════════════════════════════════════════════════════════════

test('alumni can submit a document request in mock mode', function () {
    config(['services.cashier.api_key' => '']);

    ['user' => $user] = gapMakeAlumniWithProfile();
    ['purpose' => $purpose, 'docType' => $docType] = gapSeedRefData();

    $this->postJson('/api/v1/document-requests', [
        'request_purpose_id' => $purpose->request_purpose_id,
        'or_number'          => '7654321',
        'receipt_date'       => now()->toDateString(),
        'documents'          => [['document_type_id' => $docType->document_type_id, 'number_of_copies' => 1]],
    ])->assertCreated()
      ->assertJsonPath('user_id', $user->user_id);
});

test('alumni request is rejected when OR is not found in cashier API', function () {
    config(['services.cashier.api_key' => 'test-key']);

    Http::fake(['*' => Http::response(['valid' => false, 'reason' => 'NOT_FOUND', 'data' => null], 200)]);

    gapMakeAlumniWithProfile();
    ['purpose' => $purpose, 'docType' => $docType] = gapSeedRefData();

    $this->postJson('/api/v1/document-requests', [
        'request_purpose_id' => $purpose->request_purpose_id,
        'or_number'          => '0000000',
        'receipt_date'       => now()->toDateString(),
        'documents'          => [['document_type_id' => $docType->document_type_id, 'number_of_copies' => 1]],
    ])->assertStatus(422)
      ->assertJsonPath('errors.or_number.0', fn ($msg) => str_contains($msg, 'could not be found'));
});

test('alumni request is rejected when cashier API is down', function () {
    config(['services.cashier.api_key' => 'test-key']);

    Http::fake(['*' => Http::response([], 500)]);

    gapMakeAlumniWithProfile();
    ['purpose' => $purpose, 'docType' => $docType] = gapSeedRefData();

    $this->postJson('/api/v1/document-requests', [
        'request_purpose_id' => $purpose->request_purpose_id,
        'or_number'          => '1234567',
        'receipt_date'       => now()->toDateString(),
        'documents'          => [['document_type_id' => $docType->document_type_id, 'number_of_copies' => 1]],
    ])->assertStatus(422)
      ->assertJsonPath('errors.or_number.0', fn ($msg) => str_contains($msg, 'temporarily unavailable'));
});

// ═════════════════════════════════════════════════════════════════════════════
// GAP 3 — Name mismatch / unknown cashier rejection reason
// ═════════════════════════════════════════════════════════════════════════════

test('document request is rejected with generic message on unknown cashier reason', function () {
    config(['services.cashier.api_key' => 'test-key']);

    Http::fake(['*' => Http::response([
        'valid'  => false,
        'reason' => 'NAME_MISMATCH',
        'data'   => null,
    ], 200)]);

    $user    = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT, 'status' => 'Activated']);
    $profile = StudentProfile::factory()->create(['user_id' => $user->user_id]);
    StudentAcademicRecord::factory()->create(['student_profile_id' => $profile->student_profile_id]);
    Sanctum::actingAs($user);

    ['purpose' => $purpose, 'docType' => $docType] = gapSeedRefData();

    $this->postJson('/api/v1/document-requests', [
        'request_purpose_id' => $purpose->request_purpose_id,
        'or_number'          => '1234567',
        'receipt_date'       => now()->toDateString(),
        'documents'          => [['document_type_id' => $docType->document_type_id, 'number_of_copies' => 1]],
    ])->assertStatus(422)
      ->assertJsonPath('errors.or_number.0', fn ($msg) => str_contains($msg, 'contact the registrar'));
});

// ═════════════════════════════════════════════════════════════════════════════
// GAP 4 — Announcement RBAC
//
// Announcement write routes are role:4 (superadmin), not role:3 (admin).
// The 'announcements' table has a NOT NULL 'created_by' column, so we can't
// use Announcement::create() directly in tests — go through the API instead,
// or use a superadmin user to seed records.
// ═════════════════════════════════════════════════════════════════════════════

test('student cannot create an announcement', function () {
    gapMakeUser(SystemUser::ROLE_STUDENT);

    $this->postJson('/api/v1/announcements', [
        'title'   => 'Test',
        'content' => 'Should not be allowed',
    ])->assertStatus(403);
});

test('alumni cannot create an announcement', function () {
    gapMakeAlumniWithProfile();

    $this->postJson('/api/v1/announcements', [
        'title'   => 'Alumni Announcement',
        'content' => 'Should be rejected',
    ])->assertStatus(403);
});

test('admin cannot create an announcement', function () {
    // Announcement write routes are role:4 (superadmin) only, not role:3 (admin)
    gapMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson('/api/v1/announcements', [
        'title'   => 'Admin Attempt',
        'content' => 'Should also be rejected',
    ])->assertStatus(403);
});

test('superadmin can create an announcement', function () {
    gapMakeUser(SystemUser::ROLE_SUPER_ADMIN);

    $this->postJson('/api/v1/announcements', [
        'title'   => 'Office Hours Update',
        'content' => 'The registrar office will be closed on Friday.',
    ])->assertCreated();
});

test('superadmin can delete an announcement', function () {
    // Create via API as superadmin so created_by is set correctly
    gapMakeUser(SystemUser::ROLE_SUPER_ADMIN);

    $created = $this->postJson('/api/v1/announcements', [
        'title'   => 'To Be Deleted',
        'content' => 'Content',
    ])->assertCreated()->json();

    $this->deleteJson("/api/v1/announcements/{$created['id']}")
         ->assertOk();
});

test('student cannot delete an announcement', function () {
    // Seed the announcement as superadmin, then switch to student
    $superAdmin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN, 'status' => 'Activated']);
    Sanctum::actingAs($superAdmin);

    $created = $this->postJson('/api/v1/announcements', [
        'title'   => 'Existing',
        'content' => 'Content',
    ])->assertCreated()->json();

    // Now act as student
    gapMakeUser(SystemUser::ROLE_STUDENT);

    $this->deleteJson("/api/v1/announcements/{$created['id']}")
         ->assertStatus(403);
});