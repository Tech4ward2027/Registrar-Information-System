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
| GET /document-requests (staff list and a requester's own list) must not
| carry account internals, staff identities (to non-staff) or another
| request's claim credential - the same rules as the single-request payload.
| Place at tests/Feature/DocumentRequestListPayloadTest.php.
|--------------------------------------------------------------------------
*/

const DLP_STAFF_IDP   = 'idp-sub-list-staff-3b7e';
const DLP_STUDENT_IDP = 'idp-sub-list-student-8d2c';

/** @return array{student: SystemUser, admin: SystemUser, request: DocumentRequest, other: DocumentRequest} */
function dlpScenario(): array
{
    foreach ([1 => 'Processing', 2 => 'Ready to Claim', 3 => 'Completed'] as $id => $name) {
        RequestStatus::firstOrCreate(['status_id' => $id], ['status_name' => $name]);
    }

    $student = SystemUser::factory()->create([
        'role_id'     => SystemUser::ROLE_STUDENT,
        'status'      => 'Activated',
        'idp_user_id' => DLP_STUDENT_IDP,
    ]);

    $admin = SystemUser::factory()->create([
        'role_id'     => SystemUser::ROLE_ADMIN,
        'status'      => 'Activated',
        'idp_user_id' => DLP_STAFF_IDP,
    ]);
    grantFullDashboardAccess($admin);

    // Owned by someone else, so its uuid/claim_code can only reach the
    // student's list through the embedded superseding_request.
    $someoneElse = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT, 'status' => 'Activated']);
    $other       = DocumentRequest::factory()->create(['user_id' => $someoneElse->user_id]);

    // Not archived, so it stays in the default list; archived_by is set only
    // so the archivedByUser relation has an account to serialise.
    $request = DocumentRequest::factory()->create([
        'user_id'                  => $student->user_id,
        'archived_by'              => $admin->user_id,
        'superseded_by_request_id' => $other->request_id,
    ]);

    RequestRemark::factory()->create([
        'request_id' => $request->request_id,
        'issued_by'  => $admin->user_id,
    ]);

    return compact('student', 'admin', 'request', 'other');
}

function dlpRow(array $rows, int $requestId): ?array
{
    return collect($rows)->firstWhere('request_id', $requestId);
}

it('keeps account internals out of the staff list', function () {
    $s = dlpScenario();
    Sanctum::actingAs($s['admin']);

    $response = $this->getJson('/api/v1/document-requests?all_statuses=1')->assertOk();
    $row      = dlpRow($response->json('data'), $s['request']->request_id);

    expect($row)->not->toBeNull()
        ->and(json_encode($row))->not->toContain(DLP_STAFF_IDP)
        ->and(json_encode($row))->not->toContain(DLP_STUDENT_IDP)
        ->and(json_encode($row))->not->toContain('idp_user_id')
        ->and(json_encode($row))->not->toContain($s['other']->uuid);

    // The dashboard reads this one.
    expect($row['archived_by_user']['email'])->toBe($s['admin']->email);

    expect(array_keys($row['superseding_request']))
        ->toEqualCanonicalizing(['request_id', 'public_id', 'status_id']);
});

it('keeps staff identities and internals out of a requester\'s own list', function () {
    $s = dlpScenario();
    Sanctum::actingAs($s['student']);

    $response = $this->getJson('/api/v1/document-requests')->assertOk();
    $rows     = $response->json('data');
    $row      = dlpRow($rows, $s['request']->request_id);

    expect($row)->not->toBeNull()
        ->and($response->getContent())->not->toContain($s['admin']->email)
        ->and($response->getContent())->not->toContain(DLP_STAFF_IDP)
        ->and($response->getContent())->not->toContain(DLP_STUDENT_IDP)
        ->and($response->getContent())->not->toContain($s['other']->uuid)
        ->and($row)->not->toHaveKey('archived_by_user');

    // StudentDashboard filters its list on user_id, so it must stay.
    expect($row['user_id'])->toBe($s['student']->user_id)
        ->and($row['public_id'])->toBe($s['request']->public_id);
});
