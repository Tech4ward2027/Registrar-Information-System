<?php

use App\Models\AccessType;
use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Models\RequestDocument;
use App\Models\RequestRemark;
use App\Models\RequestStatus;
use App\Models\SystemUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function ihbStaff(): void
{
    foreach ([1 => 'Processing', 2 => 'Ready to Claim', 3 => 'Completed'] as $id => $name) {
        RequestStatus::firstOrCreate(['status_id' => $id], ['status_name' => $name]);
    }

    $admin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN, 'status' => 'Activated']);
    grantFullDashboardAccess($admin);
    Sanctum::actingAs($admin);
}

function ihbDoc(DocumentRequest $request, string $name): RequestDocument
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
        'number_of_copies' => 1, 'status_id' => 2,
    ]);
}

it('flags only the held item on the per-document list', function () {
    ihbStaff();

    $request = DocumentRequest::factory()->create(['status_id' => 2]);
    $held    = ihbDoc($request, 'Transcript of Records');
    $free    = ihbDoc($request, 'Diploma');

    RequestRemark::factory()->create([
        'request_id'          => $request->request_id,
        'request_document_id' => $held->request_document_id,
        'status'              => RequestRemark::STATUS_OPEN,
        'detail'              => 'private free text',
    ]);

    $rows = collect($this->getJson('/api/document-requests/items?all_statuses=1')->assertOk()->json('data'))
        ->keyBy('id');

    expect($rows[$held->request_document_id]['on_hold'])->toBeTrue()
        ->and($rows[$held->request_document_id]['hold'])->toHaveKeys(['remark_id', 'label', 'issued_at', 'is_stale'])
        ->and($rows[$held->request_document_id]['hold'])->not->toHaveKey('detail')
        ->and($rows[$free->request_document_id]['on_hold'])->toBeFalse()
        ->and($rows[$free->request_document_id]['hold'])->toBeNull();
});

it('does not flag an item whose notice was cleared', function () {
    ihbStaff();

    $request = DocumentRequest::factory()->create(['status_id' => 2]);
    $item    = ihbDoc($request, 'Transcript of Records');

    RequestRemark::factory()->create([
        'request_id'          => $request->request_id,
        'request_document_id' => $item->request_document_id,
        'status'              => RequestRemark::STATUS_CLEARED,
    ]);

    $row = collect($this->getJson('/api/document-requests/items?all_statuses=1')->assertOk()->json('data'))->first();

    expect($row['on_hold'])->toBeFalse();
});
