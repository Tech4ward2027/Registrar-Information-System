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
use App\Models\Notification;
use App\Models\RequestCertificate;
use App\Models\RequestDocument;
use App\Models\RequestHistory;
use App\Models\RequestItemTermination;
use App\Models\RequestRemark;
use App\Models\RequestStatus;
use App\Models\SystemUser;
use App\Services\RequestAggregateStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// ═════════════════════════════════════════════════════════════════════════════
// Phase 4 - per-item Withdraw and Close - Unable to Process, the aggregate
// rule they rely on, and the whole-request cascade.
// Helper names carry an "itm" prefix (Pest files share one function namespace).
// ═════════════════════════════════════════════════════════════════════════════

function itmSeedStatuses(): void
{
    foreach ([
        1 => 'Processing', 2 => 'Ready to Claim', 3 => 'Completed', 4 => 'Forfeited',
        6 => 'Pending Signature', 12 => 'Awaiting Submission', 13 => 'Withdrawn',
        14 => 'Closed - Unable to Process',
    ] as $id => $name) {
        RequestStatus::firstOrCreate(['status_id' => $id], ['status_name' => $name]);
    }
}

function itmStaff(): SystemUser
{
    itmSeedStatuses();
    $admin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN, 'status' => 'Activated']);
    grantFullDashboardAccess($admin);
    Sanctum::actingAs($admin);

    return $admin;
}

function itmAccessId(): int
{
    return AccessType::firstOrCreate(['access_id' => 1], ['access_name' => 'Student'])->access_id;
}

function itmDocument(DocumentRequest $request, int $statusId, string $name = 'Transcript of Records'): RequestDocument
{
    $type = DocumentType::firstOrCreate(['document_name' => $name], [
        'document_description'    => 'desc',
        'document_requirements'   => 'Valid ID',
        'document_process_period' => '3-5 business days',
        'access_id'               => itmAccessId(),
    ]);

    return RequestDocument::create([
        'request_id' => $request->request_id, 'document_type_id' => $type->document_type_id,
        'number_of_copies' => 1, 'status_id' => $statusId,
    ]);
}

function itmCertificate(DocumentRequest $request, int $statusId, string $name = 'Certificate of Good Moral'): RequestCertificate
{
    $type = CertificationType::firstOrCreate(['certificate_name' => $name], [
        'certificate_requirements'   => 'Valid ID',
        'certificate_process_period' => '3-5 business days',
        'access_id'                  => itmAccessId(),
    ]);

    return RequestCertificate::create([
        'request_id' => $request->request_id, 'certificate_type_id' => $type->certificate_type_id,
        'number_of_copies' => 1, 'status_id' => $statusId,
    ]);
}

/** Processing request with two Processing documents (A, B). */
function itmTwoProcessing(?int $ownerId = null): array
{
    $request = DocumentRequest::factory()->create(array_filter([
        'status_id' => RequestStatusEnum::Processing->value,
        'user_id'   => $ownerId,
    ]));

    return [$request, itmDocument($request, 1, 'Transcript of Records'), itmDocument($request, 1, 'Diploma')];
}

function itmOpenNotice(DocumentRequest $request): RequestRemark
{
    return RequestRemark::factory()->create([
        'request_id' => $request->request_id,
        'item_key'   => DeficiencyItemEnum::MissingSignature->value,
        'status'     => RequestRemark::STATUS_OPEN,
    ]);
}

function itmClosePayload(): array
{
    $reason = collect(ClosureReasonEnum::cases())->first(fn ($c) => $c !== ClosureReasonEnum::Other);

    return ['closure_reason' => $reason->value, 'closure_proof_reference' => 'Death certificate verified on 2026-10-01'];
}

function itmWithdrawUrl(DocumentRequest $r, $item): string
{
    $seg = $item instanceof RequestDocument ? "documents/{$item->request_document_id}" : "certificates/{$item->request_certificate_id}";

    return "/api/document-requests/{$r->request_id}/{$seg}/withdraw";
}

function itmCloseUrl(DocumentRequest $r, $item): string
{
    $seg = $item instanceof RequestDocument ? "documents/{$item->request_document_id}" : "certificates/{$item->request_certificate_id}";

    return "/api/document-requests/{$r->request_id}/{$seg}/close-unable-to-process";
}

// ── The aggregate rule (pure) ────────────────────────────────────────────────

test('aggregate: withdrawn and closed items leave the request', function (array $in, int $expected) {
    expect(RequestAggregateStatus::resolve($in))->toBe($expected);
})->with([
    'completed + withdrawn'   => [[3, 13], 3],
    'ready + withdrawn'       => [[2, 13], 2],
    'processing + closed'     => [[1, 14], 1],
    'processing + ready'      => [[1, 2], 1],
    'all withdrawn'           => [[13, 13], 13],
    'withdrawn + closed'      => [[13, 14], 14],
    'all closed'              => [[14, 14], 14],
]);

test('aggregate: no items decides nothing', function () {
    expect(RequestAggregateStatus::resolve([]))->toBeNull()
        ->and(RequestAggregateStatus::resolve([null]))->toBeNull();
});

// ── Withdraw one item ────────────────────────────────────────────────────────

test('withdrawing one item leaves the others and the parent untouched', function () {
    $staff = itmStaff();
    [$request, $a, $b] = itmTwoProcessing();
    $orBefore = $request->fresh()->or_number;

    $res = $this->postJson(itmWithdrawUrl($request, $a), [
        'withdrawal_reason' => WithdrawalReasonEnum::WrongItemPaid->value,
    ])->assertOk();

    expect($a->fresh()->status_id)->toBe(RequestStatusEnum::Withdrawn->value)
        ->and($b->fresh()->status_id)->toBe(RequestStatusEnum::Processing->value)
        ->and($request->fresh()->status_id)->toBe(RequestStatusEnum::Processing->value)
        ->and($request->fresh()->or_number)->toBe($orBefore)
        ->and($res->json('request_left'))->toBeFalse()
        ->and($res->json('item.termination'))->not->toHaveKey('proof_reference');

    $t = RequestItemTermination::where('request_document_id', $a->request_document_id)->first();
    expect($t->kind)->toBe('withdrawn')
        ->and($t->reason)->toBe(WithdrawalReasonEnum::WrongItemPaid->value)
        ->and($t->acted_by)->toBe($staff->user_id)
        ->and($t->cascaded)->toBeFalse();

    expect(RequestHistory::where('request_document_id', $a->request_document_id)
        ->where('new_status_id', 13)->exists())->toBeTrue();

    expect(AuditLog::where('action', AuditLog::ACTION_ITEM_WITHDRAWN)->exists())->toBeTrue();
});

test('a certificate can be withdrawn the same way', function () {
    itmStaff();
    $request = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::Processing->value]);
    $doc  = itmDocument($request, 1);
    $cert = itmCertificate($request, 1);

    $this->postJson(itmWithdrawUrl($request, $cert), [
        'withdrawal_reason' => WithdrawalReasonEnum::WrongItemPaid->value,
    ])->assertOk();

    expect($cert->fresh()->status_id)->toBe(13)
        ->and($doc->fresh()->status_id)->toBe(1)
        ->and(RequestItemTermination::where('request_certificate_id', $cert->request_certificate_id)->exists())->toBeTrue();
});

test('withdraw needs a reason, and "other" needs a detail', function () {
    itmStaff();
    [$request, $a] = itmTwoProcessing();

    $this->postJson(itmWithdrawUrl($request, $a), [])->assertStatus(422);
    $this->postJson(itmWithdrawUrl($request, $a), ['withdrawal_reason' => WithdrawalReasonEnum::Other->value])
        ->assertStatus(422);

    expect($a->fresh()->status_id)->toBe(1);
});

test('a Ready, Completed or already withdrawn item cannot be withdrawn', function (int $status) {
    itmStaff();
    $request = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::Processing->value]);
    $item  = itmDocument($request, $status, 'Diploma');
    itmDocument($request, 1, 'Transcript of Records'); // keeps the parent alive

    $this->postJson(itmWithdrawUrl($request, $item), [
        'withdrawal_reason' => WithdrawalReasonEnum::WrongItemPaid->value,
    ])->assertStatus(422);

    expect($item->fresh()->status_id)->toBe($status)
        ->and(RequestItemTermination::count())->toBe(0);
})->with([2, 3, 13]);

test('a request that already reached a final status refuses item withdrawal', function () {
    itmStaff();
    $request = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::Completed->value]);
    $item = itmDocument($request, 1);

    $this->postJson(itmWithdrawUrl($request, $item), [
        'withdrawal_reason' => WithdrawalReasonEnum::WrongItemPaid->value,
    ])->assertStatus(422);
});

test('an item of another request is a 404', function () {
    itmStaff();
    [$request] = itmTwoProcessing();
    [, $foreign] = itmTwoProcessing();

    $this->postJson(itmWithdrawUrl($request, $foreign), [
        'withdrawal_reason' => WithdrawalReasonEnum::WrongItemPaid->value,
    ])->assertNotFound();
});

test('a student cannot withdraw an item', function () {
    itmSeedStatuses();
    Sanctum::actingAs(SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT, 'status' => 'Activated']));
    [$request, $a] = itmTwoProcessing();

    $this->postJson(itmWithdrawUrl($request, $a), [
        'withdrawal_reason' => WithdrawalReasonEnum::WrongItemPaid->value,
    ])->assertForbidden();
});

test('the plain item status endpoint cannot withdraw an item without a reason', function () {
    itmStaff();
    [$request, $a] = itmTwoProcessing();

    $this->putJson("/api/document-requests/{$request->request_id}/documents/{$a->request_document_id}", [
        'status_id' => RequestStatusEnum::Withdrawn->value,
    ])->assertStatus(422);

    expect($a->fresh()->status_id)->toBe(1);
});

// ── Parent status after an item leaves ───────────────────────────────────────

test('withdrawing the only unfinished item lets the parent advance', function () {
    itmStaff();
    $request = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::Processing->value]);
    $ready = itmDocument($request, 2, 'Transcript of Records');
    $slow  = itmDocument($request, 1, 'Diploma');

    $this->postJson(itmWithdrawUrl($request, $slow), [
        'withdrawal_reason' => WithdrawalReasonEnum::WrongItemPaid->value,
    ])->assertOk();

    expect($request->fresh()->status_id)->toBe(RequestStatusEnum::ReadyToClaim->value)
        ->and($ready->fresh()->status_id)->toBe(2);
});

test('withdrawing the last item withdraws the request, copies the reason and sends one notification', function () {
    itmStaff();
    $owner = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT]);
    [$request, $a, $b] = itmTwoProcessing($owner->user_id);
    $notice = itmOpenNotice($request);

    $body = ['withdrawal_reason' => WithdrawalReasonEnum::WrongItemPaid->value];
    $this->postJson(itmWithdrawUrl($request, $a), $body)->assertOk()->assertJsonPath('request_left', false);
    $res = $this->postJson(itmWithdrawUrl($request, $b), $body)->assertOk();

    $fresh = $request->fresh();
    expect($res->json('request_left'))->toBeTrue()
        ->and($fresh->status_id)->toBe(RequestStatusEnum::Withdrawn->value)
        ->and($fresh->withdrawal_reason)->toBe(WithdrawalReasonEnum::WrongItemPaid->value)
        ->and($notice->fresh()->status)->toBe(RequestRemark::STATUS_VOIDED)
        ->and($res->json('auto_voided_deficiency_notice_id'))->toBe($notice->remark_id);

    $messages = Notification::where('notifiable_id', $owner->user_id)
        ->where('request_id', $request->request_id)->get()
        ->map(fn ($n) => $n->data['message'] ?? '');

    // one per-item message for A, one request-level message for B: never two for B
    expect($messages)->toHaveCount(2)
        ->and($messages->filter(fn ($m) => str_contains($m, 'Wrong item was paid for'))->count())->toBe(2);
});

test('a completed item plus a withdrawn item completes the request', function () {
    itmStaff();
    $request = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::Processing->value]);
    itmDocument($request, 3, 'Transcript of Records');
    $slow = itmDocument($request, 1, 'Diploma');

    $this->postJson(itmWithdrawUrl($request, $slow), [
        'withdrawal_reason' => WithdrawalReasonEnum::WrongItemPaid->value,
    ])->assertOk();

    expect($request->fresh()->status_id)->toBe(RequestStatusEnum::Completed->value);
});

test('the remaining ready item can still be claimed after the other was withdrawn', function () {
    itmStaff();
    $request = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::Processing->value]);
    $ready = itmDocument($request, 2, 'Transcript of Records');
    $slow  = itmDocument($request, 1, 'Diploma');

    $this->postJson(itmWithdrawUrl($request, $slow), [
        'withdrawal_reason' => WithdrawalReasonEnum::WrongItemPaid->value,
    ])->assertOk();

    $this->postJson('/api/document-requests/claim', ['uuid' => $request->fresh()->uuid])->assertOk();

    expect($ready->fresh()->status_id)->toBe(RequestStatusEnum::Completed->value)
        ->and($slow->fresh()->status_id)->toBe(RequestStatusEnum::Withdrawn->value)
        ->and($request->fresh()->status_id)->toBe(RequestStatusEnum::Completed->value);
});

// ── Close one item ───────────────────────────────────────────────────────────

test('closing an item needs an open deficiency notice', function () {
    itmStaff();
    [$request, $a] = itmTwoProcessing();

    $this->postJson(itmCloseUrl($request, $a), itmClosePayload())->assertStatus(422);

    expect($a->fresh()->status_id)->toBe(1);
});

test('closing an item needs a proof reference', function () {
    itmStaff();
    [$request, $a] = itmTwoProcessing();
    itmOpenNotice($request);

    $payload = itmClosePayload();
    unset($payload['closure_proof_reference']);

    $this->postJson(itmCloseUrl($request, $a), $payload)->assertStatus(422);
});

test('closing one item keeps the request and its notice alive and stores the proof privately', function () {
    itmStaff();
    [$request, $a, $b] = itmTwoProcessing();
    $notice = itmOpenNotice($request);

    $res = $this->postJson(itmCloseUrl($request, $a), itmClosePayload())->assertOk();

    expect($a->fresh()->status_id)->toBe(RequestStatusEnum::ClosedUnableToProcess->value)
        ->and($b->fresh()->status_id)->toBe(1)
        ->and($request->fresh()->status_id)->toBe(1)
        ->and($notice->fresh()->status)->toBe(RequestRemark::STATUS_OPEN)
        ->and($res->json('item.termination'))->not->toHaveKey('proof_reference');

    expect(RequestItemTermination::where('request_document_id', $a->request_document_id)->value('proof_reference'))
        ->toBe('Death certificate verified on 2026-10-01');
    expect(AuditLog::where('action', AuditLog::ACTION_ITEM_CLOSED_UNABLE_TO_PROCESS)->exists())->toBeTrue();
});

test('closing the last remaining item closes the request and voids the notice', function () {
    itmStaff();
    [$request, $a, $b] = itmTwoProcessing();
    $notice = itmOpenNotice($request);

    $this->postJson(itmWithdrawUrl($request, $a), ['withdrawal_reason' => WithdrawalReasonEnum::WrongItemPaid->value])->assertOk();
    $res = $this->postJson(itmCloseUrl($request, $b), itmClosePayload())->assertOk();

    $fresh = $request->fresh();
    // Withdrawn + Closed -> Closed wins
    expect($res->json('request_left'))->toBeTrue()
        ->and($fresh->status_id)->toBe(RequestStatusEnum::ClosedUnableToProcess->value)
        ->and($fresh->closure_proof_reference)->toBe('Death certificate verified on 2026-10-01')
        ->and($notice->fresh()->status)->toBe(RequestRemark::STATUS_VOIDED);
});

// ── Whole-request actions cascade to the items ───────────────────────────────

test('withdrawing a whole request withdraws its open items and records why on each', function () {
    itmStaff();
    [$request, $a, $b] = itmTwoProcessing();
    $cert = itmCertificate($request, 1);

    $this->postJson("/api/document-requests/{$request->request_id}/withdraw", [
        'withdrawal_reason' => WithdrawalReasonEnum::WrongItemPaid->value,
    ])->assertOk();

    expect($a->fresh()->status_id)->toBe(13)
        ->and($b->fresh()->status_id)->toBe(13)
        ->and($cert->fresh()->status_id)->toBe(13)
        ->and(RequestItemTermination::where('request_id', $request->request_id)->where('cascaded', true)->count())->toBe(3);
});

test('a whole-request withdraw is refused while an item is already Ready to Claim', function () {
    itmStaff();
    $request = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::Processing->value]);
    $ready = itmDocument($request, 2, 'Transcript of Records');
    $slow  = itmDocument($request, 1, 'Diploma');

    $this->postJson("/api/document-requests/{$request->request_id}/withdraw", [
        'withdrawal_reason' => WithdrawalReasonEnum::WrongItemPaid->value,
    ])->assertStatus(422);

    // the refusal rolled everything back
    expect($request->fresh()->status_id)->toBe(RequestStatusEnum::Processing->value)
        ->and($slow->fresh()->status_id)->toBe(1)
        ->and($ready->fresh()->status_id)->toBe(2)
        ->and(RequestItemTermination::count())->toBe(0);
});

test('closing a whole request closes its open items', function () {
    itmStaff();
    [$request, $a, $b] = itmTwoProcessing();
    itmOpenNotice($request);

    $this->postJson("/api/document-requests/{$request->request_id}/close-unable-to-process", itmClosePayload())->assertOk();

    expect($a->fresh()->status_id)->toBe(14)
        ->and($b->fresh()->status_id)->toBe(14)
        ->and($request->fresh()->status_id)->toBe(RequestStatusEnum::ClosedUnableToProcess->value);
});

test('a request with an item that already left is still withdrawable as a whole', function () {
    itmStaff();
    [$request, $a, $b] = itmTwoProcessing();

    $this->postJson(itmWithdrawUrl($request, $a), ['withdrawal_reason' => WithdrawalReasonEnum::WrongItemPaid->value])->assertOk();
    $this->postJson("/api/document-requests/{$request->request_id}/withdraw", [
        'withdrawal_reason' => WithdrawalReasonEnum::WrongItemPaid->value,
    ])->assertOk();

    expect($b->fresh()->status_id)->toBe(13)
        ->and(RequestItemTermination::where('request_id', $request->request_id)->count())->toBe(2);
});
