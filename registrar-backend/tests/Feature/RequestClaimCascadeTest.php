<?php

use App\Enums\RequestStatusEnum;
use App\Models\AccessType;
use App\Models\CertificationType;
use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Models\RequestCertificate;
use App\Models\RequestDocument;
use App\Models\RequestHistory;
use App\Models\RequestReleaseGroup;
use App\Models\SystemUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// ═════════════════════════════════════════════════════════════════════════════
// Phase 1 regression suite.
//
//  1. A whole-request QR / claim_code scan moves the request's items to
//     Completed in the same transaction (the "admin still has to click Done"
//     bug — RequestStatusCascade).
//  2. A request that has reached a final status can no longer be pulled back
//     out of it by an item-level action (terminal guards in
//     RequestItemStatusService / RequestReleaseGroupService).
//  3. ShredExpiredRequests now goes through the same cascade helper.
//  4. The repair migration fixes rows already left stale, and is idempotent.
//
// Request statuses are seeded by tests/TestCase.php ($seed = true), so no
// per-test status seeding is needed. Helper names carry a "csc" prefix
// because Pest test files share one global function namespace.
// ═════════════════════════════════════════════════════════════════════════════

// ── Helpers ───────────────────────────────────────────────────────────────────

function cscAdmin(): SystemUser
{
    $admin = SystemUser::factory()->create([
        'role_id' => SystemUser::ROLE_ADMIN,
        'status'  => 'Activated',
    ]);

    // Full dashboard access (View, Process, Complete) — see tests/Pest.php.
    grantFullDashboardAccess($admin);

    Sanctum::actingAs($admin);

    return $admin;
}

function cscAccessId(): int
{
    return AccessType::firstOrCreate(['access_id' => 1], ['access_name' => 'Student'])->access_id;
}

function cscDocType(): DocumentType
{
    return DocumentType::create([
        'document_name'           => 'Transcript of Records',
        'document_description'    => 'Official academic transcript',
        'document_requirements'   => 'Valid ID',
        'document_process_period' => '3-5 business days',
        'access_id'               => cscAccessId(),
    ]);
}

function cscCertType(): CertificationType
{
    return CertificationType::create([
        'certificate_name'           => 'Certificate of Good Moral Character',
        'certificate_requirements'   => 'Valid ID',
        'certificate_process_period' => '3-5 business days',
        'access_id'                  => cscAccessId(),
    ]);
}

function cscRequest(int $statusId): DocumentRequest
{
    return DocumentRequest::factory()->create(['status_id' => $statusId]);
}

function cscDocument(DocumentRequest $request, int $statusId, ?int $groupId = null): RequestDocument
{
    return RequestDocument::create([
        'request_id'               => $request->request_id,
        'document_type_id'         => cscDocType()->document_type_id,
        'number_of_copies'         => 1,
        'status_id'                => $statusId,
        'request_release_group_id' => $groupId,
    ]);
}

function cscCertificate(DocumentRequest $request, int $statusId, ?int $groupId = null): RequestCertificate
{
    return RequestCertificate::create([
        'request_id'               => $request->request_id,
        'certificate_type_id'      => cscCertType()->certificate_type_id,
        'number_of_copies'         => 1,
        'status_id'                => $statusId,
        'request_release_group_id' => $groupId,
    ]);
}

function cscItemHistoryCount(int $requestId): int
{
    return RequestHistory::where('request_id', $requestId)
        ->where(fn ($q) => $q->whereNotNull('request_document_id')->orWhereNotNull('request_certificate_id'))
        ->count();
}

const CSC_READY     = 2;
const CSC_COMPLETED = 3;
const CSC_FORFEITED = 4;
const CSC_WITHDRAWN = 13;

// ═════════════════════════════════════════════════════════════════════════════
// 1. Whole-request claim cascades to the items
// ═════════════════════════════════════════════════════════════════════════════

test('a whole-request claim moves every Ready document and certificate to Completed', function () {
    $request = cscRequest(CSC_READY);
    $doc1    = cscDocument($request, CSC_READY);
    $doc2    = cscDocument($request, CSC_READY);
    $cert    = cscCertificate($request, CSC_READY);
    cscAdmin();

    $this->postJson('/api/document-requests/claim', ['uuid' => $request->uuid])
         ->assertOk()
         ->assertJsonPath('status.status_id', CSC_COMPLETED);

    expect($doc1->fresh()->status_id)->toBe(CSC_COMPLETED);
    expect($doc2->fresh()->status_id)->toBe(CSC_COMPLETED);
    expect($cert->fresh()->status_id)->toBe(CSC_COMPLETED);
    expect($request->fresh()->status_id)->toBe(CSC_COMPLETED);
});

test('claiming by claim_code cascades the same way as claiming by uuid', function () {
    $request = cscRequest(CSC_READY);
    $doc     = cscDocument($request, CSC_READY);
    cscAdmin();

    $this->postJson('/api/document-requests/claim', ['claim_code' => $request->claim_code])
         ->assertOk();

    expect($doc->fresh()->status_id)->toBe(CSC_COMPLETED);
});

test('the claim writes one item-level history row per item, attributed to the scanning admin, with no timing', function () {
    $request = cscRequest(CSC_READY);
    $doc     = cscDocument($request, CSC_READY);
    $cert    = cscCertificate($request, CSC_READY);
    $admin   = cscAdmin();

    $this->postJson('/api/document-requests/claim', ['uuid' => $request->uuid])->assertOk();

    $this->assertDatabaseHas('request_history', [
        'request_id'          => $request->request_id,
        'request_document_id' => $doc->request_document_id,
        'old_status_id'       => CSC_READY,
        'new_status_id'       => CSC_COMPLETED,
        'changed_by'          => $admin->user_id,
        'business_minutes'    => null,
    ]);
    $this->assertDatabaseHas('request_history', [
        'request_id'             => $request->request_id,
        'request_certificate_id' => $cert->request_certificate_id,
        'old_status_id'          => CSC_READY,
        'new_status_id'          => CSC_COMPLETED,
        'changed_by'             => $admin->user_id,
        'business_minutes'       => null,
    ]);

    // Exactly one whole-request Completed row as well (the row the
    // existing claim tests and the free-request report rely on).
    expect(
        RequestHistory::where('request_id', $request->request_id)
            ->whereNull('request_document_id')
            ->whereNull('request_certificate_id')
            ->where('new_status_id', CSC_COMPLETED)
            ->count()
    )->toBe(1);
});

test('a repeat scan is refused and changes nothing further', function () {
    $request = cscRequest(CSC_READY);
    cscDocument($request, CSC_READY);
    cscCertificate($request, CSC_READY);
    cscAdmin();

    $this->postJson('/api/document-requests/claim', ['uuid' => $request->uuid])->assertOk();

    $itemRowsAfterFirstScan = cscItemHistoryCount($request->request_id);
    $notificationsAfterFirstScan = DB::table('notifications')->where('request_id', $request->request_id)->count();
    expect($itemRowsAfterFirstScan)->toBe(2);

    $this->postJson('/api/document-requests/claim', ['uuid' => $request->uuid])->assertStatus(422);

    expect(cscItemHistoryCount($request->request_id))->toBe($itemRowsAfterFirstScan);
    expect(DB::table('notifications')->where('request_id', $request->request_id)->count())
        ->toBe($notificationsAfterFirstScan);
});

test('items that were already Completed are left alone, and other requests are not touched', function () {
    $request       = cscRequest(CSC_READY);
    $alreadyDone   = cscDocument($request, CSC_COMPLETED);
    $stillReady    = cscDocument($request, CSC_READY);

    $otherRequest  = cscRequest(CSC_READY);
    $otherItem     = cscDocument($otherRequest, CSC_READY);
    cscAdmin();

    $this->postJson('/api/document-requests/claim', ['uuid' => $request->uuid])->assertOk();

    expect($stillReady->fresh()->status_id)->toBe(CSC_COMPLETED);
    expect($alreadyDone->fresh()->status_id)->toBe(CSC_COMPLETED);

    // No history row was written for the item that was already done.
    $this->assertDatabaseMissing('request_history', [
        'request_document_id' => $alreadyDone->request_document_id,
    ]);

    // A different request's Ready item is untouched.
    expect($otherItem->fresh()->status_id)->toBe(CSC_READY);
    expect($otherRequest->fresh()->status_id)->toBe(CSC_READY);
});

test('a drifted request still claims, leaves the non-Ready item alone, and logs a warning', function () {
    // Capture the warning with a real Monolog handler instead of Log::spy():
    // a spy replaces the WHOLE Log facade with a mock that returns null for
    // every call, so any other code in the request pipeline that chains off
    // Log (Log::channel(...)->..., withContext, ...) blows up with a 500.
    $logHandler = new \Monolog\Handler\TestHandler();
    Log::channel()->getLogger()->pushHandler($logHandler);

    $request  = cscRequest(CSC_READY);
    $readyDoc = cscDocument($request, CSC_READY);
    $drifted  = cscDocument($request, RequestStatusEnum::Processing->value);
    cscAdmin();

    $this->postJson('/api/document-requests/claim', ['uuid' => $request->uuid])->assertOk();

    expect($readyDoc->fresh()->status_id)->toBe(CSC_COMPLETED);
    expect($drifted->fresh()->status_id)->toBe(RequestStatusEnum::Processing->value);

    expect($logHandler->hasWarningThatContains('non-terminal items'))->toBeTrue();
    expect(collect($logHandler->getRecords())->filter(
        fn ($record) => str_contains((string) $record['message'], 'non-terminal items')
    ))->toHaveCount(1);
});

test('a request-level scan is still refused while release-group tickets are outstanding, and nothing changes', function () {
    $request = cscRequest(CSC_READY);
    $group   = RequestReleaseGroup::create([
        'request_id' => $request->request_id,
        'status_id'  => CSC_READY,
    ]);
    $doc = cscDocument($request, CSC_READY, $group->request_release_group_id);
    cscAdmin();

    $this->postJson('/api/document-requests/claim', ['uuid' => $request->uuid])->assertStatus(422);

    expect($doc->fresh()->status_id)->toBe(CSC_READY);
    expect($group->fresh()->status_id)->toBe(CSC_READY);
    expect($request->fresh()->status_id)->toBe(CSC_READY);
});

// ═════════════════════════════════════════════════════════════════════════════
// 2. A finished request cannot be revived by an item-level action
// ═════════════════════════════════════════════════════════════════════════════

test('marking an item Done under an already Completed request is refused and the request stays Completed', function () {
    // The legacy shape the bug produced: parent Completed, item still Ready.
    $request = cscRequest(CSC_COMPLETED);
    $stale   = cscDocument($request, CSC_READY);
    cscAdmin();

    $this->putJson("/api/document-requests/{$request->request_id}/documents/{$stale->request_document_id}", [
        'status_id' => CSC_COMPLETED,
    ])->assertStatus(422);

    expect($request->fresh()->status_id)->toBe(CSC_COMPLETED);
    expect($stale->fresh()->status_id)->toBe(CSC_READY);
    expect(RequestHistory::where('request_id', $request->request_id)->count())->toBe(0);
    expect(DB::table('notifications')->where('request_id', $request->request_id)->count())->toBe(0);
});

test('an item action on a certificate under a Completed request is refused too', function () {
    $request = cscRequest(CSC_COMPLETED);
    $stale   = cscCertificate($request, CSC_READY);
    cscAdmin();

    $this->putJson("/api/document-requests/{$request->request_id}/certificates/{$stale->request_certificate_id}", [
        'status_id' => CSC_COMPLETED,
    ])->assertStatus(422);

    expect($request->fresh()->status_id)->toBe(CSC_COMPLETED);
});

test('an item action on a Withdrawn request is refused and the request stays Withdrawn', function () {
    $request = cscRequest(CSC_WITHDRAWN);
    $item    = cscDocument($request, RequestStatusEnum::Processing->value);
    cscAdmin();

    $this->putJson("/api/document-requests/{$request->request_id}/documents/{$item->request_document_id}", [
        'status_id' => CSC_READY,
    ])->assertStatus(422);

    expect($request->fresh()->status_id)->toBe(CSC_WITHDRAWN);
    expect($item->fresh()->status_id)->toBe(RequestStatusEnum::Processing->value);
});

test('an item action on a normal, non-final request still works and still rolls the parent up', function () {
    $request = cscRequest(CSC_READY);
    $item    = cscDocument($request, CSC_READY);
    cscAdmin();

    $this->putJson("/api/document-requests/{$request->request_id}/documents/{$item->request_document_id}", [
        'status_id' => CSC_COMPLETED,
    ])->assertOk();

    expect($item->fresh()->status_id)->toBe(CSC_COMPLETED);
    expect($request->fresh()->status_id)->toBe(CSC_COMPLETED);
});

test('bulk-done skips a request that is already final and reports request_terminal', function () {
    $final   = cscRequest(CSC_COMPLETED);
    $stale   = cscDocument($final, CSC_READY);
    cscAdmin();

    $response = $this->postJson('/api/document-requests/bulk-done', [
        'request_ids' => [$final->request_id],
    ])->assertOk();

    expect($response->json('items_updated'))->toBeEmpty();
    expect(collect($response->json('requests_skipped'))->pluck('reason')->all())->toContain('request_terminal');
    expect($stale->fresh()->status_id)->toBe(CSC_READY);
    expect($final->fresh()->status_id)->toBe(CSC_COMPLETED);
});

test('a release-group ticket cannot be claimed against a request that is already final', function () {
    $request = cscRequest(CSC_COMPLETED);
    $group   = RequestReleaseGroup::create([
        'request_id' => $request->request_id,
        'status_id'  => CSC_READY, // stale
    ]);
    cscDocument($request, CSC_READY, $group->request_release_group_id);
    cscAdmin();

    $this->postJson('/api/document-requests/claim', ['uuid' => $group->uuid])->assertStatus(422);

    expect($request->fresh()->status_id)->toBe(CSC_COMPLETED);
    expect($group->fresh()->status_id)->toBe(CSC_READY);
});

// ═════════════════════════════════════════════════════════════════════════════
// 3. ShredExpiredRequests uses the same cascade
// ═════════════════════════════════════════════════════════════════════════════

test('the shredder forfeits still-Ready items and groups, keeps Completed ones, and logs automated history', function () {
    $owner   = SystemUser::factory()->create();
    $request = DocumentRequest::factory()->create([
        'user_id'      => $owner->user_id,
        'status_id'    => CSC_READY,
        'requested_at' => now()->subDays(100),
    ]);
    RequestHistory::create([
        'request_id'         => $request->request_id,
        'old_status_id'      => RequestStatusEnum::Processing->value,
        'new_status_id'      => CSC_READY,
        'changed_at'         => now()->subDays(95),
        'changed_by'         => null,
        'processed_by_email' => 'system-test',
        'minutes_processed'  => 60,
    ]);

    $group       = RequestReleaseGroup::create(['request_id' => $request->request_id, 'status_id' => CSC_READY]);
    $readyDoc    = cscDocument($request, CSC_READY, $group->request_release_group_id);
    $readyCert   = cscCertificate($request, CSC_READY);
    $claimedDoc  = cscDocument($request, CSC_COMPLETED);

    $this->artisan('notifications:shred-expired-requests')->assertExitCode(0);

    expect($request->fresh()->status_id)->toBe(CSC_FORFEITED);
    expect($readyDoc->fresh()->status_id)->toBe(CSC_FORFEITED);
    expect($readyCert->fresh()->status_id)->toBe(CSC_FORFEITED);
    expect($group->fresh()->status_id)->toBe(CSC_FORFEITED);
    expect($claimedDoc->fresh()->status_id)->toBe(CSC_COMPLETED);

    $this->assertDatabaseHas('request_history', [
        'request_id'          => $request->request_id,
        'request_document_id' => $readyDoc->request_document_id,
        'new_status_id'       => CSC_FORFEITED,
        'changed_by'          => null,
        'processed_by_email'  => 'system',
    ]);
    $this->assertDatabaseMissing('request_history', [
        'request_document_id' => $claimedDoc->request_document_id,
    ]);
});

// ═════════════════════════════════════════════════════════════════════════════
// 4. Repair migration
// ═════════════════════════════════════════════════════════════════════════════

function cscRunRepairMigration(): void
{
    $migration = require database_path('migrations/2026_09_29_000000_repair_stale_item_statuses_after_claim.php');
    $migration->up();
}

test('the repair migration completes stale items and groups under Completed and Forfeited requests only', function () {
    // Completed parent, everything stale at Ready.
    $completed      = cscRequest(CSC_COMPLETED);
    $completedGroup = RequestReleaseGroup::create(['request_id' => $completed->request_id, 'status_id' => CSC_READY]);
    $staleDoc       = cscDocument($completed, CSC_READY, $completedGroup->request_release_group_id);
    $staleCert      = cscCertificate($completed, CSC_READY);

    // Forfeited parent with a stale item.
    $forfeited = cscRequest(CSC_FORFEITED);
    $forfeitedDoc = cscDocument($forfeited, CSC_READY);

    // A live request — must not be touched.
    $live     = cscRequest(CSC_READY);
    $liveDoc  = cscDocument($live, CSC_READY);

    // Withdrawn parent — deliberately not repaired here.
    $withdrawn    = cscRequest(CSC_WITHDRAWN);
    $withdrawnDoc = cscDocument($withdrawn, RequestStatusEnum::Processing->value);

    // Completed parent whose item is already Completed — nothing to do.
    $tidy    = cscRequest(CSC_COMPLETED);
    $tidyDoc = cscDocument($tidy, CSC_COMPLETED);

    cscRunRepairMigration();

    expect($staleDoc->fresh()->status_id)->toBe(CSC_COMPLETED);
    expect($staleCert->fresh()->status_id)->toBe(CSC_COMPLETED);
    expect($completedGroup->fresh()->status_id)->toBe(CSC_COMPLETED);
    expect($forfeitedDoc->fresh()->status_id)->toBe(CSC_FORFEITED);

    expect($liveDoc->fresh()->status_id)->toBe(CSC_READY);
    expect($withdrawnDoc->fresh()->status_id)->toBe(RequestStatusEnum::Processing->value);
    expect($tidyDoc->fresh()->status_id)->toBe(CSC_COMPLETED);

    // Parent rows are never modified by the repair.
    expect($completed->fresh()->status_id)->toBe(CSC_COMPLETED);
    expect($forfeited->fresh()->status_id)->toBe(CSC_FORFEITED);

    // Audit rows: one per repaired item, tagged as a repair, untimed.
    $this->assertDatabaseHas('request_history', [
        'request_id'          => $completed->request_id,
        'request_document_id' => $staleDoc->request_document_id,
        'old_status_id'       => CSC_READY,
        'new_status_id'       => CSC_COMPLETED,
        'changed_by'          => null,
        'processed_by_email'  => 'system-repair',
        'business_minutes'    => null,
    ]);
    $this->assertDatabaseHas('request_history', [
        'request_id'             => $completed->request_id,
        'request_certificate_id' => $staleCert->request_certificate_id,
        'new_status_id'          => CSC_COMPLETED,
        'processed_by_email'     => 'system-repair',
    ]);
    $this->assertDatabaseHas('request_history', [
        'request_document_id' => $forfeitedDoc->request_document_id,
        'new_status_id'       => CSC_FORFEITED,
        'processed_by_email'  => 'system-repair',
    ]);
    $this->assertDatabaseMissing('request_history', ['request_document_id' => $liveDoc->request_document_id]);
    $this->assertDatabaseMissing('request_history', ['request_document_id' => $tidyDoc->request_document_id]);
});

test('the repair migration is idempotent', function () {
    $completed = cscRequest(CSC_COMPLETED);
    cscDocument($completed, CSC_READY);
    cscCertificate($completed, CSC_READY);

    cscRunRepairMigration();
    $historyAfterFirstRun = RequestHistory::count();
    expect($historyAfterFirstRun)->toBe(2);

    cscRunRepairMigration();

    expect(RequestHistory::count())->toBe($historyAfterFirstRun);
});

test('the repair migration handles more rows than one chunk', function () {
    $completed = cscRequest(CSC_COMPLETED);

    // request_document has a unique (request_id, document_type_id)
    // constraint, so every line item needs its own document type.
    $rows = [];
    for ($i = 0; $i < 620; $i++) { // chunk size is 500
        $rows[] = [
            'request_id'       => $completed->request_id,
            'document_type_id' => cscDocType()->document_type_id,
            'number_of_copies' => 1,
            'status_id'        => CSC_READY,
        ];
    }
    foreach (array_chunk($rows, 100) as $batch) {
        DB::table('request_document')->insert($batch);
    }

    cscRunRepairMigration();

    expect(DB::table('request_document')->where('status_id', CSC_READY)->count())->toBe(0);
    expect(DB::table('request_document')->where('status_id', CSC_COMPLETED)->count())->toBe(620);
    expect(RequestHistory::where('processed_by_email', 'system-repair')->count())->toBe(620);
});