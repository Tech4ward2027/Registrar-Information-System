<?php

use App\Enums\RequestStatusEnum;
use App\Models\AccessType;
use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Models\RequestDocument;
use App\Models\RequestReleaseGroup;
use App\Models\RequestStatus;
use App\Services\RequestReleaseGroupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/** A request with two documents whose types sit on different fulfillment tracks. */
function rgsMixedTrackRequest(): DocumentRequest
{
    RequestStatus::firstOrCreate(['status_id' => 1], ['status_name' => 'Processing']);

    $access  = AccessType::firstOrCreate(['access_id' => 1], ['access_name' => 'Student'])->access_id;
    $trackId = DB::table('fulfillment_track')->insertGetId(['name' => 'Fast track']);

    $request = DocumentRequest::factory()->create(['status_id' => 1]);

    foreach ([['Diploma', $trackId], ['Transcript of Records', null]] as [$name, $track]) {
        $type = DocumentType::firstOrCreate(['document_name' => $name], [
            'document_description'    => 'desc',
            'document_requirements'   => 'Valid ID',
            'document_process_period' => '3-5 business days',
            'access_id'               => $access,
        ]);
        DB::table('document_type')->where('document_type_id', $type->document_type_id)
            ->update(['fulfillment_track_id' => $track]);

        RequestDocument::create([
            'request_id' => $request->request_id, 'document_type_id' => $type->document_type_id,
            'number_of_copies' => 1, 'status_id' => RequestStatusEnum::Processing->value,
        ]);
    }

    return $request->fresh();
}

it('creates no release groups when the switch is off', function () {
    // Pin the switch so a stray RELEASE_GROUPS_CREATE_ENABLED in .env or the
    // container environment can't change what this test proves.
    config(['release_groups.create_enabled' => false]);
    $request = rgsMixedTrackRequest();

    app(RequestReleaseGroupService::class)->assignReleaseGroups($request);

    expect(RequestReleaseGroup::where('request_id', $request->request_id)->count())->toBe(0);
});

it('still creates groups when the rollback switch is turned on', function () {
    config(['release_groups.create_enabled' => true]);
    $request = rgsMixedTrackRequest();

    app(RequestReleaseGroupService::class)->assignReleaseGroups($request);

    expect(RequestReleaseGroup::where('request_id', $request->request_id)->count())->toBe(2);
});

it('leaves already existing groups claimable when creation is off', function () {
    $request = rgsMixedTrackRequest();
    $group   = RequestReleaseGroup::create(['request_id' => $request->request_id, 'status_id' => 1]);

    expect(app(RequestReleaseGroupService::class)->findByCredential(['uuid' => $group->uuid]))
        ->not->toBeNull();
});