<?php

use App\Models\AccessType;
use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Models\RequestDocument;
use App\Models\RequestStatus;
use App\Models\SystemUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// ── Helpers ───────────────────────────────────────────────────────────────────
//
// Mirrors the certMakeUser()/certSeedAccessType() helpers in
// CertificationTypeControllerTest.php so this file follows the same
// conventions as the rest of the suite.

function docTypeMakeUser(int $roleId): SystemUser
{
    $user = SystemUser::factory()->create(['role_id' => $roleId, 'status' => 'Activated']);
    Sanctum::actingAs($user);
    return $user;
}

function docTypeSeedAccessType(): AccessType
{
    return AccessType::firstOrCreate(['access_id' => 1], ['access_name' => 'Student']);
}

function docTypeMakeType(array $overrides = []): DocumentType
{
    $access = docTypeSeedAccessType();

    return DocumentType::create(array_merge([
        'document_name'           => 'Transcript of Records',
        'document_description'    => 'Official academic transcript',
        'document_requirements'   => 'Valid ID',
        'document_process_period' => '3-5 business days',
        'access_id'               => $access->access_id,
    ], $overrides));
}

function docTypeSeedStatuses(): void
{
    foreach ([1 => 'Processing', 2 => 'Ready to Claim', 3 => 'Completed', 4 => 'Forfeited'] as $id => $name) {
        RequestStatus::firstOrCreate(['status_id' => $id], ['status_name' => $name]);
    }
}

// ═════════════════════════════════════════════════════════════════════════════
// Read routes — open to all authenticated roles (no role:N middleware)
// ═════════════════════════════════════════════════════════════════════════════

test('any authenticated role can list document types', function () {
    // Not assertJsonCount(1): TestCase::$seed = true means DatabaseSeeder's
    // ~14 reference document_type rows are already present in the table
    // before this test's own row is created, so the list is never empty.
    // What this test actually verifies — that the endpoint is reachable by
    // any authenticated role and returns the type we just created — doesn't
    // require (or want) table isolation.
    $type = docTypeMakeType();
    docTypeMakeUser(SystemUser::ROLE_STUDENT);

    $this->getJson('/api/v1/document-types')
         ->assertOk()
         ->assertJsonFragment(['document_type_id' => $type->document_type_id]);
});

test('show returns 404 for a missing document type', function () {
    docTypeMakeUser(SystemUser::ROLE_STUDENT);

    $this->getJson('/api/v1/document-types/999')
         ->assertStatus(404)
         ->assertJson(['message' => 'Document type not found']);
});

test('show returns the document type for a valid id', function () {
    $docType = docTypeMakeType();
    docTypeMakeUser(SystemUser::ROLE_STUDENT);

    $this->getJson("/api/v1/document-types/{$docType->document_type_id}")
         ->assertOk()
         ->assertJsonPath('document_type_id', $docType->document_type_id);
});

// ═════════════════════════════════════════════════════════════════════════════
// store() — role:3 (admin) only, via StoreDocumentTypeRequest
// ═════════════════════════════════════════════════════════════════════════════

test('student cannot create a document type', function () {
    docTypeSeedAccessType();
    docTypeMakeUser(SystemUser::ROLE_STUDENT);

    $this->postJson('/api/v1/document-types', [
        'document_name' => 'Certificate of Good Moral Character',
        'access_id'     => 1,
    ])->assertStatus(403);
});

test('admin can create a document type with valid data', function () {
    docTypeSeedAccessType();
    docTypeMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson('/api/v1/document-types', [
        'document_name'           => 'Certificate of Good Moral Character',
        'document_description'    => 'For employment purposes',
        'document_requirements'   => 'None',
        'document_process_period' => '1-2 business days',
        'access_id'                => 1,
    ])->assertCreated()
      ->assertJsonPath('document_name', 'Certificate of Good Moral Character');
});

test('store fails validation when document_name is missing', function () {
    docTypeMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson('/api/v1/document-types', [
        'access_id' => 1,
    ])->assertStatus(422)
      ->assertJsonValidationErrors(['document_name']);
});

test('store fails validation when document_name exceeds max length', function () {
    docTypeMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson('/api/v1/document-types', [
        'document_name' => str_repeat('a', 101),
        'access_id'     => 1,
    ])->assertStatus(422)
      ->assertJsonValidationErrors(['document_name']);
});

test('store fails validation when access_id does not reference an existing access type', function () {
    docTypeMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson('/api/v1/document-types', [
        'document_name' => 'Certificate of Good Moral Character',
        'access_id'     => 999, // no access_type row with this id
    ])->assertStatus(422)
      ->assertJsonValidationErrors(['access_id']);
});

// ═════════════════════════════════════════════════════════════════════════════
// update() — role:3 (admin) only, via UpdateDocumentTypeRequest
// ═════════════════════════════════════════════════════════════════════════════

test('student cannot update a document type', function () {
    $docType = docTypeMakeType();
    docTypeMakeUser(SystemUser::ROLE_STUDENT);

    $this->putJson("/api/v1/document-types/{$docType->document_type_id}", [
        'document_name' => 'Renamed',
    ])->assertStatus(403);
});

test('admin can partially update a document type', function () {
    $docType = docTypeMakeType();
    docTypeMakeUser(SystemUser::ROLE_ADMIN);

    $this->putJson("/api/v1/document-types/{$docType->document_type_id}", [
        'document_name' => 'Updated Name',
    ])->assertOk()
      ->assertJsonPath('document_name', 'Updated Name');
});

test('update returns 404 for a missing document type', function () {
    docTypeMakeUser(SystemUser::ROLE_ADMIN);

    $this->putJson('/api/v1/document-types/999', [
        'document_name' => 'Whatever',
    ])->assertStatus(404);
});

test('update fails validation when document_name is too long', function () {
    $docType = docTypeMakeType();
    docTypeMakeUser(SystemUser::ROLE_ADMIN);

    $this->putJson("/api/v1/document-types/{$docType->document_type_id}", [
        'document_name' => str_repeat('a', 101),
    ])->assertStatus(422)
      ->assertJsonValidationErrors(['document_name']);
});

test('update fails validation when access_id does not reference an existing access type', function () {
    $docType = docTypeMakeType();
    docTypeMakeUser(SystemUser::ROLE_ADMIN);

    $this->putJson("/api/v1/document-types/{$docType->document_type_id}", [
        'access_id' => 999,
    ])->assertStatus(422)
      ->assertJsonValidationErrors(['access_id']);
});

// ═════════════════════════════════════════════════════════════════════════════
// destroy() — no FormRequest involved (no request body), left as-is
// ═════════════════════════════════════════════════════════════════════════════

test('destroy returns 404 for a missing document type', function () {
    docTypeMakeUser(SystemUser::ROLE_ADMIN);

    $this->deleteJson('/api/v1/document-types/999')
         ->assertStatus(404);
});

test('admin can delete an unreferenced document type', function () {
    $docType = docTypeMakeType();
    docTypeMakeUser(SystemUser::ROLE_ADMIN);

    $this->deleteJson("/api/v1/document-types/{$docType->document_type_id}")
         ->assertOk()
         ->assertJson(['message' => 'Document type deleted']);

    $this->assertDatabaseMissing('document_type', ['document_type_id' => $docType->document_type_id]);
});

// NOTE: destroy()'s 409 "referenced by existing document requests" branch
// (QueryException code 23000) relies on the DB enforcing a
// request_document → document_type foreign key. The test suite runs on
// SQLite in-memory (see phpunit.xml) with foreign_keys off by default, so
// that branch isn't exercised here — same caveat as
// CertificationTypeControllerTest — it needs a MySQL-backed test run (or
// `PRAGMA foreign_keys = ON` wired into the SQLite connection) to verify
// for real.

// ═════════════════════════════════════════════════════════════════════════════
// archive() / restore() — role:3 (admin) only, via ArchiveDocumentTypeRequest
// ═════════════════════════════════════════════════════════════════════════════

test('archive fails validation when reason exceeds max length', function () {
    $docType = docTypeMakeType();
    docTypeMakeUser(SystemUser::ROLE_ADMIN);

    $this->patchJson("/api/v1/document-types/{$docType->document_type_id}/archive", [
        'reason' => str_repeat('a', 501),
    ])->assertStatus(422)
      ->assertJsonValidationErrors(['reason']);
});

test('admin can archive a document type with no active requests', function () {
    $docType = docTypeMakeType();
    $admin = docTypeMakeUser(SystemUser::ROLE_ADMIN);

    $this->patchJson("/api/v1/document-types/{$docType->document_type_id}/archive", [
        'reason' => 'No longer offered',
    ])->assertOk()
      ->assertJsonPath('is_archived', true);

    $this->assertDatabaseHas('audit_logs', [
        'action'  => \App\Models\AuditLog::ACTION_DOCUMENT_TYPE_ARCHIVED,
        'user_id' => $admin->user_id,
    ]);
});

test('archiving is blocked while an active request still uses the document type', function () {
    docTypeSeedStatuses();
    $docType = docTypeMakeType();

    $docRequest = DocumentRequest::factory()->create(['status_id' => 1]); // Processing
    RequestDocument::create([
        'request_id'       => $docRequest->request_id,
        'document_type_id' => $docType->document_type_id,
        'number_of_copies' => 1,
    ]);

    docTypeMakeUser(SystemUser::ROLE_ADMIN);

    $this->patchJson("/api/v1/document-types/{$docType->document_type_id}/archive", [])
         ->assertStatus(422)
         ->assertJsonPath('active_requests', 1);

    $this->assertDatabaseHas('document_type', [
        'document_type_id' => $docType->document_type_id,
        'is_archived'       => false,
    ]);
});

test('archiving an already-archived document type is a no-op that returns 200', function () {
    $docType = docTypeMakeType(['is_archived' => true, 'archived_on' => now(), 'archived_by' => null]);
    docTypeMakeUser(SystemUser::ROLE_ADMIN);

    $this->patchJson("/api/v1/document-types/{$docType->document_type_id}/archive", [])
         ->assertOk()
         ->assertJsonPath('is_archived', true);
});

test('admin can restore an archived document type', function () {
    $docType = docTypeMakeType(['is_archived' => true, 'archived_on' => now(), 'archived_by' => null]);
    docTypeMakeUser(SystemUser::ROLE_ADMIN);

    $this->patchJson("/api/v1/document-types/{$docType->document_type_id}/restore")
         ->assertOk()
         ->assertJsonPath('is_archived', false);
});