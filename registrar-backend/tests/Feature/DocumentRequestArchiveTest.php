<?php

use App\Enums\RequestStatusEnum;
use App\Models\AuditLog;
use App\Models\DocumentRequest;
use App\Models\RequestStatus;
use App\Models\SystemUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// ── Helpers ───────────────────────────────────────────────────────────────────
// Mirrors makeUser()/seedReferenceData() from RisFeatureTest.php.

function drMakeUser(int $roleId): SystemUser
{
    $user = SystemUser::factory()->create(['role_id' => $roleId, 'status' => 'Activated']);

    // See tests/Pest.php::grantFullDashboardAccess() — show() is gated by
    // module:dashboard,View, and a plain admin has zero dashboard access
    // without an attached policy since Work Item #1.
    grantFullDashboardAccess($user);

    Sanctum::actingAs($user);
    return $user;
}

function drSeedStatuses(): void
{
    foreach ([1 => 'Processing', 2 => 'Ready to Claim', 3 => 'Completed', 4 => 'Forfeited'] as $id => $name) {
        RequestStatus::firstOrCreate(['status_id' => $id], ['status_name' => $name]);
    }
}

// ═════════════════════════════════════════════════════════════════════════════
// destroy() — role:3 + DocumentRequestPolicy::delete
// ═════════════════════════════════════════════════════════════════════════════

test('student cannot delete a document request', function () {
    drSeedStatuses();
    $owner  = drMakeUser(SystemUser::ROLE_STUDENT);
    $docReq = DocumentRequest::factory()->create(['user_id' => $owner->user_id]);

    $this->deleteJson("/api/v1/document-requests/{$docReq->request_id}")
         ->assertStatus(403);
});

test('admin can delete a document request', function () {
    drSeedStatuses();
    $docReq = DocumentRequest::factory()->create();
    drMakeUser(SystemUser::ROLE_ADMIN);

    $this->deleteJson("/api/v1/document-requests/{$docReq->request_id}")
         ->assertOk()
         ->assertJson(['message' => 'Request deleted successfully']);

    // destroy() is a soft delete (see DocumentRequestController::destroy):
    // request_document/request_history carry RESTRICT (not CASCADE) foreign
    // keys back to document_request, so a real force-delete would fail on
    // any request that has at least one associated document/history row.
    // The row therefore still exists with deleted_at stamped, and is
    // excluded from normal queries by the model's SoftDeletes global scope.
    $this->assertSoftDeleted('document_request', ['request_id' => $docReq->request_id]);
});

// NOTE: destroy()'s 409 FK-violation branch isn't exercised here — same
// SQLite foreign-key limitation noted in the other new test files.

// ═════════════════════════════════════════════════════════════════════════════
// archive() / restore() — role:3 + DocumentRequestPolicy::archive/restore
// ═════════════════════════════════════════════════════════════════════════════

test('student cannot archive a document request', function () {
    drSeedStatuses();
    $owner  = drMakeUser(SystemUser::ROLE_STUDENT);
    $docReq = DocumentRequest::factory()->create(['user_id' => $owner->user_id]);

    $this->patchJson("/api/v1/document-requests/{$docReq->request_id}/archive")
         ->assertStatus(403);
});

test('admin can archive a document request regardless of status', function () {
    drSeedStatuses();
    $docReq = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::Processing->value]);
    $admin  = drMakeUser(SystemUser::ROLE_ADMIN);

    $this->patchJson("/api/v1/document-requests/{$docReq->request_id}/archive")
         ->assertOk()
         ->assertJsonPath('is_archived', true);

    $this->assertDatabaseHas('audit_logs', [
        'action'  => AuditLog::ACTION_REQUEST_ARCHIVED,
        'user_id' => $admin->user_id,
    ]);
});

test('archiving an already-archived request is idempotent', function () {
    drSeedStatuses();
    $docReq = DocumentRequest::factory()->create([
        'is_archived' => true,
        'archived_on' => now(),
        'archived_by' => null,
    ]);
    drMakeUser(SystemUser::ROLE_ADMIN);

    $this->patchJson("/api/v1/document-requests/{$docReq->request_id}/archive")
         ->assertOk()
         ->assertJsonPath('is_archived', true);
});

test('archive returns 404 for a missing request', function () {
    drMakeUser(SystemUser::ROLE_ADMIN);

    $this->patchJson('/api/v1/document-requests/999999/archive')
         ->assertStatus(404);
});

test('admin can restore an archived request', function () {
    drSeedStatuses();
    $docReq = DocumentRequest::factory()->create([
        'is_archived' => true,
        'archived_on' => now(),
        'archived_by' => null,
    ]);
    $admin = drMakeUser(SystemUser::ROLE_ADMIN);

    $this->patchJson("/api/v1/document-requests/{$docReq->request_id}/restore")
         ->assertOk()
         ->assertJsonPath('is_archived', false);

    $this->assertDatabaseHas('audit_logs', [
        'action'  => AuditLog::ACTION_REQUEST_RESTORED,
        'user_id' => $admin->user_id,
    ]);
});

test('archived requests remain viewable via show (read-only)', function () {
    drSeedStatuses();
    $docReq = DocumentRequest::factory()->create([
        'is_archived' => true,
        'archived_on' => now(),
        'archived_by' => null,
    ]);
    drMakeUser(SystemUser::ROLE_ADMIN);

    // If show() used implicit route-model binding instead of
    // DocumentRequest::withArchived()->findOrFail(), ExcludeArchivedScope
    // would 404 this — that's the specific regression this test guards.
    $this->getJson("/api/v1/document-requests/{$docReq->request_id}")
         ->assertOk()
         ->assertJsonPath('request_id', $docReq->request_id);
});

// ═════════════════════════════════════════════════════════════════════════════
// archiveBulk() / restoreBulk() — role:3 + BulkRequestIdsRequest
// ═════════════════════════════════════════════════════════════════════════════

test('student cannot call archive-bulk', function () {
    drMakeUser(SystemUser::ROLE_STUDENT);

    $this->postJson('/api/v1/document-requests/archive-bulk', ['request_ids' => [1]])
         ->assertStatus(403);
});

test('archive-bulk fails validation on an empty request_ids array', function () {
    drMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson('/api/v1/document-requests/archive-bulk', ['request_ids' => []])
         ->assertStatus(422)
         ->assertJsonValidationErrors(['request_ids']);
});

test('archive-bulk fails validation on duplicate ids', function () {
    drMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson('/api/v1/document-requests/archive-bulk', ['request_ids' => [1, 1]])
         ->assertStatus(422)
         ->assertJsonValidationErrors(['request_ids.0']);
});

test('archive-bulk archives eligible ids and reports the rest as skipped', function () {
    drSeedStatuses();
    $eligible          = DocumentRequest::factory()->create(); // not archived — eligible
    $alreadyArchived   = DocumentRequest::factory()->create(['is_archived' => true, 'archived_on' => now(), 'archived_by' => null]);
    drMakeUser(SystemUser::ROLE_ADMIN);

    $response = $this->postJson('/api/v1/document-requests/archive-bulk', [
        'request_ids' => [$eligible->request_id, $alreadyArchived->request_id],
    ])->assertOk();

    expect($response->json('archived'))->toEqual([$eligible->request_id]);
    expect($response->json('skipped'))->toEqual([$alreadyArchived->request_id]);

    $this->assertDatabaseHas('document_request', ['request_id' => $eligible->request_id, 'is_archived' => true]);
});

test('restore-bulk restores eligible ids and reports the rest as skipped', function () {
    drSeedStatuses();
    $archived   = DocumentRequest::factory()->create(['is_archived' => true, 'archived_on' => now(), 'archived_by' => null]);
    $notArchived = DocumentRequest::factory()->create(); // not archived — ineligible for restore
    drMakeUser(SystemUser::ROLE_ADMIN);

    $response = $this->postJson('/api/v1/document-requests/restore-bulk', [
        'request_ids' => [$archived->request_id, $notArchived->request_id],
    ])->assertOk();

    expect($response->json('restored'))->toEqual([$archived->request_id]);
    expect($response->json('skipped'))->toEqual([$notArchived->request_id]);

    $this->assertDatabaseHas('document_request', ['request_id' => $archived->request_id, 'is_archived' => false]);
});