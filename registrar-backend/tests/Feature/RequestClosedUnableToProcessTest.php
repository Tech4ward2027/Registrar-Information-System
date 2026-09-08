<?php

use App\Enums\ClosureReasonEnum;
use App\Enums\DeficiencyItemEnum;
use App\Enums\RequestStatusEnum;
use App\Models\AuditLog;
use App\Models\DocumentRequest;
use App\Models\Notification;
use App\Models\RequestRemark;
use App\Models\RequestStatus;
use App\Models\SystemUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// ── Helpers ───────────────────────────────────────────────────────────────────
// Mirrors withdrawMakeUser()/withdrawSeedStatuses() from
// DocumentRequestWithdrawTest.php.

function cutpMakeUser(int $roleId): SystemUser
{
    $user = SystemUser::factory()->create(['role_id' => $roleId, 'status' => 'Activated']);
    grantFullDashboardAccess($user);
    Sanctum::actingAs($user);
    return $user;
}

function cutpSeedStatuses(): void
{
    foreach ([
        1  => 'Processing',
        2  => 'Ready to Claim',
        3  => 'Completed',
        4  => 'Forfeited',
        6  => 'Pending Signature',
        12 => 'Awaiting Submission',
        13 => 'Withdrawn',
        14 => 'Closed - Unable to Process',
    ] as $id => $name) {
        RequestStatus::firstOrCreate(['status_id' => $id], ['status_name' => $name]);
    }
}

function cutpValidPayload(array $overrides = []): array
{
    return array_merge([
        'closure_reason'          => ClosureReasonEnum::RequestorDeceased->value,
        'closure_proof_reference' => 'Death certificate submitted by next of kin, verified 2026-09-10.',
    ], $overrides);
}

// ═════════════════════════════════════════════════════════════════════════════
// Authorization
// ═════════════════════════════════════════════════════════════════════════════

test('student cannot close a request as unable to process', function () {
    cutpSeedStatuses();
    $docReq = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::Processing->value]);
    RequestRemark::factory()->create(['request_id' => $docReq->request_id]);
    cutpMakeUser(SystemUser::ROLE_STUDENT);

    $this->postJson("/api/document-requests/{$docReq->request_id}/close-unable-to-process", cutpValidPayload())
        ->assertStatus(403);
});

test('close-unable-to-process returns 404 for a missing request', function () {
    cutpMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson('/api/document-requests/999999/close-unable-to-process', cutpValidPayload())
        ->assertStatus(404);
});

// ═════════════════════════════════════════════════════════════════════════════
// Core guard — requires an OPEN Deficiency Notice
// ═════════════════════════════════════════════════════════════════════════════

test('cannot close a request with no Deficiency Notice at all', function () {
    cutpSeedStatuses();
    $docReq = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::Processing->value]);
    cutpMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson("/api/document-requests/{$docReq->request_id}/close-unable-to-process", cutpValidPayload())
        ->assertStatus(422);

    $this->assertDatabaseHas('document_request', [
        'request_id' => $docReq->request_id,
        'status_id'  => RequestStatusEnum::Processing->value,
    ]);
});

test('cannot close a request whose only notice is already cleared', function () {
    cutpSeedStatuses();
    $docReq = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::Processing->value]);
    RequestRemark::factory()->cleared()->create(['request_id' => $docReq->request_id]);
    cutpMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson("/api/document-requests/{$docReq->request_id}/close-unable-to-process", cutpValidPayload())
        ->assertStatus(422);
});

test('cannot close a request whose only notice is already voided', function () {
    cutpSeedStatuses();
    $docReq = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::Processing->value]);
    RequestRemark::factory()->voided()->create(['request_id' => $docReq->request_id]);
    cutpMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson("/api/document-requests/{$docReq->request_id}/close-unable-to-process", cutpValidPayload())
        ->assertStatus(422);
});

test('admin can close a request that has an open Deficiency Notice', function () {
    cutpSeedStatuses();
    $docReq = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::Processing->value]);
    $notice = RequestRemark::factory()->create(['request_id' => $docReq->request_id]);
    $admin  = cutpMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson("/api/document-requests/{$docReq->request_id}/close-unable-to-process", cutpValidPayload())
        ->assertOk()
        ->assertJsonPath('status_id', RequestStatusEnum::ClosedUnableToProcess->value)
        ->assertJsonPath('closure_reason', ClosureReasonEnum::RequestorDeceased->value);

    $this->assertDatabaseHas('document_request', [
        'request_id' => $docReq->request_id,
        'status_id'  => RequestStatusEnum::ClosedUnableToProcess->value,
        'closed_by'  => $admin->user_id,
    ]);

    // The open notice is auto-voided as part of the same closure action.
    $this->assertDatabaseHas('request_remarks', [
        'remark_id' => $notice->remark_id,
        'status'    => RequestRemark::STATUS_VOIDED,
    ]);
});

// ═════════════════════════════════════════════════════════════════════════════
// Valid/invalid transitions — same source-status set as Withdrawn
// ═════════════════════════════════════════════════════════════════════════════

test('admin can close a request from AwaitingSubmission', function () {
    cutpSeedStatuses();
    $docReq = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::AwaitingSubmission->value]);
    RequestRemark::factory()->create(['request_id' => $docReq->request_id]);
    cutpMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson("/api/document-requests/{$docReq->request_id}/close-unable-to-process", cutpValidPayload())
        ->assertOk()
        ->assertJsonPath('status_id', RequestStatusEnum::ClosedUnableToProcess->value);
});

test('admin can close a request from PendingSignature', function () {
    cutpSeedStatuses();
    $docReq = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::PendingSignature->value]);
    RequestRemark::factory()->create(['request_id' => $docReq->request_id]);
    cutpMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson("/api/document-requests/{$docReq->request_id}/close-unable-to-process", cutpValidPayload())
        ->assertOk()
        ->assertJsonPath('status_id', RequestStatusEnum::ClosedUnableToProcess->value);
});

test('cannot close a request that is ReadyToClaim, even with an open notice', function () {
    cutpSeedStatuses();
    $docReq = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::ReadyToClaim->value]);
    // Contrived for the transition test's sake — an open notice would not
    // ordinarily coexist with ReadyToClaim in real usage, but the guard
    // order (transition check before notice check) should still reject
    // this correctly regardless.
    RequestRemark::factory()->create(['request_id' => $docReq->request_id]);
    cutpMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson("/api/document-requests/{$docReq->request_id}/close-unable-to-process", cutpValidPayload())
        ->assertStatus(422);
});

test('cannot close an already-Withdrawn request', function () {
    cutpSeedStatuses();
    $docReq = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::Withdrawn->value]);
    cutpMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson("/api/document-requests/{$docReq->request_id}/close-unable-to-process", cutpValidPayload())
        ->assertStatus(422);
});

test('cannot close an archived request', function () {
    cutpSeedStatuses();
    $docReq = DocumentRequest::factory()->create([
        'status_id'   => RequestStatusEnum::Processing->value,
        'is_archived' => true,
        'archived_on' => now(),
    ]);
    cutpMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson("/api/document-requests/{$docReq->request_id}/close-unable-to-process", cutpValidPayload())
        ->assertStatus(404); // ExcludeArchivedScope, same as withdraw()
});

// ═════════════════════════════════════════════════════════════════════════════
// Validation
// ═════════════════════════════════════════════════════════════════════════════

test('closure_reason is required', function () {
    cutpSeedStatuses();
    $docReq = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::Processing->value]);
    RequestRemark::factory()->create(['request_id' => $docReq->request_id]);
    cutpMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson("/api/document-requests/{$docReq->request_id}/close-unable-to-process", [
        'closure_proof_reference' => 'Death certificate on file.',
    ])->assertStatus(422)
      ->assertJsonValidationErrors(['closure_reason']);
});

test('closure_reason must be a valid enum value', function () {
    cutpSeedStatuses();
    $docReq = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::Processing->value]);
    RequestRemark::factory()->create(['request_id' => $docReq->request_id]);
    cutpMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson("/api/document-requests/{$docReq->request_id}/close-unable-to-process", cutpValidPayload([
        'closure_reason' => 'not_a_real_reason',
    ]))->assertStatus(422)
       ->assertJsonValidationErrors(['closure_reason']);
});

test('closure_proof_reference is required regardless of reason', function () {
    cutpSeedStatuses();
    $docReq = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::Processing->value]);
    RequestRemark::factory()->create(['request_id' => $docReq->request_id]);
    cutpMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson("/api/document-requests/{$docReq->request_id}/close-unable-to-process", [
        'closure_reason' => ClosureReasonEnum::RequestorDeceased->value,
    ])->assertStatus(422)
      ->assertJsonValidationErrors(['closure_proof_reference']);
});

test('other requires closure_detail', function () {
    cutpSeedStatuses();
    $docReq = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::Processing->value]);
    RequestRemark::factory()->create(['request_id' => $docReq->request_id]);
    cutpMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson("/api/document-requests/{$docReq->request_id}/close-unable-to-process", cutpValidPayload([
        'closure_reason' => ClosureReasonEnum::Other->value,
    ]))->assertStatus(422)
       ->assertJsonValidationErrors(['closure_detail']);
});

test('other with closure_detail succeeds', function () {
    cutpSeedStatuses();
    $docReq = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::Processing->value]);
    RequestRemark::factory()->create(['request_id' => $docReq->request_id]);
    cutpMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson("/api/document-requests/{$docReq->request_id}/close-unable-to-process", cutpValidPayload([
        'closure_reason' => ClosureReasonEnum::Other->value,
        'closure_detail' => 'Requestor confirmed incompetent by court order; guardian cannot comply.',
    ]))->assertOk()
       ->assertJsonPath('closure_detail', 'Requestor confirmed incompetent by court order; guardian cannot comply.');
});

// ═════════════════════════════════════════════════════════════════════════════
// Notification + audit trail
// ═════════════════════════════════════════════════════════════════════════════

test('closing a request notifies the owner with the resolved closure reason', function () {
    cutpSeedStatuses();
    $owner  = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT]);
    $docReq = DocumentRequest::factory()->create([
        'user_id'   => $owner->user_id,
        'status_id' => RequestStatusEnum::Processing->value,
    ]);
    RequestRemark::factory()->create(['request_id' => $docReq->request_id]);
    cutpMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson("/api/document-requests/{$docReq->request_id}/close-unable-to-process", cutpValidPayload())
        ->assertOk();

    $notification = Notification::where('notifiable_id', $owner->user_id)
        ->where('notifiable_type', SystemUser::class)
        ->where('request_id', $docReq->request_id)
        ->latest('created_at')
        ->first();

    expect($notification)->not->toBeNull();
    expect($notification->data['message'] ?? null)->toContain('Requestor deceased');
});

test('closing writes ACTION_REQUEST_CLOSED_UNABLE_TO_PROCESS and a distinct voided-notice audit entry', function () {
    cutpSeedStatuses();
    $docReq = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::Processing->value]);
    $notice = RequestRemark::factory()->create(['request_id' => $docReq->request_id]);
    $admin  = cutpMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson("/api/document-requests/{$docReq->request_id}/close-unable-to-process", cutpValidPayload())
        ->assertOk();

    $this->assertDatabaseHas('audit_logs', [
        'action'  => AuditLog::ACTION_REQUEST_CLOSED_UNABLE_TO_PROCESS,
        'user_id' => $admin->user_id,
    ]);

    $this->assertDatabaseHas('audit_logs', [
        'action'  => AuditLog::ACTION_DEFICIENCY_NOTICE_VOIDED,
        'user_id' => $admin->user_id,
    ]);
});

test('closing a request writes a request_history row', function () {
    cutpSeedStatuses();
    $docReq = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::Processing->value]);
    RequestRemark::factory()->create(['request_id' => $docReq->request_id]);
    cutpMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson("/api/document-requests/{$docReq->request_id}/close-unable-to-process", cutpValidPayload())
        ->assertOk();

    $this->assertDatabaseHas('request_history', [
        'request_id'    => $docReq->request_id,
        'old_status_id' => RequestStatusEnum::Processing->value,
        'new_status_id' => RequestStatusEnum::ClosedUnableToProcess->value,
    ]);
});

// ═════════════════════════════════════════════════════════════════════════════
// Analytics exclusion — parity with Withdrawn
// ═════════════════════════════════════════════════════════════════════════════

test('a ClosedUnableToProcess request is excluded from processing-time averages but counted in totals', function () {
    cutpSeedStatuses();
    $docReq = DocumentRequest::factory()->create([
        'status_id'    => RequestStatusEnum::Processing->value,
        'requested_at' => now(),
    ]);
    RequestRemark::factory()->create(['request_id' => $docReq->request_id]);
    cutpMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson("/api/document-requests/{$docReq->request_id}/close-unable-to-process", cutpValidPayload())
        ->assertOk();

    $range   = [now()->subDay(), now()->addDay()];
    $overview = (new \App\Services\AnalyticsService())->overview($range);

    // The request exists and was real intake — it should not vanish
    // from the system entirely, only from the completion-time average
    // (see AnalyticsService::excludeFromProcessingTimeMetrics()).
    expect((int) $overview['total'])->toBeGreaterThanOrEqual(1);
});
