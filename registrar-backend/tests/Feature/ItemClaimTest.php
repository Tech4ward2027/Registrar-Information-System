<?php

use App\Enums\RequestStatusEnum;
use App\Models\AccessType;
use App\Models\CertificationType;
use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Models\RequestCertificate;
use App\Models\RequestDocument;
use App\Models\RequestHistory;
use App\Models\SystemUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// ═════════════════════════════════════════════════════════════════════════════
// Phase 3 — per-item claiming (POST /document-requests/claim/lookup|confirm),
// item credentials + completed_at, and the per-document list
// (GET /document-requests/items).
// Helper names carry an "icl" prefix (Pest files share one function namespace).
// ═════════════════════════════════════════════════════════════════════════════

function iclStaff(): SystemUser
{
    $admin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN, 'status' => 'Activated']);
    grantFullDashboardAccess($admin);
    Sanctum::actingAs($admin);

    return $admin;
}

function iclAccessId(): int
{
    return AccessType::firstOrCreate(['access_id' => 1], ['access_name' => 'Student'])->access_id;
}

function iclDocument(DocumentRequest $request, int $statusId, string $name = 'Transcript of Records'): RequestDocument
{
    $type = DocumentType::firstOrCreate(['document_name' => $name], [
        'document_description'    => 'desc',
        'document_requirements'   => 'Valid ID',
        'document_process_period' => '3-5 business days',
        'access_id'               => iclAccessId(),
    ]);

    return RequestDocument::create([
        'request_id' => $request->request_id, 'document_type_id' => $type->document_type_id,
        'number_of_copies' => 1, 'status_id' => $statusId,
    ]);
}

function iclCertificate(DocumentRequest $request, int $statusId, string $name = 'Certificate of Good Moral'): RequestCertificate
{
    $type = CertificationType::firstOrCreate(['certificate_name' => $name], [
        'certificate_requirements'   => 'Valid ID',
        'certificate_process_period' => '3-5 business days',
        'access_id'                  => iclAccessId(),
    ]);

    return RequestCertificate::create([
        'request_id' => $request->request_id, 'certificate_type_id' => $type->certificate_type_id,
        'number_of_copies' => 1, 'status_id' => $statusId,
    ]);
}

/** Request with a Ready document (A) and a Processing document (B), parent Processing. */
function iclTwoItemRequest(): array
{
    $request = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::Processing->value]);
    $a = iclDocument($request, RequestStatusEnum::ReadyToClaim->value, 'Transcript of Records');
    $b = iclDocument($request, RequestStatusEnum::Processing->value, 'Diploma');

    return [$request, $a, $b];
}

// ── Credentials & completed_at ───────────────────────────────────────────────

test('every new item gets its own uuid and a 6-char claim code that is unique across tables', function () {
    $request = DocumentRequest::factory()->create();
    $doc  = iclDocument($request, RequestStatusEnum::Processing->value);
    $cert = iclCertificate($request, RequestStatusEnum::Processing->value);

    expect($doc->uuid)->toBeString()->toHaveLength(36)
        ->and($doc->claim_code)->toMatch('/^[2-9A-HJKMNP-TV-Z]{6}$/')
        ->and($cert->uuid)->not->toBe($doc->uuid)
        ->and($cert->claim_code)->not->toBe($doc->claim_code)
        ->and([$doc->claim_code, $cert->claim_code])->not->toContain($request->claim_code);
});

test('completed_at is stamped once, when an item becomes Completed', function () {
    $request = DocumentRequest::factory()->create();
    $doc = iclDocument($request, RequestStatusEnum::ReadyToClaim->value);
    expect($doc->fresh()->completed_at)->toBeNull();

    $doc->update(['status_id' => RequestStatusEnum::Completed->value]);
    $first = $doc->fresh()->completed_at;
    expect($first)->not->toBeNull();

    $this->travel(2)->hours();
    $doc->update(['number_of_copies' => 2]);
    expect($doc->fresh()->completed_at->equalTo($first))->toBeTrue();
});

// ── Lookup (read-only) ───────────────────────────────────────────────────────

test('lookup by an item code returns only that item and changes nothing', function () {
    iclStaff();
    [$request, $a, $b] = iclTwoItemRequest();

    $res = $this->postJson('/api/document-requests/claim/lookup', ['claim_code' => $a->claim_code])->assertOk();

    expect($res->json('matched'))->toBe('item')
        ->and($res->json('items'))->toHaveCount(1)
        ->and($res->json('items.0.uuid'))->toBe($a->uuid)
        ->and($res->json('items.0.claimable'))->toBeTrue()
        ->and($res->json('request.items_total'))->toBe(2)
        ->and($res->json('request.items_completed'))->toBe(0);

    expect($a->fresh()->status_id)->toBe(RequestStatusEnum::ReadyToClaim->value);
    expect(RequestHistory::where('request_id', $request->request_id)->count())->toBe(0);
});

test('lookup by the request code lists every item with whether it can be claimed', function () {
    iclStaff();
    [$request, $a, $b] = iclTwoItemRequest();

    $items = collect($this->postJson('/api/document-requests/claim/lookup', ['uuid' => $request->uuid])
        ->assertOk()->json('items'))->keyBy('uuid');

    expect($items[$a->uuid]['claimable'])->toBeTrue()
        ->and($items[$b->uuid]['claimable'])->toBeFalse()
        ->and($items[$b->uuid]['reason'])->toContain('not ready');
});

test('an unknown code is a generic 404 and an archived request cannot be looked up', function () {
    iclStaff();
    [$request, $a] = iclTwoItemRequest();

    $this->postJson('/api/document-requests/claim/lookup', ['claim_code' => 'ZZZZZZ'])->assertNotFound();

    $request->update(['is_archived' => true]);
    $this->postJson('/api/document-requests/claim/lookup', ['claim_code' => $a->claim_code])->assertNotFound();
});

// ── Confirm ──────────────────────────────────────────────────────────────────

test('confirming one Ready item completes only that item and leaves the parent Processing', function () {
    iclStaff();
    [$request, $a, $b] = iclTwoItemRequest();

    $res = $this->postJson('/api/document-requests/claim/confirm', ['uuid' => $a->uuid])->assertOk();

    expect($a->fresh()->status_id)->toBe(RequestStatusEnum::Completed->value)
        ->and($a->fresh()->completed_at)->not->toBeNull()
        ->and($b->fresh()->status_id)->toBe(RequestStatusEnum::Processing->value)
        ->and($request->fresh()->status_id)->toBe(RequestStatusEnum::Processing->value)
        ->and($res->json('completed'))->toHaveCount(1)
        ->and($res->json('request.items_completed'))->toBe(1)
        ->and($res->json('request.items_total'))->toBe(2);

    $history = RequestHistory::where('request_document_id', $a->request_document_id)->get();
    expect($history)->toHaveCount(1)
        ->and($history[0]->new_status_id)->toBe(RequestStatusEnum::Completed->value);
});

test('claiming the last item completes the parent exactly once', function () {
    iclStaff();
    $request = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::ReadyToClaim->value]);
    $a = iclDocument($request, RequestStatusEnum::Completed->value, 'Transcript of Records');
    $b = iclDocument($request, RequestStatusEnum::ReadyToClaim->value, 'Diploma');

    $this->postJson('/api/document-requests/claim/confirm', ['uuid' => $b->uuid])->assertOk();

    expect($request->fresh()->status_id)->toBe(RequestStatusEnum::Completed->value);
    expect(RequestHistory::where('request_id', $request->request_id)->whereNull('request_document_id')
        ->where('new_status_id', RequestStatusEnum::Completed->value)->count())->toBe(1);
});

test('confirming from the request ticket releases only the selected items, and reports skipped ones', function () {
    iclStaff();
    $request = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::Processing->value]);
    $a = iclDocument($request, RequestStatusEnum::ReadyToClaim->value, 'Transcript of Records');
    $c = iclCertificate($request, RequestStatusEnum::ReadyToClaim->value);
    $b = iclDocument($request, RequestStatusEnum::Processing->value, 'Diploma');

    $res = $this->postJson('/api/document-requests/claim/confirm', [
        'uuid'       => $request->uuid,
        'item_uuids' => [$a->uuid, $b->uuid],
    ])->assertOk();

    expect($a->fresh()->status_id)->toBe(RequestStatusEnum::Completed->value)
        ->and($b->fresh()->status_id)->toBe(RequestStatusEnum::Processing->value)
        ->and($c->fresh()->status_id)->toBe(RequestStatusEnum::ReadyToClaim->value)   // not selected: untouched
        ->and($res->json('completed'))->toHaveCount(1)
        ->and($res->json('skipped'))->toHaveCount(1)
        ->and($res->json('skipped.0.uuid'))->toBe($b->uuid);
});

test('a second confirm for the same item is refused and writes nothing more', function () {
    iclStaff();
    [$request, $a] = iclTwoItemRequest();

    $this->postJson('/api/document-requests/claim/confirm', ['uuid' => $a->uuid])->assertOk();
    $before = RequestHistory::where('request_id', $request->request_id)->count();

    $this->postJson('/api/document-requests/claim/confirm', ['uuid' => $a->uuid])->assertStatus(422);

    expect(RequestHistory::where('request_id', $request->request_id)->count())->toBe($before);
});

test('an item that is not Ready cannot be claimed', function () {
    iclStaff();
    [$request, $a, $b] = iclTwoItemRequest();

    $this->postJson('/api/document-requests/claim/confirm', ['uuid' => $b->uuid])->assertStatus(422);

    expect($b->fresh()->status_id)->toBe(RequestStatusEnum::Processing->value);
});

test('an item uuid from a different request is rejected', function () {
    iclStaff();
    [$request] = iclTwoItemRequest();
    $other = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::ReadyToClaim->value]);
    $foreign = iclDocument($other, RequestStatusEnum::ReadyToClaim->value, 'Foreign Doc');

    $this->postJson('/api/document-requests/claim/confirm', [
        'uuid' => $request->uuid, 'item_uuids' => [$foreign->uuid],
    ])->assertStatus(422);

    expect($foreign->fresh()->status_id)->toBe(RequestStatusEnum::ReadyToClaim->value);
});

test('the request ticket needs an explicit selection', function () {
    iclStaff();
    [$request] = iclTwoItemRequest();

    $this->postJson('/api/document-requests/claim/confirm', ['uuid' => $request->uuid])->assertStatus(422);
});

test('claiming is refused on a terminal request and on an archived request', function () {
    iclStaff();

    $withdrawn = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::Withdrawn->value]);
    $stale = iclDocument($withdrawn, RequestStatusEnum::ReadyToClaim->value, 'Stale Doc');
    $this->postJson('/api/document-requests/claim/confirm', ['uuid' => $stale->uuid])->assertStatus(422);
    expect($stale->fresh()->status_id)->toBe(RequestStatusEnum::ReadyToClaim->value);

    [$archived, $a] = iclTwoItemRequest();
    $archived->update(['is_archived' => true]);
    $this->postJson('/api/document-requests/claim/confirm', ['uuid' => $a->uuid])->assertNotFound();
});

test('a user without staff access cannot look up or confirm', function () {
    [$request, $a] = iclTwoItemRequest();
    $student = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT, 'status' => 'Activated']);
    Sanctum::actingAs($student);

    $this->postJson('/api/document-requests/claim/lookup', ['uuid' => $a->uuid])->assertForbidden();
    $this->postJson('/api/document-requests/claim/confirm', ['uuid' => $a->uuid])->assertForbidden();
});

// ── Per-document list (GET /document-requests/items) ─────────────────────────

test('the items list returns one row per document and certificate, with totals that match', function () {
    iclStaff();
    $request = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::Processing->value]);
    iclDocument($request, RequestStatusEnum::ReadyToClaim->value, 'Transcript of Records');
    iclDocument($request, RequestStatusEnum::Processing->value, 'Diploma');
    iclCertificate($request, RequestStatusEnum::Processing->value);

    $res = $this->getJson('/api/document-requests/items?per_page=2')->assertOk();

    expect($res->json('total'))->toBe(3)
        ->and($res->json('last_page'))->toBe(2)
        ->and($res->json('data'))->toHaveCount(2)
        ->and($res->json('data.0.request.request_id'))->toBe($request->request_id)
        ->and($res->json('data.0.request.items_total'))->toBe(3);
});

test('searching a document name returns only that document, not its siblings', function () {
    iclStaff();
    $request = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::Processing->value]);
    iclDocument($request, RequestStatusEnum::Processing->value, 'Transcript of Records');
    iclDocument($request, RequestStatusEnum::Processing->value, 'Diploma');

    $rows = $this->getJson('/api/document-requests/items?search=' . urlencode('Diploma'))->assertOk()->json('data');

    expect($rows)->toHaveCount(1)->and($rows[0]['name'])->toBe('Diploma');
});

test('searching a requester returns all of their documents', function () {
    iclStaff();
    $request = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::Processing->value]);
    iclDocument($request, RequestStatusEnum::Processing->value, 'Transcript of Records');
    iclDocument($request, RequestStatusEnum::Processing->value, 'Diploma');

    $this->getJson('/api/document-requests/items?search=' . $request->claim_code)
        ->assertOk()->assertJsonCount(2, 'data');
});

test('the default window hides documents completed over 24h ago, judged per document', function () {
    iclStaff();
    $request = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::Processing->value]);
    $fresh = iclDocument($request, RequestStatusEnum::Completed->value, 'Fresh Doc');
    $old   = iclDocument($request, RequestStatusEnum::Completed->value, 'Old Doc');
    $open  = iclDocument($request, RequestStatusEnum::Processing->value, 'Open Doc');
    RequestDocument::whereKey($old->request_document_id)->update(['completed_at' => now()->subDays(3)]);

    $names = collect($this->getJson('/api/document-requests/items')->assertOk()->json('data'))->pluck('name')->all();

    expect($names)->toContain('Fresh Doc', 'Open Doc')->not->toContain('Old Doc');

    $all = collect($this->getJson('/api/document-requests/items?all_statuses=true')->json('data'))->pluck('name')->all();
    expect($all)->toContain('Old Doc');
});

test('finished documents sort last, and status and document filters apply to the item', function () {
    iclStaff();
    $request = DocumentRequest::factory()->create(['status_id' => RequestStatusEnum::Processing->value]);
    $done = iclDocument($request, RequestStatusEnum::Completed->value, 'Done Doc');
    $ready = iclDocument($request, RequestStatusEnum::ReadyToClaim->value, 'Ready Doc');

    $order = collect($this->getJson('/api/document-requests/items?sort=' . urlencode('Recent Requests'))->json('data'))->pluck('name')->all();
    expect($order)->toBe(['Ready Doc', 'Done Doc']);

    $filtered = $this->getJson('/api/document-requests/items?status=' . urlencode('Ready to Claim'))->json('data');
    expect($filtered)->toHaveCount(1)->and($filtered[0]['uuid'])->toBe($ready->uuid);
});

test('the items list rejects bad params and does not leak undergrad PII', function () {
    iclStaff();

    $this->getJson('/api/document-requests/items?sort=' . urlencode('x; DROP TABLE y'))->assertStatus(422);
    $this->getJson('/api/document-requests/items?per_page=500')->assertStatus(422);

    $profile = \App\Models\UndergradRequestorProfile::factory()->create([
        'phone' => '09171234567', 'present_address' => '12 Confidential Street',
    ]);
    $request = DocumentRequest::factory()->create(['user_id' => $profile->user_id, 'status_id' => RequestStatusEnum::Processing->value]);
    iclDocument($request, RequestStatusEnum::Processing->value);

    $body = $this->getJson('/api/document-requests/items')->assertOk()->getContent();
    expect($body)->not->toContain('09171234567')->not->toContain('Confidential Street');
});

test('only staff can use the items list', function () {
    $student = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT, 'status' => 'Activated']);
    Sanctum::actingAs($student);

    $this->getJson('/api/document-requests/items')->assertForbidden();
});
