<?php

use App\Enums\ClosureReasonEnum;
use App\Enums\DeficiencyItemEnum;
use App\Enums\RequestStatusEnum;
use App\Enums\WithdrawalReasonEnum;
use App\Models\AccessType;
use App\Models\AuditLog;
use App\Models\CertificationType;
use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Models\RequestCertificate;
use App\Models\RequestDocument;
use App\Models\RequestRemark;
use App\Models\RequestStatus;
use App\Models\SystemUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// ═════════════════════════════════════════════════════════════════════════════
// Phase 5 - per-item Deficiency Notices.
// Helper names carry an "ndn" prefix (Pest files share one function namespace).
// ═════════════════════════════════════════════════════════════════════════════

function ndnStaff(): SystemUser
{
    foreach ([
        1 => 'Processing', 2 => 'Ready to Claim', 3 => 'Completed', 4 => 'Forfeited',
        6 => 'Pending Signature', 12 => 'Awaiting Submission', 13 => 'Withdrawn',
        14 => 'Closed - Unable to Process',
    ] as $id => $name) {
        RequestStatus::firstOrCreate(['status_id' => $id], ['status_name' => $name]);
    }

    $admin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN, 'status' => 'Activated']);
    grantFullDashboardAccess($admin);
    Sanctum::actingAs($admin);

    return $admin;
}

function ndnDocument(DocumentRequest $request, int $statusId, string $name = 'Transcript of Records'): RequestDocument
{
    $access = AccessType::firstOrCreate(['access_id' => 1], ['access_name' => 'Student'])->access_id;

    $type = DocumentType::firstOrCreate(['document_name' => $name], [
        'document_description'    => 'desc',
        'document_requirements'   => 'Valid ID',
        'document_process_period' => '3-5 business days',
        'access_id'               => $access,
    ]);

    return RequestDocument::create([
        'request_id' => $request->request_id, 'document_type_id' => $type->document_type_id,
        'number_of_copies' => 1, 'status_id' => $statusId,
    ]);
}

function ndnCertificate(DocumentRequest $request, int $statusId): RequestCertificate
{
    $access = AccessType::firstOrCreate(['access_id' => 1], ['access_name' => 'Student'])->access_id;

    $type = CertificationType::firstOrCreate(['certificate_name' => 'Certificate of Good Moral'], [
        'certificate_requirements'   => 'Valid ID',
        'certificate_process_period' => '3-5 business days',
        'access_id'                  => $access,
    ]);

    return RequestCertificate::create([
        'request_id' => $request->request_id, 'certificate_type_id' => $type->certificate_type_id,
        'number_of_copies' => 1, 'status_id' => $statusId,
    ]);
}

/** Request with two items (A, B) at the given item status; parent set to $parent. */
function ndnTwoItems(int $itemStatus = 1, int $parent = 1): array
{
    $request = DocumentRequest::factory()->create(['status_id' => $parent]);

    return [$request, ndnDocument($request, $itemStatus, 'Transcript of Records'), ndnDocument($request, $itemStatus, 'Diploma')];
}

function ndnNoticeOn(RequestDocument|RequestCertificate $item): RequestRemark
{
    return RequestRemark::factory()->create([
        'request_id'             => $item->request_id,
        'request_document_id'    => $item instanceof RequestDocument ? $item->request_document_id : null,
        'request_certificate_id' => $item instanceof RequestCertificate ? $item->request_certificate_id : null,
        'status'                 => RequestRemark::STATUS_OPEN,
    ]);
}

function ndnRequestNotice(DocumentRequest $request): RequestRemark
{
    return RequestRemark::factory()->create(['request_id' => $request->request_id, 'status' => RequestRemark::STATUS_OPEN]);
}

function ndnIssueUrl(DocumentRequest $r): string
{
    return "/api/document-requests/{$r->request_id}/deficiency-notices";
}

function ndnItemUrl(DocumentRequest $r, $item, string $action): string
{
    $seg = $item instanceof RequestDocument ? "documents/{$item->request_document_id}" : "certificates/{$item->request_certificate_id}";

    return "/api/document-requests/{$r->request_id}/{$seg}/{$action}";
}

function ndnClosePayload(): array
{
    $reason = collect(ClosureReasonEnum::cases())->first(fn ($c) => $c !== ClosureReasonEnum::Other);

    return ['closure_reason' => $reason->value, 'closure_proof_reference' => 'Verified on 2026-10-02'];
}

function ndnWithdrawPayload(): array
{
    $reason = collect(WithdrawalReasonEnum::cases())->first(fn ($c) => $c !== WithdrawalReasonEnum::Other);

    return ['withdrawal_reason' => $reason->value];
}

// ── Issue ────────────────────────────────────────────────────────────────────

test('staff can issue a notice on one document and it is audited', function () {
    ndnStaff();
    [$request, $a, $b] = ndnTwoItems();

    $res = $this->postJson(ndnIssueUrl($request), [
        'item_key'            => DeficiencyItemEnum::MissingSignature->value,
        'request_document_id' => $a->request_document_id,
    ])->assertCreated();

    expect($res->json('request_document_id'))->toBe($a->request_document_id)
        ->and($res->json('request_certificate_id'))->toBeNull()
        ->and($res->json('status'))->toBe('open');

    expect(AuditLog::where('action', AuditLog::ACTION_DEFICIENCY_NOTICE_ISSUED)->count())->toBe(1);

    // The request-level relation stays empty; the item-level one carries it.
    expect($request->fresh()->openDeficiencyNotice)->toBeNull()
        ->and($request->fresh()->openItemDeficiencyNotices)->toHaveCount(1);
});

test('staff can issue a notice on a certificate', function () {
    ndnStaff();
    $request = DocumentRequest::factory()->create(['status_id' => 1]);
    $cert    = ndnCertificate($request, 1);

    $this->postJson(ndnIssueUrl($request), [
        'item_key'               => DeficiencyItemEnum::MissingValidId->value,
        'request_certificate_id' => $cert->request_certificate_id,
    ])->assertCreated()->assertJsonPath('request_certificate_id', $cert->request_certificate_id);
});

test('each item may hold one open notice, and a request-level notice may coexist', function () {
    ndnStaff();
    [$request, $a, $b] = ndnTwoItems();

    $body = fn ($item) => [
        'item_key' => DeficiencyItemEnum::MissingSignature->value, 'request_document_id' => $item->request_document_id,
    ];

    $this->postJson(ndnIssueUrl($request), $body($a))->assertCreated();
    $this->postJson(ndnIssueUrl($request), $body($b))->assertCreated();
    $this->postJson(ndnIssueUrl($request), $body($a))->assertStatus(422);

    // Request-level scope is independent of the item scopes.
    $this->postJson(ndnIssueUrl($request), ['item_key' => DeficiencyItemEnum::MissingSignature->value])->assertCreated();
    $this->postJson(ndnIssueUrl($request), ['item_key' => DeficiencyItemEnum::MissingSignature->value])->assertStatus(422);

    expect(RequestRemark::where('request_id', $request->request_id)->open()->count())->toBe(3);
});

test('an existing request-level notice does not stop a notice on an item', function () {
    ndnStaff();
    [$request, $a] = ndnTwoItems();
    ndnRequestNotice($request);

    $this->postJson(ndnIssueUrl($request), [
        'item_key' => DeficiencyItemEnum::MissingSignature->value, 'request_document_id' => $a->request_document_id,
    ])->assertCreated();
});

test('an item from another request, an unknown item, or both ids are rejected', function () {
    ndnStaff();
    [$request, $a] = ndnTwoItems();
    [, $foreign]   = ndnTwoItems();
    $cert          = ndnCertificate($request, 1);

    $key = DeficiencyItemEnum::MissingSignature->value;

    $this->postJson(ndnIssueUrl($request), ['item_key' => $key, 'request_document_id' => $foreign->request_document_id])->assertStatus(422);
    $this->postJson(ndnIssueUrl($request), ['item_key' => $key, 'request_document_id' => 999999])->assertStatus(422);
    $this->postJson(ndnIssueUrl($request), [
        'item_key' => $key, 'request_document_id' => $a->request_document_id, 'request_certificate_id' => $cert->request_certificate_id,
    ])->assertStatus(422);

    expect(RequestRemark::count())->toBe(0);
});

test('a finished item or a finished request cannot receive a notice, but Ready to Claim can', function () {
    ndnStaff();
    $key = DeficiencyItemEnum::MissingSignature->value;

    $request = DocumentRequest::factory()->create(['status_id' => 1]);
    $done    = ndnDocument($request, RequestStatusEnum::Completed->value, 'Diploma');
    $ready   = ndnDocument($request, RequestStatusEnum::ReadyToClaim->value, 'Transcript of Records');

    $this->postJson(ndnIssueUrl($request), ['item_key' => $key, 'request_document_id' => $done->request_document_id])->assertStatus(422);
    $this->postJson(ndnIssueUrl($request), ['item_key' => $key, 'request_document_id' => $ready->request_document_id])->assertCreated();

    $closed = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::Completed->value]);
    $item   = ndnDocument($closed, RequestStatusEnum::Processing->value);
    $this->postJson(ndnIssueUrl($closed), ['item_key' => $key, 'request_document_id' => $item->request_document_id])->assertStatus(422);
});

test('a student cannot issue a notice on an item', function () {
    ndnStaff();
    [$request, $a] = ndnTwoItems();
    $student = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT, 'status' => 'Activated']);
    Sanctum::actingAs($student);

    $this->postJson(ndnIssueUrl($request), [
        'item_key' => DeficiencyItemEnum::MissingSignature->value, 'request_document_id' => $a->request_document_id,
    ])->assertStatus(403);
});

// ── A notice blocks only its own item ────────────────────────────────────────

test('lookup marks the held item and leaves the others claimable', function () {
    ndnStaff();
    [$request, $a, $b] = ndnTwoItems(RequestStatusEnum::ReadyToClaim->value, RequestStatusEnum::ReadyToClaim->value);
    $notice = ndnNoticeOn($a);

    $items = collect($this->postJson('/api/document-requests/claim/lookup', ['uuid' => $request->uuid])
        ->assertOk()->json('items'))->keyBy('uuid');

    expect($items[$a->uuid]['claimable'])->toBeFalse()
        ->and($items[$a->uuid]['on_hold'])->toBeTrue()
        ->and($items[$a->uuid]['deficiency_notice']['remark_id'])->toBe($notice->remark_id)
        ->and(strtolower($items[$a->uuid]['reason']))->toContain('on hold')
        ->and($items[$b->uuid]['claimable'])->toBeTrue()
        ->and($items[$b->uuid]['on_hold'])->toBeFalse()
        ->and($items[$b->uuid]['deficiency_notice'])->toBeNull();
});

test('confirm releases the other item and reports the held one as skipped', function () {
    ndnStaff();
    [$request, $a, $b] = ndnTwoItems(RequestStatusEnum::ReadyToClaim->value, RequestStatusEnum::ReadyToClaim->value);
    ndnNoticeOn($a);

    $res = $this->postJson('/api/document-requests/claim/confirm', [
        'uuid' => $request->uuid, 'item_uuids' => [$a->uuid, $b->uuid],
    ])->assertOk();

    expect($res->json('completed'))->toHaveCount(1)
        ->and($res->json('completed.0.uuid'))->toBe($b->uuid)
        ->and($res->json('skipped'))->toHaveCount(1)
        ->and($res->json('skipped.0.uuid'))->toBe($a->uuid)
        ->and(strtolower($res->json('skipped.0.skipped_reason')))->toContain('on hold');

    expect($a->fresh()->status_id)->toBe(RequestStatusEnum::ReadyToClaim->value)
        ->and($b->fresh()->status_id)->toBe(RequestStatusEnum::Completed->value);
});

test('confirming only the held item claims nothing', function () {
    ndnStaff();
    [$request, $a] = ndnTwoItems(RequestStatusEnum::ReadyToClaim->value, RequestStatusEnum::ReadyToClaim->value);
    ndnNoticeOn($a);

    $this->postJson('/api/document-requests/claim/confirm', ['claim_code' => $a->claim_code])->assertStatus(422);

    expect($a->fresh()->status_id)->toBe(RequestStatusEnum::ReadyToClaim->value);
});

test('clearing the notice makes the item claimable again', function () {
    ndnStaff();
    [$request, $a] = ndnTwoItems(RequestStatusEnum::ReadyToClaim->value, RequestStatusEnum::ReadyToClaim->value);
    $notice = ndnNoticeOn($a);

    $this->postJson('/api/document-requests/claim/confirm', ['claim_code' => $a->claim_code])->assertStatus(422);
    $this->postJson("/api/deficiency-notices/{$notice->remark_id}/clear")->assertOk();
    $this->postJson('/api/document-requests/claim/confirm', ['claim_code' => $a->claim_code])->assertOk();

    expect($a->fresh()->status_id)->toBe(RequestStatusEnum::Completed->value);
});

test('the whole-request QR claim is refused while any item is held', function () {
    ndnStaff();
    [$request, $a, $b] = ndnTwoItems(RequestStatusEnum::ReadyToClaim->value, RequestStatusEnum::ReadyToClaim->value);
    ndnNoticeOn($b);

    $this->postJson('/api/document-requests/claim', ['uuid' => $request->uuid])->assertStatus(422);

    expect($request->fresh()->status_id)->toBe(RequestStatusEnum::ReadyToClaim->value)
        ->and($a->fresh()->status_id)->toBe(RequestStatusEnum::ReadyToClaim->value)
        ->and($b->fresh()->status_id)->toBe(RequestStatusEnum::ReadyToClaim->value);
});

test('a request-level notice still does not block claiming', function () {
    ndnStaff();
    [$request, $a] = ndnTwoItems(RequestStatusEnum::ReadyToClaim->value, RequestStatusEnum::ReadyToClaim->value);
    ndnRequestNotice($request);

    $this->postJson('/api/document-requests/claim/confirm', ['claim_code' => $a->claim_code])->assertOk();
});

test('the manual Done button is refused on a held item but works on the other', function () {
    ndnStaff();
    [$request, $a, $b] = ndnTwoItems(RequestStatusEnum::ReadyToClaim->value, RequestStatusEnum::ReadyToClaim->value);
    ndnNoticeOn($a);

    $done = ['status_id' => RequestStatusEnum::Completed->value];

    $this->putJson("/api/document-requests/{$request->request_id}/documents/{$a->request_document_id}", $done)->assertStatus(422);
    $this->putJson("/api/document-requests/{$request->request_id}/documents/{$b->request_document_id}", $done)->assertOk();

    expect($a->fresh()->status_id)->toBe(RequestStatusEnum::ReadyToClaim->value);
});

test('bulk-done skips a held item and still completes the others', function () {
    ndnStaff();
    [$request, $a, $b] = ndnTwoItems(RequestStatusEnum::ReadyToClaim->value, RequestStatusEnum::ReadyToClaim->value);
    ndnNoticeOn($a);

    $res = $this->postJson('/api/document-requests/bulk-done', ['request_ids' => [$request->request_id]])->assertOk();

    expect(collect($res->json('items_skipped'))->pluck('reason')->all())->toContain('item_on_hold')
        ->and($a->fresh()->status_id)->toBe(RequestStatusEnum::ReadyToClaim->value)
        ->and($b->fresh()->status_id)->toBe(RequestStatusEnum::Completed->value);
});

test('the legacy release-group ticket is refused when one of its items is held', function () {
    ndnStaff();
    [$request, $a, $b] = ndnTwoItems(RequestStatusEnum::ReadyToClaim->value, RequestStatusEnum::ReadyToClaim->value);
    $group = \App\Models\RequestReleaseGroup::create([
        'request_id' => $request->request_id, 'status_id' => RequestStatusEnum::ReadyToClaim->value,
    ]);
    $a->update(['request_release_group_id' => $group->request_release_group_id]);
    $b->update(['request_release_group_id' => $group->request_release_group_id]);
    ndnNoticeOn($b);

    $this->postJson('/api/document-requests/claim', ['uuid' => $group->uuid])->assertStatus(422);

    expect($a->fresh()->status_id)->toBe(RequestStatusEnum::ReadyToClaim->value);
});

// ── Close needs a notice that covers THAT item ──────────────────────────────

test('closing an item needs a notice on it or on the request, not on a sibling', function () {
    ndnStaff();
    [$request, $a, $b] = ndnTwoItems();
    ndnNoticeOn($b);

    $this->postJson(ndnItemUrl($request, $a, 'close-unable-to-process'), ndnClosePayload())->assertStatus(422);
    expect($a->fresh()->status_id)->toBe(1);

    $this->postJson(ndnItemUrl($request, $b, 'close-unable-to-process'), ndnClosePayload())->assertOk();
    expect($b->fresh()->status_id)->toBe(RequestStatusEnum::ClosedUnableToProcess->value);
});

test('a request-level notice still unlocks closing an item and stays open', function () {
    ndnStaff();
    [$request, $a] = ndnTwoItems();
    $notice = ndnRequestNotice($request);

    $this->postJson(ndnItemUrl($request, $a, 'close-unable-to-process'), ndnClosePayload())->assertOk();

    expect($notice->fresh()->status)->toBe(RequestRemark::STATUS_OPEN);
});

test('closing or withdrawing an item voids only that items own notice', function () {
    ndnStaff();
    [$request, $a, $b] = ndnTwoItems();
    $onA = ndnNoticeOn($a);
    $onB = ndnNoticeOn($b);

    $res = $this->postJson(ndnItemUrl($request, $a, 'withdraw'), ndnWithdrawPayload())->assertOk();

    expect($res->json('auto_voided_deficiency_notice_ids'))->toBe([$onA->remark_id])
        ->and($onA->fresh()->status)->toBe(RequestRemark::STATUS_VOIDED)
        ->and($onB->fresh()->status)->toBe(RequestRemark::STATUS_OPEN)
        ->and(AuditLog::where('action', AuditLog::ACTION_DEFICIENCY_NOTICE_VOIDED)->count())->toBe(1);
});

test('when the last item leaves, every remaining open notice is voided', function () {
    ndnStaff();
    [$request, $a, $b] = ndnTwoItems();
    $onReq = ndnRequestNotice($request);
    $onB   = ndnNoticeOn($b);

    $this->postJson(ndnItemUrl($request, $a, 'withdraw'), ndnWithdrawPayload())->assertOk();
    $this->postJson(ndnItemUrl($request, $b, 'withdraw'), ndnWithdrawPayload())->assertOk();

    expect($request->fresh()->status_id)->toBe(RequestStatusEnum::Withdrawn->value)
        ->and($onReq->fresh()->status)->toBe(RequestRemark::STATUS_VOIDED)
        ->and($onB->fresh()->status)->toBe(RequestRemark::STATUS_VOIDED);
});

// ── Whole-request actions handle several notices ─────────────────────────────

test('withdrawing the request voids every open notice, item-level included', function () {
    ndnStaff();
    [$request, $a, $b] = ndnTwoItems();
    $one = ndnRequestNotice($request);
    $two = ndnNoticeOn($a);
    $three = ndnNoticeOn($b);

    $res = $this->postJson("/api/document-requests/{$request->request_id}/withdraw", ndnWithdrawPayload())->assertOk();

    expect($res->json('auto_voided_deficiency_notice_ids'))->toHaveCount(3)
        ->and(RequestRemark::where('request_id', $request->request_id)->open()->count())->toBe(0)
        ->and(AuditLog::where('action', AuditLog::ACTION_DEFICIENCY_NOTICE_VOIDED)->count())->toBe(3);
});

test('closing the request works with only an item-level notice and voids all of them', function () {
    ndnStaff();
    [$request, $a, $b] = ndnTwoItems();
    ndnNoticeOn($a);
    ndnNoticeOn($b);

    $res = $this->postJson("/api/document-requests/{$request->request_id}/close-unable-to-process", ndnClosePayload())->assertOk();

    expect($res->json('closed_deficiency_notice_ids'))->toHaveCount(2)
        ->and(RequestRemark::where('request_id', $request->request_id)->open()->count())->toBe(0);
});

test('closing the request with no notice at all is still refused', function () {
    ndnStaff();
    [$request] = ndnTwoItems();

    $this->postJson("/api/document-requests/{$request->request_id}/close-unable-to-process", ndnClosePayload())->assertStatus(422);
});

// ── Clear / void / read ──────────────────────────────────────────────────────

test('voiding one item notice leaves the others untouched', function () {
    ndnStaff();
    [$request, $a, $b] = ndnTwoItems();
    $onA = ndnNoticeOn($a);
    $onB = ndnNoticeOn($b);

    $this->postJson("/api/deficiency-notices/{$onA->remark_id}/void", ['void_reason' => 'Student unreachable'])
        ->assertOk()->assertJsonPath('request_document_id', $a->request_document_id);

    expect($onA->fresh()->status)->toBe(RequestRemark::STATUS_VOIDED)
        ->and($onB->fresh()->status)->toBe(RequestRemark::STATUS_OPEN);
});

test('after clearing, a new notice can be issued on the same item', function () {
    ndnStaff();
    [$request, $a] = ndnTwoItems();
    $first = ndnNoticeOn($a);

    $this->postJson("/api/deficiency-notices/{$first->remark_id}/clear")->assertOk();
    $this->postJson(ndnIssueUrl($request), [
        'item_key' => DeficiencyItemEnum::MissingSignature->value, 'request_document_id' => $a->request_document_id,
    ])->assertCreated();
});

test('show returns open item notices separately from the request-level notice', function () {
    ndnStaff();
    [$request, $a] = ndnTwoItems();
    ndnNoticeOn($a);

    $res = $this->getJson("/api/document-requests/{$request->request_id}")->assertOk();

    expect($res->json('open_deficiency_notice'))->toBeNull()
        ->and($res->json('open_item_deficiency_notices'))->toHaveCount(1)
        ->and($res->json('open_item_deficiency_notices.0.request_document_id'))->toBe($a->request_document_id);
});

test('an escalation-era request-level notice created without item ids still behaves as before', function () {
    ndnStaff();
    [$request] = ndnTwoItems();
    $notice = ndnRequestNotice($request);

    expect($notice->fresh()->isItemLevel())->toBeFalse()
        ->and($request->fresh()->openDeficiencyNotice?->remark_id)->toBe($notice->remark_id);
});
