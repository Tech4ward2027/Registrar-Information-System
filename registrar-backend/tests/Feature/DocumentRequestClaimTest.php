<?php

use App\Enums\RequestStatusEnum;
use App\Models\DocumentRequest;
use App\Models\RequestStatus;
use App\Models\SystemUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// ── Helpers ───────────────────────────────────────────────────────────────────
// Mirrors drMakeUser()/drSeedStatuses() from DocumentRequestArchiveTest.php.

function claimMakeUser(int $roleId): SystemUser
{
    $user = SystemUser::factory()->create(['role_id' => $roleId, 'status' => 'Activated']);

    // See tests/Pest.php::grantFullDashboardAccess() — claimRequest()
    // delegates into updateRequest() targeting Completed, which requires
    // the dashboard 'Complete' action (the route also gates on
    // module:dashboard,Complete), and a plain admin has zero dashboard
    // access without an attached policy since Work Item #1.
    grantFullDashboardAccess($user);

    Sanctum::actingAs($user);
    return $user;
}

function claimSeedStatuses(): void
{
    foreach ([1 => 'Processing', 2 => 'Ready to Claim', 3 => 'Completed', 4 => 'Forfeited'] as $id => $name) {
        RequestStatus::firstOrCreate(['status_id' => $id], ['status_name' => $name]);
    }
}

// ═════════════════════════════════════════════════════════════════════════════
// Happy path — this is the endpoint's whole reason to exist: turn a scanned
// uuid or a typed claim_code into a completed request, with zero new
// business logic beyond the lookup (claimRequest() delegates straight into
// updateRequest(), which is already covered by its own tests elsewhere).
// ═════════════════════════════════════════════════════════════════════════════

test('staff can complete a claim via uuid (QR scan)', function () {
    claimSeedStatuses();
    $docReq = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::ReadyToClaim->value]);
    claimMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson('/api/v1/document-requests/claim', ['uuid' => $docReq->uuid])
         ->assertOk()
         ->assertJsonPath('status.status_id', RequestStatusEnum::Completed->value);

    $this->assertDatabaseHas('document_request', [
        'request_id' => $docReq->request_id,
        'status_id'  => RequestStatusEnum::Completed->value,
    ]);
});

test('staff can complete a claim via claim_code (manual fallback)', function () {
    claimSeedStatuses();
    $docReq = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::ReadyToClaim->value]);
    claimMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson('/api/v1/document-requests/claim', ['claim_code' => $docReq->claim_code])
         ->assertOk()
         ->assertJsonPath('status.status_id', RequestStatusEnum::Completed->value);
});

test('claim_code lookup is case-insensitive', function () {
    claimSeedStatuses();
    $docReq = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::ReadyToClaim->value]);
    claimMakeUser(SystemUser::ROLE_ADMIN);

    // ClaimDocumentRequestRequest::prepareForValidation() uppercases
    // manual input — codes are always stored uppercase, so a lowercase
    // submission (e.g. autocorrect, a student reading it out loud) must
    // still resolve.
    $this->postJson('/api/v1/document-requests/claim', ['claim_code' => strtolower($docReq->claim_code)])
         ->assertOk()
         ->assertJsonPath('status.status_id', RequestStatusEnum::Completed->value);
});

test('a completed claim records request history and notifies the owner', function () {
    claimSeedStatuses();
    $owner  = SystemUser::factory()->create();
    $docReq = DocumentRequest::factory()->create([
        'user_id'   => $owner->user_id,
        'status_id' => RequestStatusEnum::ReadyToClaim->value,
    ]);
    claimMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson('/api/v1/document-requests/claim', ['uuid' => $docReq->uuid])
         ->assertOk();

    // Same audit trail every other status change goes through — claiming
    // isn't a special case, it's updateRequest() under a new entry point.
    $this->assertDatabaseHas('request_history', [
        'request_id'    => $docReq->request_id,
        'old_status_id' => RequestStatusEnum::ReadyToClaim->value,
        'new_status_id' => RequestStatusEnum::Completed->value,
    ]);

    $this->assertDatabaseHas('notifications', [
        'notifiable_id' => $owner->user_id,
    ]);
});

// ═════════════════════════════════════════════════════════════════════════════
// §3.7 — single-use / double-scan
// ═════════════════════════════════════════════════════════════════════════════

test('scanning an already-completed request is rejected', function () {
    claimSeedStatuses();
    $docReq = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::Completed->value]);
    claimMakeUser(SystemUser::ROLE_ADMIN);

    // Completed has no allowedTransitions() — the same guard updateRequest()
    // uses for every other status change catches this with no extra code,
    // per the "single-use is already covered" reasoning from the policy
    // mapping. Asserting it explicitly here rather than trusting that by
    // inference.
    $this->postJson('/api/v1/document-requests/claim', ['uuid' => $docReq->uuid])
         ->assertStatus(422);

    $this->assertDatabaseHas('document_request', [
        'request_id' => $docReq->request_id,
        'status_id'  => RequestStatusEnum::Completed->value, // unchanged
    ]);
});

test('scanning the same QR twice in a row only completes the claim once', function () {
    claimSeedStatuses();
    $docReq = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::ReadyToClaim->value]);
    claimMakeUser(SystemUser::ROLE_ADMIN);

    // First scan: succeeds, transitions ReadyToClaim -> Completed.
    $this->postJson('/api/v1/document-requests/claim', ['uuid' => $docReq->uuid])
         ->assertOk();

    // Second scan of the exact same QR (a static QR sitting in frame across
    // multiple polls, or the student showing the same code again by mistake):
    // must fail, not silently no-op or re-fire notifications.
    $this->postJson('/api/v1/document-requests/claim', ['uuid' => $docReq->uuid])
         ->assertStatus(422);

    // Exactly one Completed transition recorded, not two.
    expect(
        \App\Models\RequestHistory::where('request_id', $docReq->request_id)
            ->where('new_status_id', RequestStatusEnum::Completed->value)
            ->count()
    )->toBe(1);
});

// ═════════════════════════════════════════════════════════════════════════════
// Scan before ReadyToClaim
// ═════════════════════════════════════════════════════════════════════════════

test('scanning a request that is still processing is rejected', function () {
    claimSeedStatuses();
    $docReq = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::Processing->value]);
    claimMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson('/api/v1/document-requests/claim', ['uuid' => $docReq->uuid])
         ->assertStatus(422);

    $this->assertDatabaseHas('document_request', [
        'request_id' => $docReq->request_id,
        'status_id'  => RequestStatusEnum::Processing->value, // unchanged
    ]);
});

test('scanning a request still pending signature is rejected', function () {
    claimSeedStatuses();
    RequestStatus::firstOrCreate(['status_id' => 6], ['status_name' => 'Pending Signature']);
    $docReq = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::PendingSignature->value]);
    claimMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson('/api/v1/document-requests/claim', ['uuid' => $docReq->uuid])
         ->assertStatus(422);
});

// ═════════════════════════════════════════════════════════════════════════════
// Unknown / malformed credentials
// ═════════════════════════════════════════════════════════════════════════════

test('an unknown uuid returns a generic 404, not a validation error', function () {
    claimSeedStatuses();
    claimMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson('/api/v1/document-requests/claim', ['uuid' => (string) \Illuminate\Support\Str::uuid()])
         ->assertStatus(404);
});

test('an unknown claim_code returns a generic 404', function () {
    claimSeedStatuses();
    claimMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson('/api/v1/document-requests/claim', ['claim_code' => 'ZZZZZZ'])
         ->assertStatus(404);
});

test('an archived request cannot be claimed even with a valid code', function () {
    claimSeedStatuses();
    $docReq = DocumentRequest::factory()->create([
        'status_id'   => RequestStatusEnum::ReadyToClaim->value,
        'is_archived' => true,
        'archived_on' => now(),
    ]);
    claimMakeUser(SystemUser::ROLE_ADMIN);

    // claimRequest()'s lookup uses the model's default (non-archived) scope,
    // same as every other query — an archived request's own code simply
    // won't match, surfacing as the same generic 404 as an unknown code.
    $this->postJson('/api/v1/document-requests/claim', ['uuid' => $docReq->uuid])
         ->assertStatus(404);
});

test('providing both uuid and claim_code is rejected as a validation error', function () {
    claimSeedStatuses();
    $docReq = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::ReadyToClaim->value]);
    claimMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson('/api/v1/document-requests/claim', [
        'uuid'       => $docReq->uuid,
        'claim_code' => $docReq->claim_code,
    ])->assertStatus(422);

    // Nothing should have been completed — a malformed request must not
    // have any side effect.
    $this->assertDatabaseHas('document_request', [
        'request_id' => $docReq->request_id,
        'status_id'  => RequestStatusEnum::ReadyToClaim->value,
    ]);
});

test('providing neither uuid nor claim_code is rejected as a validation error', function () {
    claimSeedStatuses();
    claimMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson('/api/v1/document-requests/claim', [])
         ->assertStatus(422);
});

test('a malformed claim_code (wrong length) is rejected as a validation error, not a 404', function () {
    claimSeedStatuses();
    claimMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson('/api/v1/document-requests/claim', ['claim_code' => 'ABC'])
         ->assertStatus(422);
});

// ═════════════════════════════════════════════════════════════════════════════
// Authorization — role:3 (staff/admin) on the route, ClaimDocumentRequestRequest
// ::authorize() as the second layer.
// ═════════════════════════════════════════════════════════════════════════════

test('a student cannot claim a request, even their own', function () {
    claimSeedStatuses();
    $owner  = claimMakeUser(SystemUser::ROLE_STUDENT);
    $docReq = DocumentRequest::factory()->create([
        'user_id'   => $owner->user_id,
        'status_id' => RequestStatusEnum::ReadyToClaim->value,
    ]);

    $this->postJson('/api/v1/document-requests/claim', ['uuid' => $docReq->uuid])
         ->assertStatus(403);
});

test('an unauthenticated request cannot claim', function () {
    claimSeedStatuses();
    $docReq = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::ReadyToClaim->value]);

    $this->postJson('/api/v1/document-requests/claim', ['uuid' => $docReq->uuid])
         ->assertStatus(401);
});

// ═════════════════════════════════════════════════════════════════════════════
// Concurrency — the lock claimRequest() relies on (via updateRequest()'s
// lockForUpdate()) is exercised here at the single-process level: this
// doesn't prove true multi-connection blocking (Pest/SQLite runs one
// connection per test), but it does prove the *outcome* the lock exists to
// guarantee — that two claim attempts against the same row never both
// succeed, regardless of ordering. A real concurrent-connection test would
// need a DB that supports genuine row locking under parallel connections
// (MySQL, not the sqlite :memory: driver these tests run against) and is
// better suited to a dedicated integration/staging check than the unit
// suite.
// ═════════════════════════════════════════════════════════════════════════════

test('two claim attempts on the same request never both succeed', function () {
    claimSeedStatuses();
    $docReq = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::ReadyToClaim->value]);
    claimMakeUser(SystemUser::ROLE_ADMIN);

    $first  = $this->postJson('/api/v1/document-requests/claim', ['uuid' => $docReq->uuid]);
    $second = $this->postJson('/api/v1/document-requests/claim', ['uuid' => $docReq->uuid]);

    $statuses = [$first->getStatusCode(), $second->getStatusCode()];
    sort($statuses);

    // Exactly one 200 and one 422 — never both 200 (double-completion)
    // and never both failing (the legitimate first scan must go through).
    expect($statuses)->toBe([200, 422]);

    $docReq->refresh();
    expect($docReq->status_id)->toBe(RequestStatusEnum::Completed->value);
});

test('claiming by uuid and then by claim_code for the same request only completes once', function () {
    claimSeedStatuses();
    $docReq = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::ReadyToClaim->value]);
    claimMakeUser(SystemUser::ROLE_ADMIN);

    // Simulates a scan succeeding at the counter while the claim_code was
    // also typed into a second tab/device for the same student — both
    // credentials point at the same row, so only one may win.
    $byUuid = $this->postJson('/api/v1/document-requests/claim', ['uuid' => $docReq->uuid]);
    $byCode = $this->postJson('/api/v1/document-requests/claim', ['claim_code' => $docReq->claim_code]);

    $statuses = [$byUuid->getStatusCode(), $byCode->getStatusCode()];
    sort($statuses);
    expect($statuses)->toBe([200, 422]);
});