<?php

use App\Models\AccessType;
use App\Models\CertificationType;
use App\Models\DocumentRequest;
use App\Models\RequestCertificate;
use App\Models\RequestStatus;
use App\Models\SystemUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// ── Helpers ───────────────────────────────────────────────────────────────────
//
// Mirrors the gapMakeUser()/gapSeedStatuses() helpers in RisGapTest.php so
// this file follows the same conventions as the rest of the suite.

function certMakeUser(int $roleId): SystemUser
{
    $user = SystemUser::factory()->create(['role_id' => $roleId, 'status' => 'Activated']);
    Sanctum::actingAs($user);
    return $user;
}

function certSeedAccessType(): AccessType
{
    return AccessType::firstOrCreate(['access_id' => 1], ['access_name' => 'Student']);
}

function certMakeType(array $overrides = []): CertificationType
{
    $access = certSeedAccessType();

    return CertificationType::create(array_merge([
        'certificate_name'           => 'Certificate of Enrollment',
        'certificate_requirements'   => 'Valid ID',
        'certificate_process_period' => '3-5 business days',
        'access_id'                  => $access->access_id,
    ], $overrides));
}

function certSeedStatuses(): void
{
    foreach ([1 => 'Processing', 2 => 'Ready to Claim', 3 => 'Completed', 4 => 'Forfeited'] as $id => $name) {
        RequestStatus::firstOrCreate(['status_id' => $id], ['status_name' => $name]);
    }
}

// ═════════════════════════════════════════════════════════════════════════════
// Read routes — open to all authenticated roles (no role:N middleware)
// ═════════════════════════════════════════════════════════════════════════════

test('any authenticated role can list certification types', function () {
    // Not assertJsonCount(1): TestCase::$seed = true means DatabaseSeeder's
    // ~17 reference certificate_type rows are already present before this
    // test's own row is created. What this test verifies — that the
    // endpoint is reachable by any authenticated role and returns the type
    // we just created — doesn't require table isolation.
    $type = certMakeType();
    certMakeUser(SystemUser::ROLE_STUDENT);

    $this->getJson('/api/v1/certifications')
         ->assertOk()
         ->assertJsonFragment(['certificate_type_id' => $type->certificate_type_id]);
});

test('show returns 404 for a missing certification type', function () {
    certMakeUser(SystemUser::ROLE_STUDENT);

    $this->getJson('/api/v1/certifications/999')
         ->assertStatus(404)
         ->assertJson(['message' => 'Certification type not found']);
});

test('show returns the certification type for a valid id', function () {
    $cert = certMakeType();
    certMakeUser(SystemUser::ROLE_STUDENT);

    $this->getJson("/api/v1/certifications/{$cert->certificate_type_id}")
         ->assertOk()
         ->assertJsonPath('certificate_type_id', $cert->certificate_type_id);
});

// ═════════════════════════════════════════════════════════════════════════════
// store() — role:3 (admin) only, via StoreCertificationTypeRequest
// ═════════════════════════════════════════════════════════════════════════════

test('student cannot create a certification type', function () {
    certSeedAccessType();
    certMakeUser(SystemUser::ROLE_STUDENT);

    $this->postJson('/api/v1/certifications', [
        'certificate_name' => 'Certificate of Good Moral Character',
        'access_id'        => 1,
    ])->assertStatus(403);
});

test('admin can create a certification type with valid data', function () {
    certSeedAccessType();
    certMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson('/api/v1/certifications', [
        'certificate_name'           => 'Certificate of Good Moral Character',
        'certificate_requirements'   => 'None',
        'certificate_process_period' => '1-2 business days',
        'access_id'                  => 1,
    ])->assertCreated()
      ->assertJsonPath('certificate_name', 'Certificate of Good Moral Character');
});

test('store fails validation when certificate_name is missing', function () {
    certMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson('/api/v1/certifications', [
        'access_id' => 1,
    ])->assertStatus(422)
      ->assertJsonValidationErrors(['certificate_name']);
});

test('store fails validation when certificate_name exceeds max length', function () {
    certMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson('/api/v1/certifications', [
        'certificate_name' => str_repeat('a', 256),
        'access_id'        => 1,
    ])->assertStatus(422)
      ->assertJsonValidationErrors(['certificate_name']);
});

// ═════════════════════════════════════════════════════════════════════════════
// update() — role:3 (admin) only, via UpdateCertificationTypeRequest
// ═════════════════════════════════════════════════════════════════════════════

test('student cannot update a certification type', function () {
    $cert = certMakeType();
    certMakeUser(SystemUser::ROLE_STUDENT);

    $this->putJson("/api/v1/certifications/{$cert->certificate_type_id}", [
        'certificate_name' => 'Renamed',
    ])->assertStatus(403);
});

test('admin can partially update a certification type', function () {
    $cert = certMakeType();
    certMakeUser(SystemUser::ROLE_ADMIN);

    $this->putJson("/api/v1/certifications/{$cert->certificate_type_id}", [
        'certificate_name' => 'Updated Name',
    ])->assertOk()
      ->assertJsonPath('certificate_name', 'Updated Name');
});

test('update returns 404 for a missing certification type', function () {
    certMakeUser(SystemUser::ROLE_ADMIN);

    $this->putJson('/api/v1/certifications/999', [
        'certificate_name' => 'Whatever',
    ])->assertStatus(404);
});

test('update fails validation when certificate_name is too long', function () {
    $cert = certMakeType();
    certMakeUser(SystemUser::ROLE_ADMIN);

    $this->putJson("/api/v1/certifications/{$cert->certificate_type_id}", [
        'certificate_name' => str_repeat('a', 256),
    ])->assertStatus(422)
      ->assertJsonValidationErrors(['certificate_name']);
});

// ═════════════════════════════════════════════════════════════════════════════
// destroy() — no FormRequest involved (no request body), left as-is
// ═════════════════════════════════════════════════════════════════════════════

test('destroy returns 404 for a missing certification type', function () {
    certMakeUser(SystemUser::ROLE_ADMIN);

    $this->deleteJson('/api/v1/certifications/999')
         ->assertStatus(404);
});

test('admin can delete an unreferenced certification type', function () {
    $cert = certMakeType();
    certMakeUser(SystemUser::ROLE_ADMIN);

    $this->deleteJson("/api/v1/certifications/{$cert->certificate_type_id}")
         ->assertOk()
         ->assertJson(['message' => 'Certification type deleted']);

    $this->assertDatabaseMissing('certificate_type', ['certificate_type_id' => $cert->certificate_type_id]);
});

// NOTE: destroy()'s 409 "referenced by existing document requests" branch
// (QueryException code 23000) relies on the DB enforcing the
// request_certificate → certificate_type foreign key. The test suite runs
// on SQLite in-memory (see phpunit.xml) with foreign_keys off by default,
// so that branch isn't exercised here — it needs a MySQL-backed test run
// (or `PRAGMA foreign_keys = ON` wired into the SQLite connection) to
// verify for real.

// ═════════════════════════════════════════════════════════════════════════════
// archive() / restore() — role:3 (admin) only, via ArchiveCertificationTypeRequest
// ═════════════════════════════════════════════════════════════════════════════

test('archive fails validation when reason exceeds max length', function () {
    $cert = certMakeType();
    certMakeUser(SystemUser::ROLE_ADMIN);

    $this->patchJson("/api/v1/certifications/{$cert->certificate_type_id}/archive", [
        'reason' => str_repeat('a', 501),
    ])->assertStatus(422)
      ->assertJsonValidationErrors(['reason']);
});

test('admin can archive a certification type with no active requests', function () {
    $cert = certMakeType();
    $admin = certMakeUser(SystemUser::ROLE_ADMIN);

    $this->patchJson("/api/v1/certifications/{$cert->certificate_type_id}/archive", [
        'reason' => 'No longer offered',
    ])->assertOk()
      ->assertJsonPath('is_archived', true);

    $this->assertDatabaseHas('audit_logs', [
        'action'  => \App\Models\AuditLog::ACTION_CERTIFICATE_TYPE_ARCHIVED,
        'user_id' => $admin->user_id,
    ]);
});

test('archiving is blocked while an active request still uses the certification type', function () {
    certSeedStatuses();
    $cert = certMakeType();

    $docRequest = DocumentRequest::factory()->create(['status_id' => 1]); // Processing
    RequestCertificate::create([
        'request_id'           => $docRequest->request_id,
        'certificate_type_id'  => $cert->certificate_type_id,
        'number_of_copies'     => 1,
    ]);

    certMakeUser(SystemUser::ROLE_ADMIN);

    $this->patchJson("/api/v1/certifications/{$cert->certificate_type_id}/archive", [])
         ->assertStatus(422)
         ->assertJsonPath('active_requests', 1);

    $this->assertDatabaseHas('certificate_type', [
        'certificate_type_id' => $cert->certificate_type_id,
        'is_archived'         => false,
    ]);
});

test('archiving an already-archived certification type is a no-op that returns 200', function () {
    $cert = certMakeType(['is_archived' => true, 'archived_on' => now(), 'archived_by' => null]);
    certMakeUser(SystemUser::ROLE_ADMIN);

    $this->patchJson("/api/v1/certifications/{$cert->certificate_type_id}/archive", [])
         ->assertOk()
         ->assertJsonPath('is_archived', true);
});

test('admin can restore an archived certification type', function () {
    $cert = certMakeType(['is_archived' => true, 'archived_on' => now(), 'archived_by' => null]);
    certMakeUser(SystemUser::ROLE_ADMIN);

    $this->patchJson("/api/v1/certifications/{$cert->certificate_type_id}/restore")
         ->assertOk()
         ->assertJsonPath('is_archived', false);
});

// ═════════════════════════════════════════════════════════════════════════════
// updateLayout() — role:3 (admin) only, via UpdateCertificationLayoutRequest
// ═════════════════════════════════════════════════════════════════════════════

test('admin can update the layout of a non-archived certification type', function () {
    $cert = certMakeType();
    certMakeUser(SystemUser::ROLE_ADMIN);

    $this->putJson("/api/v1/certifications/{$cert->certificate_type_id}/layout", [
        'layout_header_logo_size' => 64,
        'layout_footer_urls'      => null,
    ])->assertOk()
      ->assertJsonPath('message', 'Certification layout updated successfully');

    $this->assertDatabaseHas('certificate_type', [
        'certificate_type_id'     => $cert->certificate_type_id,
        'layout_header_logo_size' => 64,
    ]);
});

test('updateLayout is locked with 423 when the certification type is archived', function () {
    $cert = certMakeType(['is_archived' => true, 'archived_on' => now(), 'archived_by' => null]);
    certMakeUser(SystemUser::ROLE_ADMIN);

    $this->putJson("/api/v1/certifications/{$cert->certificate_type_id}/layout", [
        'layout_header_logo_size' => 64,
    ])->assertStatus(423);
});

test('updateLayout fails validation when logo size is out of range', function () {
    $cert = certMakeType();
    certMakeUser(SystemUser::ROLE_ADMIN);

    $this->putJson("/api/v1/certifications/{$cert->certificate_type_id}/layout", [
        'layout_header_logo_size' => 10, // below min:24
    ])->assertStatus(422)
      ->assertJsonValidationErrors(['layout_header_logo_size']);
});

// ═════════════════════════════════════════════════════════════════════════════
// uploadLayoutLogo() — role:3 (admin) only, via UploadCertificationLayoutLogoRequest
// ═════════════════════════════════════════════════════════════════════════════

test('admin can upload a layout logo for a non-archived certification type', function () {
    Storage::fake(config('filesystems.default', 'public'));

    $cert = certMakeType();
    certMakeUser(SystemUser::ROLE_ADMIN);

    $file = UploadedFile::fake()->image('logo.png');

    $this->post("/api/v1/certifications/{$cert->certificate_type_id}/layout/logo", [
        'logo' => $file,
        'slot' => 'header_left',
    ])->assertCreated()
      ->assertJsonPath('data.slot', 'header_left');

    $this->assertDatabaseHas('certificate_type', [
        'certificate_type_id' => $cert->certificate_type_id,
    ]);
});

test('uploadLayoutLogo is locked with 423 when the certification type is archived', function () {
    Storage::fake(config('filesystems.default', 'public'));

    $cert = certMakeType(['is_archived' => true, 'archived_on' => now(), 'archived_by' => null]);
    certMakeUser(SystemUser::ROLE_ADMIN);

    $this->post("/api/v1/certifications/{$cert->certificate_type_id}/layout/logo", [
        'logo' => UploadedFile::fake()->image('logo.png'),
    ])->assertStatus(423);
});

test('uploadLayoutLogo fails validation when logo is missing', function () {
    $cert = certMakeType();
    certMakeUser(SystemUser::ROLE_ADMIN);

    $this->post("/api/v1/certifications/{$cert->certificate_type_id}/layout/logo", [
        'slot' => 'header_left',
    ])->assertStatus(422)
      ->assertJsonValidationErrors(['logo']);
});

test('uploadLayoutLogo fails validation when logo is not an image', function () {
    $cert = certMakeType();
    certMakeUser(SystemUser::ROLE_ADMIN);

    $this->post("/api/v1/certifications/{$cert->certificate_type_id}/layout/logo", [
        'logo' => UploadedFile::fake()->create('document.pdf', 100),
    ])->assertStatus(422)
      ->assertJsonValidationErrors(['logo']);
});