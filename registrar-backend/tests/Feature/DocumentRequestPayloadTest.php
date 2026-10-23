<?php

use App\Models\DocumentRequest;
use App\Models\RequestRemark;
use App\Models\RequestStatus;
use App\Models\SystemUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| GET /document-requests/{id} must not serialise internal account columns,
| staff identities (to a non-staff viewer) or another request's claim
| credential. Place at tests/Feature/DocumentRequestPayloadTest.php.
|
| request_id / user_id at the top level are intentionally still present until
| the contract phase (the frontend reads them), so they are not asserted
| absent here.
|--------------------------------------------------------------------------
*/

const DRP_STAFF_IDP   = 'idp-sub-staff-9f3a';
const DRP_STUDENT_IDP = 'idp-sub-student-4c1d';

function drpSeedStatuses(): void
{
    foreach ([1 => 'Processing', 2 => 'Ready to Claim', 3 => 'Completed'] as $id => $name) {
        RequestStatus::firstOrCreate(['status_id' => $id], ['status_name' => $name]);
    }
}

/** @return array{student: SystemUser, admin: SystemUser, request: DocumentRequest, other: DocumentRequest} */
function drpScenario(): array
{
    drpSeedStatuses();

    $student = SystemUser::factory()->create([
        'role_id'     => SystemUser::ROLE_STUDENT,
        'status'      => 'Activated',
        'idp_user_id' => DRP_STUDENT_IDP,
    ]);

    $admin = SystemUser::factory()->create([
        'role_id'     => SystemUser::ROLE_ADMIN,
        'status'      => 'Activated',
        'idp_user_id' => DRP_STAFF_IDP,
    ]);
    grantFullDashboardAccess($admin);

    $other = DocumentRequest::factory()->create(['user_id' => $student->user_id]);

    $request = DocumentRequest::factory()->create([
        'user_id'                  => $student->user_id,
        'is_archived'              => true,
        'archived_by'              => $admin->user_id,
        'archived_on'              => now(),
        'superseded_by_request_id' => $other->request_id,
    ]);

    RequestRemark::factory()->create([
        'request_id' => $request->request_id,
        'issued_by'  => $admin->user_id,
    ]);

    return compact('student', 'admin', 'request', 'other');
}

it('keeps the fields the frontend reads and the bare model shape', function () {
    ['student' => $student, 'request' => $request] = drpScenario();
    Sanctum::actingAs($student);

    $this->getJson('/api/v1/document-requests/' . $request->public_id)
        ->assertOk()
        ->assertJsonMissingPath('data')
        ->assertJsonPath('public_id', $request->public_id)
        ->assertJsonPath('request_id', $request->request_id)
        ->assertJsonPath('claim_code', $request->claim_code)
        ->assertJsonPath('user.email', $student->email);
});

it('never serialises account internals to the requester', function () {
    ['student' => $student, 'request' => $request] = drpScenario();
    Sanctum::actingAs($student);

    $json = $this->getJson('/api/v1/document-requests/' . $request->public_id)
        ->assertOk()
        ->getContent();

    expect($json)->not->toContain(DRP_STUDENT_IDP)
        ->and($json)->not->toContain('idp_user_id')
        ->and($json)->not->toContain('idp_access_token')
        ->and($json)->not->toContain('"password"');
});

it('hides staff accounts from a non-staff viewer', function () {
    ['student' => $student, 'admin' => $admin, 'request' => $request] = drpScenario();
    Sanctum::actingAs($student);

    $response = $this->getJson('/api/v1/document-requests/' . $request->public_id)->assertOk();

    $response->assertJsonMissingPath('archived_by_user')
        ->assertJsonMissingPath('open_deficiency_notice.issued_by_user');

    expect($response->getContent())->not->toContain($admin->email)
        ->and($response->getContent())->not->toContain(DRP_STAFF_IDP);
});

it('shows staff the actor email the dashboard reads, without internals', function () {
    ['admin' => $admin, 'request' => $request] = drpScenario();
    Sanctum::actingAs($admin);

    $response = $this->getJson('/api/v1/document-requests/' . $request->public_id)
        ->assertOk()
        ->assertJsonPath('archived_by_user.email', $admin->email);

    expect($response->getContent())->not->toContain(DRP_STAFF_IDP)
        ->and($response->getContent())->not->toContain(DRP_STUDENT_IDP)
        ->and($response->getContent())->not->toContain('idp_user_id');
});

it('reduces superseding_request to a pointer for every viewer', function (string $who) {
    $s = drpScenario();
    Sanctum::actingAs($s[$who]);

    $response = $this->getJson('/api/v1/document-requests/' . $s['request']->public_id)->assertOk();

    $response->assertJsonPath('superseding_request.public_id', $s['other']->public_id);

    expect(array_keys($response->json('superseding_request')))
        ->toEqualCanonicalizing(['request_id', 'public_id', 'status_id'])
        ->and($response->getContent())->not->toContain($s['other']->uuid);
})->with(['student', 'admin']);
