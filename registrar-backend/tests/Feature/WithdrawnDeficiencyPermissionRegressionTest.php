<?php

use App\Enums\DeficiencyItemEnum;
use App\Enums\RequestStatusEnum;
use App\Enums\WithdrawalReasonEnum;
use App\Models\DocumentRequest;
use App\Models\RequestRemark;
use App\Models\RequestStatus;
use App\Models\SystemUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Deficiency Notice & Withdrawn Status — Phase 5
| ("Permission regression tests")
|--------------------------------------------------------------------------
| Confirms no existing role:1,2 (student/alumni) route can reach any of
| the new staff-only endpoints this feature introduced:
|   POST /document-requests/{documentRequest}/withdraw
|   POST /document-requests/{documentRequest}/deficiency-notices
|   POST /deficiency-notices/{deficiencyNotice}/clear
|   POST /deficiency-notices/{deficiencyNotice}/void
|
| All four are gated by role:3 (Admin) middleware at the route level
| (routes/api.php) BEFORE either FormRequest::authorize() or the
| corresponding Policy method ever runs — this suite specifically
| exercises that outer route-level gate, distinct from
| DocumentRequestWithdrawTest.php's/DeficiencyNoticeTest.php's own
| authorization tests, which exercise the Policy layer for an
| already-role:3-admitted request. A regression in either layer alone
| should fail a test somewhere; this file is what would catch a
| regression in the OUTER gate specifically (e.g. someone loosening
| routes/api.php's middleware to 'role:1,2,3' by mistake).
|
| Every case below uses a real Sanctum-authenticated student or alumni
| user (not an anonymous guest) so a 403 unambiguously means "role
| middleware correctly rejected this authenticated-but-wrong-role user",
| not "no one was logged in" (which would 401, a different failure mode
| entirely — see RoleMiddleware::handle()).
|--------------------------------------------------------------------------
*/

// ── Helpers ───────────────────────────────────────────────────────────────────

function permRegressionMakeUser(int $roleId): SystemUser
{
    $user = SystemUser::factory()->create(['role_id' => $roleId, 'status' => 'Activated']);
    Sanctum::actingAs($user);
    return $user;
}

function permRegressionSeedStatuses(): void
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
// POST /document-requests/{documentRequest}/withdraw
// ═════════════════════════════════════════════════════════════════════════════

test('student cannot reach the withdraw route', function () {
    permRegressionSeedStatuses();
    $docReq = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::Processing->value]);
    permRegressionMakeUser(SystemUser::ROLE_STUDENT);

    $this->postJson("/api/document-requests/{$docReq->request_id}/withdraw", [
        'withdrawal_reason' => WithdrawalReasonEnum::WrongItemPaid->value,
    ])->assertStatus(403);

    $this->assertDatabaseHas('document_request', [
        'request_id' => $docReq->request_id,
        'status_id'  => RequestStatusEnum::Processing->value,
    ]);
});

test('alumni cannot reach the withdraw route', function () {
    permRegressionSeedStatuses();
    $docReq = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::Processing->value]);
    permRegressionMakeUser(SystemUser::ROLE_ALUMNI);

    $this->postJson("/api/document-requests/{$docReq->request_id}/withdraw", [
        'withdrawal_reason' => WithdrawalReasonEnum::WrongItemPaid->value,
    ])->assertStatus(403);
});

// ═════════════════════════════════════════════════════════════════════════════
// POST /document-requests/{documentRequest}/deficiency-notices
// ═════════════════════════════════════════════════════════════════════════════

test('student cannot reach the issue-deficiency-notice route', function () {
    permRegressionSeedStatuses();
    $docReq = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::Processing->value]);
    permRegressionMakeUser(SystemUser::ROLE_STUDENT);

    $this->postJson("/api/document-requests/{$docReq->request_id}/deficiency-notices", [
        'item_key' => DeficiencyItemEnum::MissingSignature->value,
    ])->assertStatus(403);

    $this->assertDatabaseMissing('request_remarks', ['request_id' => $docReq->request_id]);
});

test('alumni cannot reach the issue-deficiency-notice route', function () {
    permRegressionSeedStatuses();
    $docReq = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::Processing->value]);
    permRegressionMakeUser(SystemUser::ROLE_ALUMNI);

    $this->postJson("/api/document-requests/{$docReq->request_id}/deficiency-notices", [
        'item_key' => DeficiencyItemEnum::MissingSignature->value,
    ])->assertStatus(403);
});

// ═════════════════════════════════════════════════════════════════════════════
// POST /deficiency-notices/{deficiencyNotice}/clear
// ═════════════════════════════════════════════════════════════════════════════

test('student cannot reach the clear-deficiency-notice route', function () {
    permRegressionSeedStatuses();
    $remark = RequestRemark::factory()->create();
    permRegressionMakeUser(SystemUser::ROLE_STUDENT);

    $this->postJson("/api/deficiency-notices/{$remark->remark_id}/clear")
        ->assertStatus(403);

    $this->assertDatabaseHas('request_remarks', [
        'remark_id' => $remark->remark_id,
        'status'    => RequestRemark::STATUS_OPEN,
    ]);
});

test('alumni cannot reach the clear-deficiency-notice route', function () {
    permRegressionSeedStatuses();
    $remark = RequestRemark::factory()->create();
    permRegressionMakeUser(SystemUser::ROLE_ALUMNI);

    $this->postJson("/api/deficiency-notices/{$remark->remark_id}/clear")
        ->assertStatus(403);
});

// ═════════════════════════════════════════════════════════════════════════════
// POST /deficiency-notices/{deficiencyNotice}/void
// ═════════════════════════════════════════════════════════════════════════════

test('student cannot reach the void-deficiency-notice route', function () {
    permRegressionSeedStatuses();
    $remark = RequestRemark::factory()->create();
    permRegressionMakeUser(SystemUser::ROLE_STUDENT);

    $this->postJson("/api/deficiency-notices/{$remark->remark_id}/void", [
        'void_reason' => 'Attempting an unauthorized void.',
    ])->assertStatus(403);

    $this->assertDatabaseHas('request_remarks', [
        'remark_id' => $remark->remark_id,
        'status'    => RequestRemark::STATUS_OPEN,
    ]);
});

test('alumni cannot reach the void-deficiency-notice route', function () {
    permRegressionSeedStatuses();
    $remark = RequestRemark::factory()->create();
    permRegressionMakeUser(SystemUser::ROLE_ALUMNI);

    $this->postJson("/api/deficiency-notices/{$remark->remark_id}/void", [
        'void_reason' => 'Attempting an unauthorized void.',
    ])->assertStatus(403);
});

// ═════════════════════════════════════════════════════════════════════════════
// Unauthenticated — sanity check that these routes 401, not 403, with no
// session at all (distinguishes "wrong role" from "no session"; both are
// blocked, but RoleMiddleware only returns 403 once $request->user() exists).
// ═════════════════════════════════════════════════════════════════════════════

test('an unauthenticated request to withdraw is rejected before role checking', function () {
    permRegressionSeedStatuses();
    $docReq = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::Processing->value]);

    $this->postJson("/api/document-requests/{$docReq->request_id}/withdraw", [
        'withdrawal_reason' => WithdrawalReasonEnum::WrongItemPaid->value,
    ])->assertStatus(401);
});
