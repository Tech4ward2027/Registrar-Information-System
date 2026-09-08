<?php

use App\Enums\DeficiencyItemEnum;
use App\Enums\RequestStatusEnum;
use App\Enums\WithdrawalReasonEnum;
use App\Models\AuditLog;
use App\Models\DocumentRequest;
use App\Models\Notification;
use App\Models\RequestRemark;
use App\Models\RequestStatus;
use App\Models\SystemUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Deficiency Notice & Withdrawn Status — Phase 5
| ("Withdraw while a Deficiency Notice is open")
|--------------------------------------------------------------------------
| Covers DocumentRequestService::withdraw()'s auto-void cascade: an open
| request_remarks row on a request that is about to be withdrawn is
| automatically voided in the SAME transaction, with void_reason
| cascading from the withdrawal_reason/withdrawal_detail — see that
| method's docblock for the full "block vs. auto-void" reasoning.
|--------------------------------------------------------------------------
*/

// ── Helpers ───────────────────────────────────────────────────────────────────
// Mirrors withdrawMakeUser()/withdrawSeedStatuses() from
// DocumentRequestWithdrawTest.php.

function autoVoidMakeUser(int $roleId): SystemUser
{
    $user = SystemUser::factory()->create(['role_id' => $roleId, 'status' => 'Activated']);
    grantFullDashboardAccess($user);
    Sanctum::actingAs($user);
    return $user;
}

function autoVoidSeedStatuses(): void
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
// Auto-void on withdraw
// ═════════════════════════════════════════════════════════════════════════════

test('withdrawing a request with an open Deficiency Notice auto-voids it', function () {
    autoVoidSeedStatuses();
    $docReq = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::Processing->value]);
    $remark = RequestRemark::factory()->create([
        'request_id' => $docReq->request_id,
        'item_key'   => DeficiencyItemEnum::MissingSignature->value,
        'status'     => RequestRemark::STATUS_OPEN,
    ]);
    autoVoidMakeUser(SystemUser::ROLE_ADMIN);

    $response = $this->postJson("/api/document-requests/{$docReq->request_id}/withdraw", [
        'withdrawal_reason' => WithdrawalReasonEnum::WrongItemPaid->value,
    ])->assertOk();

    $response->assertJsonPath('status_id', RequestStatusEnum::Withdrawn->value);
    $response->assertJsonPath('auto_voided_deficiency_notice_id', $remark->remark_id);

    $this->assertDatabaseHas('request_remarks', [
        'remark_id' => $remark->remark_id,
        'status'    => RequestRemark::STATUS_VOIDED,
    ]);

    $remark->refresh();
    expect($remark->voided_by)->not->toBeNull();
    expect($remark->voided_at)->not->toBeNull();
    expect($remark->void_reason)->toContain('Wrong item was paid for');
});

test('the cascaded void_reason substitutes withdrawal_detail when the reason is Other', function () {
    autoVoidSeedStatuses();
    $docReq = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::Processing->value]);
    $remark = RequestRemark::factory()->create([
        'request_id' => $docReq->request_id,
        'status'     => RequestRemark::STATUS_OPEN,
    ]);
    autoVoidMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson("/api/document-requests/{$docReq->request_id}/withdraw", [
        'withdrawal_reason' => WithdrawalReasonEnum::Other->value,
        'withdrawal_detail' => 'Student confirmed by phone they no longer need any of this.',
    ])->assertOk();

    $remark->refresh();
    expect($remark->void_reason)->toContain('Student confirmed by phone they no longer need any of this.');
});

test('withdrawing a request with no open Deficiency Notice reports no auto-void', function () {
    autoVoidSeedStatuses();
    $docReq = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::Processing->value]);
    autoVoidMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson("/api/document-requests/{$docReq->request_id}/withdraw", [
        'withdrawal_reason' => WithdrawalReasonEnum::WrongItemPaid->value,
    ])->assertOk()
      ->assertJsonPath('auto_voided_deficiency_notice_id', null);
});

test('withdrawing a request does not touch an already-resolved (cleared) notice', function () {
    autoVoidSeedStatuses();
    $docReq = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::Processing->value]);
    $remark = RequestRemark::factory()->cleared()->create([
        'request_id' => $docReq->request_id,
    ]);
    autoVoidMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson("/api/document-requests/{$docReq->request_id}/withdraw", [
        'withdrawal_reason' => WithdrawalReasonEnum::WrongItemPaid->value,
    ])->assertOk()
      ->assertJsonPath('auto_voided_deficiency_notice_id', null);

    $this->assertDatabaseHas('request_remarks', [
        'remark_id' => $remark->remark_id,
        'status'    => RequestRemark::STATUS_CLEARED,
    ]);
});

test('withdrawing a request with an already-voided notice does not double-void it', function () {
    autoVoidSeedStatuses();
    $docReq = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::Processing->value]);
    $remark = RequestRemark::factory()->voided()->create([
        'request_id'  => $docReq->request_id,
        'void_reason' => 'Original manual void reason — must not be overwritten.',
    ]);
    autoVoidMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson("/api/document-requests/{$docReq->request_id}/withdraw", [
        'withdrawal_reason' => WithdrawalReasonEnum::WrongItemPaid->value,
    ])->assertOk()
      ->assertJsonPath('auto_voided_deficiency_notice_id', null);

    $this->assertDatabaseHas('request_remarks', [
        'remark_id'   => $remark->remark_id,
        'void_reason' => 'Original manual void reason — must not be overwritten.',
    ]);
});

// ═════════════════════════════════════════════════════════════════════════════
// Auditing — both the request-withdrawn and notice-voided entries are logged
// ═════════════════════════════════════════════════════════════════════════════

test('auto-voiding writes a distinct deficiency_notice_voided audit entry alongside request_withdrawn', function () {
    autoVoidSeedStatuses();
    $docReq = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::Processing->value]);
    $remark = RequestRemark::factory()->create([
        'request_id' => $docReq->request_id,
        'status'     => RequestRemark::STATUS_OPEN,
    ]);
    $admin = autoVoidMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson("/api/document-requests/{$docReq->request_id}/withdraw", [
        'withdrawal_reason' => WithdrawalReasonEnum::WrongItemPaid->value,
    ])->assertOk();

    $this->assertDatabaseHas('audit_logs', [
        'action'  => AuditLog::ACTION_REQUEST_WITHDRAWN,
        'user_id' => $admin->user_id,
    ]);

    $this->assertDatabaseHas('audit_logs', [
        'action'  => AuditLog::ACTION_DEFICIENCY_NOTICE_VOIDED,
        'user_id' => $admin->user_id,
    ]);
});

test('withdrawing with no open notice writes no deficiency_notice_voided audit entry', function () {
    autoVoidSeedStatuses();
    $docReq = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::Processing->value]);
    autoVoidMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson("/api/document-requests/{$docReq->request_id}/withdraw", [
        'withdrawal_reason' => WithdrawalReasonEnum::WrongItemPaid->value,
    ])->assertOk();

    $this->assertDatabaseMissing('audit_logs', [
        'action' => AuditLog::ACTION_DEFICIENCY_NOTICE_VOIDED,
    ]);
});

// ═════════════════════════════════════════════════════════════════════════════
// Notifications — exactly one notification fires (request_withdrawn), not two
// ═════════════════════════════════════════════════════════════════════════════

test('auto-voiding a notice during withdrawal sends only the withdrawal notification, not a second notice-voided notification', function () {
    autoVoidSeedStatuses();
    $owner  = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT]);
    $docReq = DocumentRequest::factory()->create([
        'user_id'   => $owner->user_id,
        'status_id' => RequestStatusEnum::Processing->value,
    ]);
    RequestRemark::factory()->create([
        'request_id' => $docReq->request_id,
        'status'     => RequestRemark::STATUS_OPEN,
    ]);
    autoVoidMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson("/api/document-requests/{$docReq->request_id}/withdraw", [
        'withdrawal_reason' => WithdrawalReasonEnum::WrongItemPaid->value,
    ])->assertOk();

    $notifications = Notification::where('notifiable_id', $owner->user_id)
        ->where('notifiable_type', SystemUser::class)
        ->where('request_id', $docReq->request_id)
        ->get();

    expect($notifications)->toHaveCount(1);
    expect($notifications->first()->data['message'] ?? null)->toContain('Wrong item was paid for');
});

// ═════════════════════════════════════════════════════════════════════════════
// Guard interaction — an invalid withdrawal transition never touches the notice
// ═════════════════════════════════════════════════════════════════════════════

test('a rejected withdrawal transition leaves an open notice untouched', function () {
    autoVoidSeedStatuses();
    // ReadyToClaim -> Withdrawn is not an allowed transition (see
    // RequestStatusEnum::allowedTransitions()) — the request-status
    // guard must abort BEFORE the auto-void logic runs, so a failed
    // withdrawal attempt must never leave a notice voided behind it.
    $docReq = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::ReadyToClaim->value]);
    $remark = RequestRemark::factory()->create([
        'request_id' => $docReq->request_id,
        'status'     => RequestRemark::STATUS_OPEN,
    ]);
    autoVoidMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson("/api/document-requests/{$docReq->request_id}/withdraw", [
        'withdrawal_reason' => WithdrawalReasonEnum::WrongItemPaid->value,
    ])->assertStatus(422);

    $this->assertDatabaseHas('request_remarks', [
        'remark_id' => $remark->remark_id,
        'status'    => RequestRemark::STATUS_OPEN,
    ]);
});
