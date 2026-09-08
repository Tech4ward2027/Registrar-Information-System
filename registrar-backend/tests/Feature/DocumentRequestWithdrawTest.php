<?php

use App\Enums\RequestStatusEnum;
use App\Enums\WithdrawalReasonEnum;
use App\Models\AuditLog;
use App\Models\DocumentRequest;
use App\Models\Notification;
use App\Models\RequestStatus;
use App\Models\SystemUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// ── Helpers ───────────────────────────────────────────────────────────────────
// Mirrors drMakeUser()/drSeedStatuses() from DocumentRequestArchiveTest.php.

function withdrawMakeUser(int $roleId): SystemUser
{
    $user = SystemUser::factory()->create(['role_id' => $roleId, 'status' => 'Activated']);

    // See tests/Pest.php::grantFullDashboardAccess() — withdraw() is
    // gated by module:dashboard,Process, and a plain admin has zero
    // dashboard access without an attached policy since Work Item #1.
    grantFullDashboardAccess($user);

    Sanctum::actingAs($user);
    return $user;
}

function withdrawSeedStatuses(): void
{
    foreach ([
        1  => 'Processing',
        2  => 'Ready to Claim',
        3  => 'Completed',
        4  => 'Forfeited',
        6  => 'Pending Signature',
        12 => 'Awaiting Submission',
        13 => 'Withdrawn',
    ] as $id => $name) {
        RequestStatus::firstOrCreate(['status_id' => $id], ['status_name' => $name]);
    }
}

// ═════════════════════════════════════════════════════════════════════════════
// Authorization — role:3 + module:dashboard,Process + DocumentRequestPolicy::withdraw
// ═════════════════════════════════════════════════════════════════════════════

test('student cannot withdraw a document request', function () {
    withdrawSeedStatuses();
    $owner  = withdrawMakeUser(SystemUser::ROLE_STUDENT);
    $docReq = DocumentRequest::factory()->create([
        'user_id'   => $owner->user_id,
        'status_id' => RequestStatusEnum::Processing->value,
    ]);

    $this->postJson("/api/document-requests/{$docReq->request_id}/withdraw", [
        'withdrawal_reason' => WithdrawalReasonEnum::WrongItemPaid->value,
    ])->assertStatus(403);
});

test('withdraw returns 404 for a missing request', function () {
    withdrawMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson('/api/document-requests/999999/withdraw', [
        'withdrawal_reason' => WithdrawalReasonEnum::WrongItemPaid->value,
    ])->assertStatus(404);
});

// ═════════════════════════════════════════════════════════════════════════════
// Valid transitions — AwaitingSubmission | Processing | PendingSignature → Withdrawn
// ═════════════════════════════════════════════════════════════════════════════

test('admin can withdraw a request from Processing', function () {
    withdrawSeedStatuses();
    $docReq = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::Processing->value]);
    $admin  = withdrawMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson("/api/document-requests/{$docReq->request_id}/withdraw", [
        'withdrawal_reason' => WithdrawalReasonEnum::WrongItemPaid->value,
    ])
        ->assertOk()
        ->assertJsonPath('status_id', RequestStatusEnum::Withdrawn->value)
        ->assertJsonPath('withdrawal_reason', WithdrawalReasonEnum::WrongItemPaid->value);

    $this->assertDatabaseHas('document_request', [
        'request_id'        => $docReq->request_id,
        'status_id'         => RequestStatusEnum::Withdrawn->value,
        'withdrawal_reason' => WithdrawalReasonEnum::WrongItemPaid->value,
    ]);

    $this->assertDatabaseHas('audit_logs', [
        'action'  => AuditLog::ACTION_REQUEST_WITHDRAWN,
        'user_id' => $admin->user_id,
    ]);
});

test('admin can withdraw a request from AwaitingSubmission', function () {
    withdrawSeedStatuses();
    $docReq = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::AwaitingSubmission->value]);
    withdrawMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson("/api/document-requests/{$docReq->request_id}/withdraw", [
        'withdrawal_reason' => WithdrawalReasonEnum::StudentNoLongerNeeds->value,
    ])->assertOk()
      ->assertJsonPath('status_id', RequestStatusEnum::Withdrawn->value);
});

test('admin can withdraw a request from PendingSignature', function () {
    withdrawSeedStatuses();
    $docReq = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::PendingSignature->value]);
    withdrawMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson("/api/document-requests/{$docReq->request_id}/withdraw", [
        'withdrawal_reason' => WithdrawalReasonEnum::DuplicateSubmission->value,
    ])->assertOk()
      ->assertJsonPath('status_id', RequestStatusEnum::Withdrawn->value);
});

// ═════════════════════════════════════════════════════════════════════════════
// Invalid transitions
// ═════════════════════════════════════════════════════════════════════════════

test('cannot withdraw a request that is ReadyToClaim', function () {
    withdrawSeedStatuses();
    $docReq = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::ReadyToClaim->value]);
    withdrawMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson("/api/document-requests/{$docReq->request_id}/withdraw", [
        'withdrawal_reason' => WithdrawalReasonEnum::WrongItemPaid->value,
    ])->assertStatus(422);

    $this->assertDatabaseHas('document_request', [
        'request_id' => $docReq->request_id,
        'status_id'  => RequestStatusEnum::ReadyToClaim->value,
    ]);
});

test('cannot withdraw an already-Completed request', function () {
    withdrawSeedStatuses();
    $docReq = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::Completed->value]);
    withdrawMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson("/api/document-requests/{$docReq->request_id}/withdraw", [
        'withdrawal_reason' => WithdrawalReasonEnum::WrongItemPaid->value,
    ])->assertStatus(422);
});

test('cannot withdraw an already-Withdrawn request', function () {
    withdrawSeedStatuses();
    $docReq = DocumentRequest::factory()->create([
        'status_id'         => RequestStatusEnum::Withdrawn->value,
        'withdrawal_reason' => WithdrawalReasonEnum::WrongItemPaid->value,
    ]);
    withdrawMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson("/api/document-requests/{$docReq->request_id}/withdraw", [
        'withdrawal_reason' => WithdrawalReasonEnum::DuplicateSubmission->value,
    ])->assertStatus(422);
});

test('cannot withdraw an archived request', function () {
    withdrawSeedStatuses();
    $docReq = DocumentRequest::factory()->create([
        'status_id'   => RequestStatusEnum::Processing->value,
        'is_archived' => true,
        'archived_on' => now(),
        'archived_by' => null,
    ]);
    withdrawMakeUser(SystemUser::ROLE_ADMIN);

    // ExcludeArchivedScope means the route's implicit binding 404s on an
    // archived request — same as every other write route on this model
    // that doesn't explicitly opt into withArchived() (e.g. update()).
    $this->postJson("/api/document-requests/{$docReq->request_id}/withdraw", [
        'withdrawal_reason' => WithdrawalReasonEnum::WrongItemPaid->value,
    ])->assertStatus(404);
});

// ═════════════════════════════════════════════════════════════════════════════
// Validation — reason required, "other" requires detail, supersede must exist
// ═════════════════════════════════════════════════════════════════════════════

test('withdrawal_reason is required', function () {
    withdrawSeedStatuses();
    $docReq = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::Processing->value]);
    withdrawMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson("/api/document-requests/{$docReq->request_id}/withdraw", [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['withdrawal_reason']);
});

test('withdrawal_reason must be a valid enum value', function () {
    withdrawSeedStatuses();
    $docReq = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::Processing->value]);
    withdrawMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson("/api/document-requests/{$docReq->request_id}/withdraw", [
        'withdrawal_reason' => 'not_a_real_reason',
    ])->assertStatus(422)
      ->assertJsonValidationErrors(['withdrawal_reason']);
});

test('other requires withdrawal_detail', function () {
    withdrawSeedStatuses();
    $docReq = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::Processing->value]);
    withdrawMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson("/api/document-requests/{$docReq->request_id}/withdraw", [
        'withdrawal_reason' => WithdrawalReasonEnum::Other->value,
    ])->assertStatus(422)
      ->assertJsonValidationErrors(['withdrawal_detail']);
});

test('other with withdrawal_detail succeeds', function () {
    withdrawSeedStatuses();
    $docReq = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::Processing->value]);
    withdrawMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson("/api/document-requests/{$docReq->request_id}/withdraw", [
        'withdrawal_reason' => WithdrawalReasonEnum::Other->value,
        'withdrawal_detail' => 'Student was declared deceased per family notice.',
    ])->assertOk()
      ->assertJsonPath('withdrawal_detail', 'Student was declared deceased per family notice.');
});

test('superseded_by_request_id must reference an existing request', function () {
    withdrawSeedStatuses();
    $docReq = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::Processing->value]);
    withdrawMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson("/api/document-requests/{$docReq->request_id}/withdraw", [
        'withdrawal_reason'        => WithdrawalReasonEnum::DuplicateSubmission->value,
        'superseded_by_request_id' => 999999,
    ])->assertStatus(422);
});

test('a request cannot supersede itself', function () {
    withdrawSeedStatuses();
    $docReq = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::Processing->value]);
    withdrawMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson("/api/document-requests/{$docReq->request_id}/withdraw", [
        'withdrawal_reason'        => WithdrawalReasonEnum::DuplicateSubmission->value,
        'superseded_by_request_id' => $docReq->request_id,
    ])->assertStatus(422);
});

test('superseded_by_request_id is persisted when it references a real request', function () {
    withdrawSeedStatuses();
    $superseding = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::Processing->value]);
    $duplicate   = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::Processing->value]);
    withdrawMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson("/api/document-requests/{$duplicate->request_id}/withdraw", [
        'withdrawal_reason'        => WithdrawalReasonEnum::DuplicateSubmission->value,
        'superseded_by_request_id' => $superseding->request_id,
    ])->assertOk()
      ->assertJsonPath('superseded_by_request_id', $superseding->request_id);
});

// ═════════════════════════════════════════════════════════════════════════════
// Notification — request_withdrawn fires with the resolved reason text
// ═════════════════════════════════════════════════════════════════════════════

test('withdrawing a request notifies the owner with the human-readable reason', function () {
    withdrawSeedStatuses();
    $owner  = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT]);
    $docReq = DocumentRequest::factory()->create([
        'user_id'   => $owner->user_id,
        'status_id' => RequestStatusEnum::Processing->value,
    ]);
    withdrawMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson("/api/document-requests/{$docReq->request_id}/withdraw", [
        'withdrawal_reason' => WithdrawalReasonEnum::WrongItemPaid->value,
    ])->assertOk();

    $notification = Notification::where('notifiable_id', $owner->user_id)
        ->where('notifiable_type', SystemUser::class)
        ->where('request_id', $docReq->request_id)
        ->latest('created_at')
        ->first();

    expect($notification)->not->toBeNull();
    expect($notification->data['message'] ?? null)
        ->toContain('Wrong item was paid for');
});

test('withdrawing with reason "other" substitutes the staff-entered detail, not a generic label', function () {
    withdrawSeedStatuses();
    $owner  = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT]);
    $docReq = DocumentRequest::factory()->create([
        'user_id'   => $owner->user_id,
        'status_id' => RequestStatusEnum::Processing->value,
    ]);
    withdrawMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson("/api/document-requests/{$docReq->request_id}/withdraw", [
        'withdrawal_reason' => WithdrawalReasonEnum::Other->value,
        'withdrawal_detail' => 'Requestor passed away; family notified the office.',
    ])->assertOk();

    $notification = Notification::where('notifiable_id', $owner->user_id)
        ->where('notifiable_type', SystemUser::class)
        ->where('request_id', $docReq->request_id)
        ->latest('created_at')
        ->first();

    expect($notification->data['message'] ?? null)
        ->toContain('Requestor passed away; family notified the office.');
});

// ═════════════════════════════════════════════════════════════════════════════
// Paid OR is preserved — Phase 1 exit criteria
// ═════════════════════════════════════════════════════════════════════════════

test('withdrawing a request leaves or_number and receipt_date untouched', function () {
    withdrawSeedStatuses();
    $docReq = DocumentRequest::factory()->create([
        'status_id'    => RequestStatusEnum::Processing->value,
        'or_number'    => 'OR-2026-000123',
        'receipt_date' => '2026-09-01',
    ]);
    withdrawMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson("/api/document-requests/{$docReq->request_id}/withdraw", [
        'withdrawal_reason' => WithdrawalReasonEnum::WrongItemPaid->value,
    ])->assertOk();

    // Assert through the model's `date` cast rather than a raw
    // assertDatabaseHas() string match. SQLite has no native DATE type,
    // so it round-trips `date`-cast columns as `Y-m-d H:i:s`; comparing
    // the raw column against a bare `Y-m-d` string is driver-dependent
    // and fails on SQLite even when the value is untouched. Re-fetching
    // through Eloquent and comparing the cast Carbon instance is
    // correct regardless of the underlying database driver.
    $fresh = $docReq->fresh();

    $this->assertSame('OR-2026-000123', $fresh->or_number);
    $this->assertNotNull($fresh->receipt_date);
    $this->assertTrue($fresh->receipt_date->isSameDay('2026-09-01'));
});

// ═════════════════════════════════════════════════════════════════════════════
// request_history is written like any other transition
// ═════════════════════════════════════════════════════════════════════════════

test('withdrawing a request writes a request_history row', function () {
    withdrawSeedStatuses();
    $docReq = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::Processing->value]);
    withdrawMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson("/api/document-requests/{$docReq->request_id}/withdraw", [
        'withdrawal_reason' => WithdrawalReasonEnum::WrongItemPaid->value,
    ])->assertOk();

    $this->assertDatabaseHas('request_history', [
        'request_id'    => $docReq->request_id,
        'old_status_id' => RequestStatusEnum::Processing->value,
        'new_status_id' => RequestStatusEnum::Withdrawn->value,
    ]);
});