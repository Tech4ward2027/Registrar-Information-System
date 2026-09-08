<?php

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

function deficiencyMakeUser(int $roleId): SystemUser
{
    $user = SystemUser::factory()->create(['role_id' => $roleId, 'status' => 'Activated']);

    grantFullDashboardAccess($user);

    Sanctum::actingAs($user);
    return $user;
}

function deficiencySeedStatuses(): void
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
// Issue — Authorization
// ═════════════════════════════════════════════════════════════════════════════

test('student cannot issue a deficiency notice', function () {
    deficiencySeedStatuses();
    $owner  = deficiencyMakeUser(SystemUser::ROLE_STUDENT);
    $docReq = DocumentRequest::factory()->create([
        'user_id'   => $owner->user_id,
        'status_id' => RequestStatusEnum::Processing->value,
    ]);

    $this->postJson("/api/document-requests/{$docReq->request_id}/deficiency-notices", [
        'item_key' => DeficiencyItemEnum::MissingSignature->value,
    ])->assertStatus(403);
});

test('issuing a notice on a missing request returns 404', function () {
    deficiencyMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson('/api/document-requests/999999/deficiency-notices', [
        'item_key' => DeficiencyItemEnum::MissingSignature->value,
    ])->assertStatus(404);
});

// ═════════════════════════════════════════════════════════════════════════════
// Issue — Happy path
// ═════════════════════════════════════════════════════════════════════════════

test('admin can issue a deficiency notice', function () {
    deficiencySeedStatuses();
    $docReq = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::Processing->value]);
    $admin  = deficiencyMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson("/api/document-requests/{$docReq->request_id}/deficiency-notices", [
        'item_key' => DeficiencyItemEnum::MissingSignature->value,
    ])
        ->assertCreated()
        ->assertJsonPath('status', RequestRemark::STATUS_OPEN)
        ->assertJsonPath('item_key', DeficiencyItemEnum::MissingSignature->value)
        ->assertJsonPath('item_label', DeficiencyItemEnum::MissingSignature->label());

    $this->assertDatabaseHas('request_remarks', [
        'request_id' => $docReq->request_id,
        'item_key'   => DeficiencyItemEnum::MissingSignature->value,
        'status'     => RequestRemark::STATUS_OPEN,
        'issued_by'  => $admin->user_id,
    ]);

    $this->assertDatabaseHas('audit_logs', [
        'action'  => AuditLog::ACTION_DEFICIENCY_NOTICE_ISSUED,
        'user_id' => $admin->user_id,
    ]);
});

test('issuing a notice does not change document_request.status_id', function () {
    deficiencySeedStatuses();
    $docReq = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::Processing->value]);
    deficiencyMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson("/api/document-requests/{$docReq->request_id}/deficiency-notices", [
        'item_key' => DeficiencyItemEnum::MissingValidId->value,
    ])->assertCreated();

    $this->assertDatabaseHas('document_request', [
        'request_id' => $docReq->request_id,
        'status_id'  => RequestStatusEnum::Processing->value,
    ]);
});

// ═════════════════════════════════════════════════════════════════════════════
// Issue — Validation
// ═════════════════════════════════════════════════════════════════════════════

test('item_key is required', function () {
    deficiencySeedStatuses();
    $docReq = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::Processing->value]);
    deficiencyMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson("/api/document-requests/{$docReq->request_id}/deficiency-notices", [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['item_key']);
});

test('item_key must be a valid enum value', function () {
    deficiencySeedStatuses();
    $docReq = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::Processing->value]);
    deficiencyMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson("/api/document-requests/{$docReq->request_id}/deficiency-notices", [
        'item_key' => 'not_a_real_item',
    ])->assertStatus(422)
      ->assertJsonValidationErrors(['item_key']);
});

test('other requires detail', function () {
    deficiencySeedStatuses();
    $docReq = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::Processing->value]);
    deficiencyMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson("/api/document-requests/{$docReq->request_id}/deficiency-notices", [
        'item_key' => DeficiencyItemEnum::Other->value,
    ])->assertStatus(422)
      ->assertJsonValidationErrors(['detail']);
});

test('other with detail succeeds', function () {
    deficiencySeedStatuses();
    $docReq = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::Processing->value]);
    deficiencyMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson("/api/document-requests/{$docReq->request_id}/deficiency-notices", [
        'item_key' => DeficiencyItemEnum::Other->value,
        'detail'   => 'Missing notarized affidavit of loss.',
    ])->assertCreated()
      ->assertJsonPath('detail', 'Missing notarized affidavit of loss.');
});

test('item_label cannot be supplied by the client', function () {
    deficiencySeedStatuses();
    $docReq = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::Processing->value]);
    deficiencyMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson("/api/document-requests/{$docReq->request_id}/deficiency-notices", [
        'item_key'   => DeficiencyItemEnum::MissingSignature->value,
        'item_label' => 'Something else entirely',
    ])->assertCreated()
      ->assertJsonPath('item_label', DeficiencyItemEnum::MissingSignature->label());
});

// ═════════════════════════════════════════════════════════════════════════════
// Issue — Business rule guards
// ═════════════════════════════════════════════════════════════════════════════

test('cannot issue a second open notice while one is already open', function () {
    deficiencySeedStatuses();
    $docReq = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::Processing->value]);
    deficiencyMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson("/api/document-requests/{$docReq->request_id}/deficiency-notices", [
        'item_key' => DeficiencyItemEnum::MissingSignature->value,
    ])->assertCreated();

    $this->postJson("/api/document-requests/{$docReq->request_id}/deficiency-notices", [
        'item_key' => DeficiencyItemEnum::MissingValidId->value,
    ])->assertStatus(422);

    expect(RequestRemark::where('request_id', $docReq->request_id)->count())->toBe(1);
});

test('a new notice can be issued once the prior one is cleared', function () {
    deficiencySeedStatuses();
    $docReq = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::Processing->value]);
    deficiencyMakeUser(SystemUser::ROLE_ADMIN);

    $first = $this->postJson("/api/document-requests/{$docReq->request_id}/deficiency-notices", [
        'item_key' => DeficiencyItemEnum::MissingSignature->value,
    ])->assertCreated()->json();

    $this->postJson("/api/deficiency-notices/{$first['remark_id']}/clear")->assertOk();

    $this->postJson("/api/document-requests/{$docReq->request_id}/deficiency-notices", [
        'item_key' => DeficiencyItemEnum::MissingValidId->value,
    ])->assertCreated();

    expect(RequestRemark::where('request_id', $docReq->request_id)->count())->toBe(2);
});

test('cannot issue a notice against an archived request', function () {
    deficiencySeedStatuses();
    $docReq = DocumentRequest::factory()->create([
        'status_id'   => RequestStatusEnum::Processing->value,
        'is_archived' => true,
        'archived_on' => now(),
    ]);
    deficiencyMakeUser(SystemUser::ROLE_ADMIN);

    // ExcludeArchivedScope means the route's implicit binding 404s on an
    // archived request — same as every other write route on this model.
    $this->postJson("/api/document-requests/{$docReq->request_id}/deficiency-notices", [
        'item_key' => DeficiencyItemEnum::MissingSignature->value,
    ])->assertStatus(404);
});

test('cannot issue a notice against a withdrawn request', function () {
    deficiencySeedStatuses();
    $docReq = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::Withdrawn->value]);
    deficiencyMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson("/api/document-requests/{$docReq->request_id}/deficiency-notices", [
        'item_key' => DeficiencyItemEnum::MissingSignature->value,
    ])->assertStatus(422);

    expect(RequestRemark::where('request_id', $docReq->request_id)->exists())->toBeFalse();
});

// ═════════════════════════════════════════════════════════════════════════════
// Clear
// ═════════════════════════════════════════════════════════════════════════════

test('student cannot clear a deficiency notice', function () {
    deficiencySeedStatuses();
    $remark = RequestRemark::factory()->create();
    deficiencyMakeUser(SystemUser::ROLE_STUDENT);

    $this->postJson("/api/deficiency-notices/{$remark->remark_id}/clear")->assertStatus(403);
});

test('admin can clear an open notice', function () {
    deficiencySeedStatuses();
    $remark = RequestRemark::factory()->create();
    $admin  = deficiencyMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson("/api/deficiency-notices/{$remark->remark_id}/clear")
        ->assertOk()
        ->assertJsonPath('status', RequestRemark::STATUS_CLEARED);

    $this->assertDatabaseHas('request_remarks', [
        'remark_id'  => $remark->remark_id,
        'status'     => RequestRemark::STATUS_CLEARED,
        'cleared_by' => $admin->user_id,
    ]);

    $this->assertDatabaseHas('audit_logs', [
        'action'  => AuditLog::ACTION_DEFICIENCY_NOTICE_CLEARED,
        'user_id' => $admin->user_id,
    ]);
});

test('cannot clear an already-cleared notice', function () {
    deficiencySeedStatuses();
    $remark = RequestRemark::factory()->cleared()->create();
    deficiencyMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson("/api/deficiency-notices/{$remark->remark_id}/clear")->assertStatus(422);
});

test('cannot clear an already-voided notice', function () {
    deficiencySeedStatuses();
    $remark = RequestRemark::factory()->voided()->create();
    deficiencyMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson("/api/deficiency-notices/{$remark->remark_id}/clear")->assertStatus(422);
});

test('clearing a notice does not change document_request.status_id', function () {
    deficiencySeedStatuses();
    $docReq = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::Processing->value]);
    $remark = RequestRemark::factory()->create(['request_id' => $docReq->request_id]);
    deficiencyMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson("/api/deficiency-notices/{$remark->remark_id}/clear")->assertOk();

    $this->assertDatabaseHas('document_request', [
        'request_id' => $docReq->request_id,
        'status_id'  => RequestStatusEnum::Processing->value,
    ]);
});

// ═════════════════════════════════════════════════════════════════════════════
// Void
// ═════════════════════════════════════════════════════════════════════════════

test('admin can void an open notice with a reason', function () {
    deficiencySeedStatuses();
    $remark = RequestRemark::factory()->create();
    $admin  = deficiencyMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson("/api/deficiency-notices/{$remark->remark_id}/void", [
        'void_reason' => 'Student confirmed deceased; family notified the office.',
    ])
        ->assertOk()
        ->assertJsonPath('status', RequestRemark::STATUS_VOIDED)
        ->assertJsonPath('void_reason', 'Student confirmed deceased; family notified the office.');

    $this->assertDatabaseHas('audit_logs', [
        'action'  => AuditLog::ACTION_DEFICIENCY_NOTICE_VOIDED,
        'user_id' => $admin->user_id,
    ]);
});

test('void_reason is required', function () {
    deficiencySeedStatuses();
    $remark = RequestRemark::factory()->create();
    deficiencyMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson("/api/deficiency-notices/{$remark->remark_id}/void", [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['void_reason']);
});

test('cannot void an already-resolved notice', function () {
    deficiencySeedStatuses();
    $remark = RequestRemark::factory()->cleared()->create();
    deficiencyMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson("/api/deficiency-notices/{$remark->remark_id}/void", [
        'void_reason' => 'Too late, already cleared.',
    ])->assertStatus(422);
});

test('voiding a notice does not auto-transition the parent request status', function () {
    deficiencySeedStatuses();
    $docReq = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::Processing->value]);
    $remark = RequestRemark::factory()->create(['request_id' => $docReq->request_id]);
    deficiencyMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson("/api/deficiency-notices/{$remark->remark_id}/void", [
        'void_reason' => 'Student unreachable after repeated attempts.',
    ])->assertOk();

    $this->assertDatabaseHas('document_request', [
        'request_id' => $docReq->request_id,
        'status_id'  => RequestStatusEnum::Processing->value,
    ]);
});

// ═════════════════════════════════════════════════════════════════════════════
// Notifications — fire exactly once per action, with the correct text
// ═════════════════════════════════════════════════════════════════════════════

test('issuing a notice notifies the owner with the item label', function () {
    deficiencySeedStatuses();
    $owner  = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT]);
    $docReq = DocumentRequest::factory()->create([
        'user_id'   => $owner->user_id,
        'status_id' => RequestStatusEnum::Processing->value,
    ]);
    deficiencyMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson("/api/document-requests/{$docReq->request_id}/deficiency-notices", [
        'item_key' => DeficiencyItemEnum::MissingSignature->value,
    ])->assertCreated();

    $notification = Notification::where('notifiable_id', $owner->user_id)
        ->where('notifiable_type', SystemUser::class)
        ->where('request_id', $docReq->request_id)
        ->latest('created_at')
        ->first();

    expect($notification)->not->toBeNull();
    expect($notification->data['message'] ?? null)->toContain('Missing Signature');
});

test('issuing a notice with item_key "other" substitutes the staff-entered detail', function () {
    deficiencySeedStatuses();
    $owner  = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT]);
    $docReq = DocumentRequest::factory()->create([
        'user_id'   => $owner->user_id,
        'status_id' => RequestStatusEnum::Processing->value,
    ]);
    deficiencyMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson("/api/document-requests/{$docReq->request_id}/deficiency-notices", [
        'item_key' => DeficiencyItemEnum::Other->value,
        'detail'   => 'Missing notarized affidavit of loss.',
    ])->assertCreated();

    $notification = Notification::where('notifiable_id', $owner->user_id)
        ->where('notifiable_type', SystemUser::class)
        ->where('request_id', $docReq->request_id)
        ->latest('created_at')
        ->first();

    expect($notification->data['message'] ?? null)->toContain('Missing notarized affidavit of loss.');
});

test('clearing a notice notifies the owner that processing has resumed', function () {
    deficiencySeedStatuses();
    $owner  = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT]);
    $docReq = DocumentRequest::factory()->create(['user_id' => $owner->user_id]);
    $remark = RequestRemark::factory()->create(['request_id' => $docReq->request_id]);
    deficiencyMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson("/api/deficiency-notices/{$remark->remark_id}/clear")->assertOk();

    $notification = Notification::where('notifiable_id', $owner->user_id)
        ->where('notifiable_type', SystemUser::class)
        ->where('request_id', $docReq->request_id)
        ->latest('created_at')
        ->first();

    expect($notification)->not->toBeNull();
    expect($notification->data['message'] ?? null)->toContain('resuming processing');
});

test('voiding a notice notifies the owner with the void reason', function () {
    deficiencySeedStatuses();
    $owner  = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT]);
    $docReq = DocumentRequest::factory()->create(['user_id' => $owner->user_id]);
    $remark = RequestRemark::factory()->create(['request_id' => $docReq->request_id]);
    deficiencyMakeUser(SystemUser::ROLE_ADMIN);

    $this->postJson("/api/deficiency-notices/{$remark->remark_id}/void", [
        'void_reason' => 'Student confirmed deceased; family notified the office.',
    ])->assertOk();

    $notification = Notification::where('notifiable_id', $owner->user_id)
        ->where('notifiable_type', SystemUser::class)
        ->where('request_id', $docReq->request_id)
        ->latest('created_at')
        ->first();

    expect($notification->data['message'] ?? null)
        ->toContain('Student confirmed deceased; family notified the office.');
});

// ═════════════════════════════════════════════════════════════════════════════
// DocumentRequestController::show() eager-loads the open notice
// ═════════════════════════════════════════════════════════════════════════════

test('show() includes the open deficiency notice', function () {
    deficiencySeedStatuses();
    $docReq = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::Processing->value]);
    RequestRemark::factory()->create(['request_id' => $docReq->request_id]);
    deficiencyMakeUser(SystemUser::ROLE_ADMIN);

    $this->getJson("/api/document-requests/{$docReq->request_id}")
        ->assertOk()
        ->assertJsonPath('open_deficiency_notice.status', RequestRemark::STATUS_OPEN)
        ->assertJsonPath('open_deficiency_notice.item_key', DeficiencyItemEnum::MissingSignature->value);
});

test('show() omits the notice once cleared', function () {
    deficiencySeedStatuses();
    $docReq = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::Processing->value]);
    RequestRemark::factory()->cleared()->create(['request_id' => $docReq->request_id]);
    deficiencyMakeUser(SystemUser::ROLE_ADMIN);

    $this->getJson("/api/document-requests/{$docReq->request_id}")
        ->assertOk()
        ->assertJsonPath('open_deficiency_notice', null);
});
