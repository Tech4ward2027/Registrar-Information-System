<?php

use App\Enums\RequestStatusEnum;
use App\Models\AccessType;
use App\Models\AuditLog;
use App\Models\CertificationType;
use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Models\Policy;
use App\Models\RequestCertificate;
use App\Models\RequestDocument;
use App\Models\RequestReleaseGroup;
use App\Models\SystemUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// ═════════════════════════════════════════════════════════════════════════════
// bulk-ready / bulk-done — Multi-Item / Mixed-Status Batch rules.
//
// Covers RequestItemStatusService::bulkAdvanceItems() end-to-end through the
// real HTTP endpoints (POST /document-requests/bulk-ready and /bulk-done),
// same "verify via a real API call, not the service in isolation" standard
// GranularDashboardPermissionsTest already holds the single-item path to.
// ═════════════════════════════════════════════════════════════════════════════

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * An admin with full dashboard access (View, Process, Complete) — the
 * "normally-provisioned admin" baseline, same shape as
 * grantFullDashboardAccess() in tests/Pest.php, built locally so this file
 * doesn't depend on role_id === ROLE_ADMIN inference living elsewhere.
 */
function bulkMakeAdmin(): SystemUser
{
    $policy = Policy::firstOrCreate(
        ['name' => 'Test Bulk Full Dashboard Access'],
        [
            'permissions' => ['dashboard' => ['View', 'Process', 'Complete']],
            'is_system'   => false,
        ]
    );

    $admin = SystemUser::factory()->create([
        'role_id'   => SystemUser::ROLE_ADMIN,
        'status'    => 'Activated',
        'policy_id' => $policy->policy_id,
    ]);

    Sanctum::actingAs($admin);

    return $admin;
}

/**
 * An admin whose policy grants View + Complete but NOT Process — used to
 * confirm bulk-ready/bulk-done still run their fine-grained
 * Process-vs-Complete check via authorizeItemStatusChange(), not just the
 * coarse route-level module:dashboard,Process|Complete gate (which a
 * Complete-only admin passes, since the route accepts either action).
 */
function bulkMakeCompleteOnlyAdmin(): SystemUser
{
    $policy = Policy::firstOrCreate(
        ['name' => 'Test Bulk Complete-Only Admin'],
        [
            'permissions' => ['dashboard' => ['View', 'Complete']],
            'is_system'   => false,
        ]
    );

    $admin = SystemUser::factory()->create([
        'role_id'   => SystemUser::ROLE_ADMIN,
        'status'    => 'Activated',
        'policy_id' => $policy->policy_id,
    ]);

    Sanctum::actingAs($admin);

    return $admin;
}

function bulkMakeDocType(array $overrides = []): DocumentType
{
    return DocumentType::create(array_merge([
        'document_name'           => 'Transcript of Records',
        'document_description'    => 'Official academic transcript',
        'document_requirements'   => 'Valid ID',
        'document_process_period' => '3-5 business days',
        'access_id'               => AccessType::firstOrCreate(
            ['access_id' => 1],
            ['access_name' => 'Student']
        )->access_id,
    ], $overrides));
}

function bulkMakeCertType(array $overrides = []): CertificationType
{
    return CertificationType::create(array_merge([
        'certificate_name'            => 'Certificate of Good Moral Character',
        'certificate_requirements'    => 'Valid ID',
        'certificate_process_period'  => '3-5 business days',
        'access_id'                   => AccessType::firstOrCreate(
            ['access_id' => 1],
            ['access_name' => 'Student']
        )->access_id,
    ], $overrides));
}

/** Creates a DocumentRequest with exactly one request_document row at $statusId. */
function bulkMakeRequestWithDocument(int $statusId): DocumentRequest
{
    $docRequest = DocumentRequest::factory()->create(['status_id' => $statusId]);
    $docType    = bulkMakeDocType();

    RequestDocument::create([
        'request_id'       => $docRequest->request_id,
        'document_type_id' => $docType->document_type_id,
        'number_of_copies' => 1,
        'status_id'        => $statusId,
    ]);

    return $docRequest;
}

/** Creates a DocumentRequest with exactly one request_certificate row at $statusId. */
function bulkMakeRequestWithCertificate(int $statusId, ?string $generatedAt = null): DocumentRequest
{
    $docRequest = DocumentRequest::factory()->create(['status_id' => $statusId]);
    $certType   = bulkMakeCertType();

    RequestCertificate::create([
        'request_id'          => $docRequest->request_id,
        'certificate_type_id' => $certType->certificate_type_id,
        'number_of_copies'    => 1,
        'status_id'           => $statusId,
        'generated_at'        => $generatedAt,
    ]);

    return $docRequest;
}

// ═════════════════════════════════════════════════════════════════════════════
// Authorization
// ═════════════════════════════════════════════════════════════════════════════

test('student cannot call bulk-ready', function () {
    $docRequest = bulkMakeRequestWithDocument(RequestStatusEnum::Processing->value);
    // Explicit variable rather than a later re-query by role_id — the
    // request's own owner (created inside DocumentRequest::factory()) is
    // ALSO a ROLE_STUDENT SystemUser by default (see SystemUserFactory),
    // so querying "any student" back out would be non-deterministic
    // about which row actually gets authenticated.
    $student = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT, 'status' => 'Activated']);
    Sanctum::actingAs($student);

    $this->postJson('/api/v1/document-requests/bulk-ready', ['request_ids' => [$docRequest->request_id]])
         ->assertStatus(403);
});

test('student cannot call bulk-done', function () {
    $docRequest = bulkMakeRequestWithDocument(RequestStatusEnum::ReadyToClaim->value);
    $student = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT, 'status' => 'Activated']);
    Sanctum::actingAs($student);

    $this->postJson('/api/v1/document-requests/bulk-done', ['request_ids' => [$docRequest->request_id]])
         ->assertStatus(403);
});

test('an admin with no dashboard access at all gets 403 from bulk-ready', function () {
    $docRequest = bulkMakeRequestWithDocument(RequestStatusEnum::Processing->value);
    // No policy_id attached — falls back to the zero-access default policy.
    $admin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN, 'status' => 'Activated']);
    Sanctum::actingAs($admin);

    $this->postJson('/api/v1/document-requests/bulk-ready', ['request_ids' => [$docRequest->request_id]])
         ->assertStatus(403);
});

test('a Complete-only admin (no Process) is rejected by the fine-grained gate on bulk-ready', function () {
    $docRequest = bulkMakeRequestWithDocument(RequestStatusEnum::Processing->value);
    bulkMakeCompleteOnlyAdmin();

    // Passes the coarse route middleware (module:dashboard,Process|Complete
    // accepts either action) but bulkAdvanceItems() -> authorizeItemStatusChange()
    // requires 'Process' specifically for a ReadyToClaim target.
    $this->postJson('/api/v1/document-requests/bulk-ready', ['request_ids' => [$docRequest->request_id]])
         ->assertStatus(403);
});

test('a Complete-only admin can call bulk-done, which requires Complete not Process', function () {
    $docRequest = bulkMakeRequestWithDocument(RequestStatusEnum::ReadyToClaim->value);
    bulkMakeCompleteOnlyAdmin();

    $this->postJson('/api/v1/document-requests/bulk-done', ['request_ids' => [$docRequest->request_id]])
         ->assertOk();

    $this->assertDatabaseHas('request_document', [
        'request_id' => $docRequest->request_id,
        'status_id'  => RequestStatusEnum::Completed->value,
    ]);
});

// ═════════════════════════════════════════════════════════════════════════════
// Validation
// ═════════════════════════════════════════════════════════════════════════════

test('bulk-ready fails validation on an empty request_ids array', function () {
    bulkMakeAdmin();

    $this->postJson('/api/v1/document-requests/bulk-ready', ['request_ids' => []])
         ->assertStatus(422)
         ->assertJsonValidationErrors(['request_ids']);
});

test('bulk-ready fails validation on duplicate ids', function () {
    bulkMakeAdmin();

    $this->postJson('/api/v1/document-requests/bulk-ready', ['request_ids' => [1, 1]])
         ->assertStatus(422)
         ->assertJsonValidationErrors(['request_ids.0']);
});

test('bulk-ready fails validation past the 200-id cap', function () {
    bulkMakeAdmin();

    $this->postJson('/api/v1/document-requests/bulk-ready', [
        'request_ids' => range(1, 201),
    ])->assertStatus(422)
      ->assertJsonValidationErrors(['request_ids']);
});

// ═════════════════════════════════════════════════════════════════════════════
// Bulk Ready — Multi-Item / Mixed-Status Batch rules
// ═════════════════════════════════════════════════════════════════════════════

test('bulk-ready advances an eligible Processing document item to ReadyToClaim', function () {
    $docRequest = bulkMakeRequestWithDocument(RequestStatusEnum::Processing->value);
    $admin      = bulkMakeAdmin();

    $response = $this->postJson('/api/v1/document-requests/bulk-ready', [
        'request_ids' => [$docRequest->request_id],
    ])->assertOk();

    $response->assertJsonPath('target_status', 'ReadyToClaim');
    expect($response->json('items_updated'))->toHaveCount(1);
    expect($response->json('items_skipped'))->toBeEmpty();
    expect($response->json('requests_processed'))->toEqual([$docRequest->request_id]);
    expect($response->json('requests_status_changed'))->toEqual([$docRequest->request_id]);

    $this->assertDatabaseHas('request_document', [
        'request_id' => $docRequest->request_id,
        'status_id'  => RequestStatusEnum::ReadyToClaim->value,
    ]);
    $this->assertDatabaseHas('document_request', [
        'request_id' => $docRequest->request_id,
        'status_id'  => RequestStatusEnum::ReadyToClaim->value,
    ]);

    // Aggregate-status changes are audited once per request, mirroring
    // archive-bulk/restore-bulk's "one entry per request actually affected"
    // convention — see DocumentRequestController::bulkReadyItems().
    $this->assertDatabaseHas('audit_logs', [
        'action'  => AuditLog::ACTION_REQUEST_STATUS_CHANGED,
        'user_id' => $admin->user_id,
    ]);
});

test('bulk-ready is a no-op on an already-ReadyToClaim item — reported skipped, not re-updated', function () {
    $docRequest = bulkMakeRequestWithDocument(RequestStatusEnum::ReadyToClaim->value);
    bulkMakeAdmin();

    $response = $this->postJson('/api/v1/document-requests/bulk-ready', [
        'request_ids' => [$docRequest->request_id],
    ])->assertOk();

    expect($response->json('items_updated'))->toBeEmpty();
    expect($response->json('items_skipped'))->toHaveCount(1);
    expect($response->json('items_skipped.0.reason'))->toBe('invalid_transition');
    expect($response->json('requests_status_changed'))->toBeEmpty();
    expect($response->json('requests_skipped.0.reason'))->toBe('no_eligible_items');
});

test('bulk-ready processes a mixed batch: eligible items update, ineligible items are skipped without blocking the rest', function () {
    $eligible   = bulkMakeRequestWithDocument(RequestStatusEnum::Processing->value);
    $ineligible = bulkMakeRequestWithDocument(RequestStatusEnum::ReadyToClaim->value); // already there
    $admin      = bulkMakeAdmin();

    $response = $this->postJson('/api/v1/document-requests/bulk-ready', [
        'request_ids' => [$eligible->request_id, $ineligible->request_id],
    ])->assertOk();

    expect($response->json('items_updated'))->toHaveCount(1);
    expect($response->json('items_updated.0.request_id'))->toBe($eligible->request_id);
    expect($response->json('items_skipped'))->toHaveCount(1);
    expect($response->json('items_skipped.0.request_id'))->toBe($ineligible->request_id);

    $this->assertDatabaseHas('document_request', [
        'request_id' => $eligible->request_id,
        'status_id'  => RequestStatusEnum::ReadyToClaim->value,
    ]);
    // Untouched — still whatever it started at.
    $this->assertDatabaseHas('document_request', [
        'request_id' => $ineligible->request_id,
        'status_id'  => RequestStatusEnum::ReadyToClaim->value,
    ]);
});

test('bulk-ready advances only the eligible items on a request that mixes an eligible and an ineligible line item, then rolls the parent up correctly', function () {
    // One request, two documents: one Processing (eligible), one already
    // ReadyToClaim (ineligible) — this is the Multi-Item/Child Request Rule
    // itself: per-item eligibility within a single request, not just across
    // a batch of requests.
    $docRequest = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::Processing->value]);

    // Two distinct document types: request_document enforces a real
    // unique constraint on (request_id, document_type_id)
    // ("rd_request_doctype_unique", see
    // 2026_07_03_000000_fix_schema_issues.php), so two line items on the
    // same request can never share a document type — each must be its
    // own type to model "two different documents on one request".
    $processingDocType = bulkMakeDocType();
    $readyDocType       = bulkMakeDocType(['document_name' => 'Certificate of Enrollment']);

    $processingItem = RequestDocument::create([
        'request_id'       => $docRequest->request_id,
        'document_type_id' => $processingDocType->document_type_id,
        'number_of_copies' => 1,
        'status_id'        => RequestStatusEnum::Processing->value,
    ]);
    $readyItem = RequestDocument::create([
        'request_id'       => $docRequest->request_id,
        'document_type_id' => $readyDocType->document_type_id,
        'number_of_copies' => 1,
        'status_id'        => RequestStatusEnum::ReadyToClaim->value,
    ]);

    bulkMakeAdmin();

    $response = $this->postJson('/api/v1/document-requests/bulk-ready', [
        'request_ids' => [$docRequest->request_id],
    ])->assertOk();

    expect($response->json('items_updated'))->toHaveCount(1);
    expect($response->json('items_updated.0.id'))->toBe($processingItem->request_document_id);
    expect($response->json('items_skipped'))->toHaveCount(1);
    expect($response->json('items_skipped.0.id'))->toBe($readyItem->request_document_id);

    $this->assertDatabaseHas('request_document', [
        'request_document_id' => $processingItem->request_document_id,
        'status_id'            => RequestStatusEnum::ReadyToClaim->value,
    ]);

    // Earliest-stage-wins: both items are now ReadyToClaim, so the parent
    // rolls up to ReadyToClaim too.
    $this->assertDatabaseHas('document_request', [
        'request_id' => $docRequest->request_id,
        'status_id'  => RequestStatusEnum::ReadyToClaim->value,
    ]);
});

test('bulk-ready also advances an eligible PendingSignature certificate item', function () {
    $docRequest = bulkMakeRequestWithCertificate(RequestStatusEnum::PendingSignature->value);
    bulkMakeAdmin();

    $response = $this->postJson('/api/v1/document-requests/bulk-ready', [
        'request_ids' => [$docRequest->request_id],
    ])->assertOk();

    expect($response->json('items_updated'))->toHaveCount(1);
    expect($response->json('items_updated.0.type'))->toBe('certificate');

    $this->assertDatabaseHas('request_certificate', [
        'request_id' => $docRequest->request_id,
        'status_id'  => RequestStatusEnum::ReadyToClaim->value,
    ]);
});

test('bulk-ready does not enforce certificate_not_generated today — the guard is intentionally disabled', function () {
    // Documents the CURRENT, live business decision (see
    // RequestItemStatusService::certificateGeneratedIneligibilityReason()):
    // an ungenerated certificate is still eligible for Bulk Ready right
    // now. This test is meant to start failing the day that guard is
    // re-enabled — at which point it should be rewritten to assert the
    // opposite (skipped with reason 'certificate_not_generated').
    $docRequest = bulkMakeRequestWithCertificate(RequestStatusEnum::Processing->value, generatedAt: null);
    bulkMakeAdmin();

    $response = $this->postJson('/api/v1/document-requests/bulk-ready', [
        'request_ids' => [$docRequest->request_id],
    ])->assertOk();

    expect($response->json('items_updated'))->toHaveCount(1);
    expect($response->json('items_skipped'))->toBeEmpty();
});

test('bulk-ready skips an archived request entirely and reports it', function () {
    $docRequest = bulkMakeRequestWithDocument(RequestStatusEnum::Processing->value);
    $docRequest->update(['is_archived' => true, 'archived_on' => now()]);
    bulkMakeAdmin();

    $response = $this->postJson('/api/v1/document-requests/bulk-ready', [
        'request_ids' => [$docRequest->request_id],
    ])->assertOk();

    expect($response->json('items_updated'))->toBeEmpty();
    expect($response->json('requests_skipped'))->toEqual([
        ['request_id' => $docRequest->request_id, 'reason' => 'archived'],
    ]);

    $this->assertDatabaseHas('request_document', [
        'request_id' => $docRequest->request_id,
        'status_id'  => RequestStatusEnum::Processing->value, // untouched
    ]);
});

test('bulk-ready reports a nonexistent request id as not_found without failing the rest of the batch', function () {
    $eligible = bulkMakeRequestWithDocument(RequestStatusEnum::Processing->value);
    bulkMakeAdmin();

    $response = $this->postJson('/api/v1/document-requests/bulk-ready', [
        'request_ids' => [$eligible->request_id, 999999],
    ])->assertOk();

    expect($response->json('items_updated'))->toHaveCount(1);
    expect($response->json('requests_skipped'))->toEqual([
        ['request_id' => 999999, 'reason' => 'not_found'],
    ]);
});

// ═════════════════════════════════════════════════════════════════════════════
// Bulk Done — only ReadyToClaim items are eligible
// ═════════════════════════════════════════════════════════════════════════════

test('bulk-done advances an eligible ReadyToClaim item to Completed', function () {
    $docRequest = bulkMakeRequestWithDocument(RequestStatusEnum::ReadyToClaim->value);
    bulkMakeAdmin();

    $response = $this->postJson('/api/v1/document-requests/bulk-done', [
        'request_ids' => [$docRequest->request_id],
    ])->assertOk();

    expect($response->json('items_updated'))->toHaveCount(1);
    $this->assertDatabaseHas('document_request', [
        'request_id' => $docRequest->request_id,
        'status_id'  => RequestStatusEnum::Completed->value,
    ]);
});

test('bulk-done skips a Processing item — only ReadyToClaim is eligible for Completed', function () {
    $docRequest = bulkMakeRequestWithDocument(RequestStatusEnum::Processing->value);
    bulkMakeAdmin();

    $response = $this->postJson('/api/v1/document-requests/bulk-done', [
        'request_ids' => [$docRequest->request_id],
    ])->assertOk();

    expect($response->json('items_updated'))->toBeEmpty();
    expect($response->json('items_skipped.0.reason'))->toBe('invalid_transition');
    expect($response->json('items_skipped.0.current_status'))->toBe('Processing');

    $this->assertDatabaseHas('request_document', [
        'request_id' => $docRequest->request_id,
        'status_id'  => RequestStatusEnum::Processing->value, // untouched
    ]);
});

test('bulk-done processes a mixed batch of Ready and not-yet-Ready requests independently', function () {
    $ready    = bulkMakeRequestWithDocument(RequestStatusEnum::ReadyToClaim->value);
    $notReady = bulkMakeRequestWithDocument(RequestStatusEnum::PendingSignature->value);
    bulkMakeAdmin();

    $response = $this->postJson('/api/v1/document-requests/bulk-done', [
        'request_ids' => [$ready->request_id, $notReady->request_id],
    ])->assertOk();

    expect($response->json('items_updated'))->toHaveCount(1);
    expect($response->json('items_updated.0.request_id'))->toBe($ready->request_id);

    $this->assertDatabaseHas('document_request', [
        'request_id' => $ready->request_id,
        'status_id'  => RequestStatusEnum::Completed->value,
    ]);
    $this->assertDatabaseHas('document_request', [
        'request_id' => $notReady->request_id,
        'status_id'  => RequestStatusEnum::PendingSignature->value, // untouched
    ]);
});

// ═════════════════════════════════════════════════════════════════════════════
// Release group rollup — recomputeReleaseGroupAggregate()
// ═════════════════════════════════════════════════════════════════════════════

test('bulk-ready rolls up a release group\'s own status_id as its member items advance', function () {
    $docRequest = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::Processing->value]);
    $docType    = bulkMakeDocType();

    $group = RequestReleaseGroup::create([
        'request_id' => $docRequest->request_id,
        'status_id'  => RequestStatusEnum::Processing->value,
    ]);

    RequestDocument::create([
        'request_id'                => $docRequest->request_id,
        'document_type_id'          => $docType->document_type_id,
        'number_of_copies'          => 1,
        'status_id'                 => RequestStatusEnum::Processing->value,
        'request_release_group_id'  => $group->request_release_group_id,
    ]);

    bulkMakeAdmin();

    $this->postJson('/api/v1/document-requests/bulk-ready', [
        'request_ids' => [$docRequest->request_id],
    ])->assertOk();

    $this->assertDatabaseHas('request_release_group', [
        'request_release_group_id' => $group->request_release_group_id,
        'status_id'                => RequestStatusEnum::ReadyToClaim->value,
    ]);
});