<?php

use App\Models\AccessType;
use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Models\JobRunLog;
use App\Models\RequestDocument;
use App\Models\RequestRemark;
use App\Models\RequestStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

const RCC_PROCESSING = 1;
const RCC_READY      = 2;
const RCC_COMPLETED  = 3;
const RCC_WITHDRAWN  = 13;

function rccRequest(int $status): DocumentRequest
{
    foreach ([1 => 'Processing', 2 => 'Ready to Claim', 3 => 'Completed', 4 => 'Forfeited', 13 => 'Withdrawn', 14 => 'Closed - Unable to Process'] as $id => $name) {
        RequestStatus::firstOrCreate(['status_id' => $id], ['status_name' => $name]);
    }

    return DocumentRequest::factory()->create(['status_id' => $status]);
}

function rccItem(DocumentRequest $request, int $status, string $name = 'Transcript of Records'): RequestDocument
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
        'number_of_copies' => 1, 'status_id' => $status,
    ]);
}

function rccLastRun(): JobRunLog
{
    return JobRunLog::where('job_name', 'requests:check-consistency')->latest('job_run_id')->firstOrFail();
}

it('passes and records a successful run when everything is consistent', function () {
    $ok = rccRequest(RCC_PROCESSING);
    rccItem($ok, RCC_PROCESSING);

    // Completed + Withdrawn items under a Completed request is a legal end state.
    $done = rccRequest(RCC_COMPLETED);
    rccItem($done, RCC_COMPLETED, 'Diploma');
    rccItem($done, RCC_WITHDRAWN, 'Certificate of Grades');

    $this->artisan('requests:check-consistency')->assertExitCode(0);

    expect(rccLastRun()->status)->toBe(JobRunLog::STATUS_SUCCESS);
});

it('flags a final request that still has an open item', function () {
    $bad = rccRequest(RCC_COMPLETED);
    rccItem($bad, RCC_READY);

    $this->artisan('requests:check-consistency')->assertExitCode(1);

    $run = rccLastRun();
    expect($run->status)->toBe(JobRunLog::STATUS_FAILED)
        ->and($run->error_message)->toContain('terminal_request_open_items=1');
});

it('flags a non-final request whose items are all final', function () {
    $stuck = rccRequest(RCC_PROCESSING);
    rccItem($stuck, RCC_COMPLETED);

    $this->artisan('requests:check-consistency')->assertExitCode(1);

    expect(rccLastRun()->error_message)->toContain('stuck_parent=1');
});

it('flags an open item notice on an item that is already final', function () {
    $request = rccRequest(RCC_PROCESSING);
    rccItem($request, RCC_PROCESSING, 'Diploma');
    $doneItem = rccItem($request, RCC_COMPLETED);

    RequestRemark::factory()->create([
        'request_id'          => $request->request_id,
        'request_document_id' => $doneItem->request_document_id,
        'status'              => RequestRemark::STATUS_OPEN,
    ]);

    $this->artisan('requests:check-consistency')->assertExitCode(1);

    expect(rccLastRun()->error_message)->toContain('held_item_on_final=1');
});

it('does not flag an open notice on a live item, and never changes data', function () {
    $request = rccRequest(RCC_READY);
    $item    = rccItem($request, RCC_READY);

    RequestRemark::factory()->create([
        'request_id'          => $request->request_id,
        'request_document_id' => $item->request_document_id,
        'status'              => RequestRemark::STATUS_OPEN,
    ]);

    $this->artisan('requests:check-consistency')->assertExitCode(0);

    expect($item->fresh()->status_id)->toBe(RCC_READY)
        ->and($request->fresh()->status_id)->toBe(RCC_READY);
});
