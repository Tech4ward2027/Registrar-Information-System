<?php

use App\Models\AccessType;
use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Models\RequestDocument;
use App\Models\RequestStatus;
use App\Models\SystemUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Row-level access on /request-documents, and public_id addressing of
| /document-requests/{documentRequest}.
|--------------------------------------------------------------------------
| Place at tests/Feature/RequestAccessControlTest.php.
*/

function racSeedStatuses(): void
{
    foreach ([1 => 'Processing', 2 => 'Ready to Claim', 3 => 'Completed'] as $id => $name) {
        RequestStatus::firstOrCreate(['status_id' => $id], ['status_name' => $name]);
    }
}

function racLineItem(DocumentRequest $request): RequestDocument
{
    $access = AccessType::firstOrCreate(['access_id' => 1], ['access_name' => 'Student'])->access_id;

    $type = DocumentType::firstOrCreate(['document_name' => 'Transcript of Records'], [
        'document_description'    => 'desc',
        'document_requirements'   => 'Valid ID',
        'document_process_period' => '3-5 business days',
        'access_id'               => $access,
    ]);

    return RequestDocument::create([
        'request_id'       => $request->request_id,
        'document_type_id' => $type->document_type_id,
        'number_of_copies' => 1,
        'status_id'        => 1,
    ]);
}

function racStudent(): SystemUser
{
    return SystemUser::factory()->create([
        'role_id' => SystemUser::ROLE_STUDENT,
        'status'  => 'Activated',
    ]);
}

function racAdmin(): SystemUser
{
    $admin = SystemUser::factory()->create([
        'role_id' => SystemUser::ROLE_ADMIN,
        'status'  => 'Activated',
    ]);

    return grantFullDashboardAccess($admin);
}

// ── /request-documents ────────────────────────────────────────────────

it('lets a student list only the line items of their own requests', function () {
    racSeedStatuses();

    $me    = racStudent();
    $other = racStudent();

    $mine   = DocumentRequest::factory()->create(['user_id' => $me->user_id]);
    $theirs = DocumentRequest::factory()->create(['user_id' => $other->user_id]);

    $myItem    = racLineItem($mine);
    $theirItem = racLineItem($theirs);

    Sanctum::actingAs($me);

    $ids = collect($this->getJson('/api/v1/request-documents')->assertOk()->json('data'))
        ->pluck('request_document_id');

    expect($ids)->toContain($myItem->request_document_id)
        ->not->toContain($theirItem->request_document_id);
});

it('returns 404 when a student reads another student\'s line item', function () {
    racSeedStatuses();

    $me    = racStudent();
    $other = racStudent();

    $item = racLineItem(DocumentRequest::factory()->create(['user_id' => $other->user_id]));

    Sanctum::actingAs($me);

    $this->getJson("/api/v1/request-documents/{$item->request_document_id}")->assertNotFound();
});

it('lets a student read their own line item', function () {
    racSeedStatuses();

    $me   = racStudent();
    $item = racLineItem(DocumentRequest::factory()->create(['user_id' => $me->user_id]));

    Sanctum::actingAs($me);

    $this->getJson("/api/v1/request-documents/{$item->request_document_id}")
        ->assertOk()
        ->assertJsonPath('request_document_id', $item->request_document_id);
});

it('lets staff with dashboard access read any line item', function () {
    racSeedStatuses();

    $item = racLineItem(DocumentRequest::factory()->create());

    Sanctum::actingAs(racAdmin());

    $this->getJson("/api/v1/request-documents/{$item->request_document_id}")->assertOk();
    $this->getJson('/api/v1/request-documents')->assertOk();
});

it('caps the page size on the line-item list', function () {
    racSeedStatuses();

    Sanctum::actingAs(racAdmin());

    $this->getJson('/api/v1/request-documents?per_page=100000')
        ->assertOk()
        ->assertJsonPath('per_page', 100);
});

// ── public_id addressing of /document-requests/{documentRequest} ───────────

it('resolves a document request by public_id', function () {
    racSeedStatuses();

    $me      = racStudent();
    $request = DocumentRequest::factory()->create(['user_id' => $me->user_id]);

    Sanctum::actingAs($me);

    $this->getJson("/api/v1/document-requests/{$request->public_id}")
        ->assertOk()
        ->assertJsonPath('public_id', $request->public_id);
});

it('still resolves the legacy numeric id while the compatibility flag is on', function () {
    racSeedStatuses();
    config(['public_ids.accept_legacy_numeric_request_ids' => true]);

    $me      = racStudent();
    $request = DocumentRequest::factory()->create(['user_id' => $me->user_id]);

    Sanctum::actingAs($me);

    $this->getJson("/api/v1/document-requests/{$request->request_id}")->assertOk();
});

it('rejects the numeric id once the compatibility flag is off', function () {
    racSeedStatuses();
    config(['public_ids.accept_legacy_numeric_request_ids' => false]);

    $me      = racStudent();
    $request = DocumentRequest::factory()->create(['user_id' => $me->user_id]);

    Sanctum::actingAs($me);

    $this->getJson("/api/v1/document-requests/{$request->request_id}")->assertNotFound();
    $this->getJson("/api/v1/document-requests/{$request->public_id}")->assertOk();
});

it('never accepts the claim uuid as a route identifier', function () {
    racSeedStatuses();

    $me      = racStudent();
    $request = DocumentRequest::factory()->create(['user_id' => $me->user_id]);

    Sanctum::actingAs($me);

    $this->getJson("/api/v1/document-requests/{$request->uuid}")->assertNotFound();
});

it('gives every new request a unique ULID public_id', function () {
    $a = DocumentRequest::factory()->create();
    $b = DocumentRequest::factory()->create();

    expect($a->public_id)->toMatch('/^[0-9a-hjkmnp-tv-z]{26}$/')
        ->and($a->public_id)->not->toBe($b->public_id);
});

it('still forbids a student from reading another student\'s request by public_id', function () {
    racSeedStatuses();

    $me      = racStudent();
    $request = DocumentRequest::factory()->create(['user_id' => racStudent()->user_id]);

    Sanctum::actingAs($me);

    $this->getJson("/api/v1/document-requests/{$request->public_id}")->assertForbidden();
});

it('answers 404 for a malformed identifier', function () {
    racSeedStatuses();

    Sanctum::actingAs(racAdmin());

    $this->getJson('/api/v1/document-requests/not-a-valid-id')->assertNotFound();
});