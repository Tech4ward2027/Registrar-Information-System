<?php

use App\Models\DocumentRequest;
use App\Models\RequestorStatusFlag;
use App\Models\StudentProfile;
use App\Models\SystemUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// The staff "Requestor Status & Representative" panel reads one endpoint:
// GET /document-requests/{id}/representative. It must report whether the
// requestor is flagged (status and date only) and must stay staff-only.

function panelRequest(): array
{
    $owner = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT, 'status' => 'Activated']);
    StudentProfile::factory()->create(['user_id' => $owner->user_id]);

    return [$owner, DocumentRequest::factory()->create(['user_id' => $owner->user_id])];
}

function panelStaff(): SystemUser
{
    $admin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN, 'status' => 'Activated']);

    return grantFullDashboardAccess($admin);
}

test('an unflagged requestor reports no flag and no representative', function () {
    [, $request] = panelRequest();
    Sanctum::actingAs(panelStaff());

    $this->getJson("/api/v1/document-requests/{$request->request_id}/representative")
        ->assertOk()
        ->assertJsonPath('data', null)
        ->assertJsonPath('requestor_flag', null);
});

test('an active flag is reported with its status but not its note', function () {
    [$owner, $request] = panelRequest();
    RequestorStatusFlag::create(['user_id' => $owner->user_id, 'status' => 'deceased', 'note' => 'secret note', 'flagged_by' => 1]);
    Sanctum::actingAs(panelStaff());

    $response = $this->getJson("/api/v1/document-requests/{$request->request_id}/representative")->assertOk();

    $response->assertJsonPath('requestor_flag.status', 'deceased');
    expect($response->json('requestor_flag'))->not->toHaveKey('note')
        ->and($response->getContent())->not->toContain('secret note');
});

test('a lifted flag is no longer reported', function () {
    [$owner, $request] = panelRequest();
    RequestorStatusFlag::create(['user_id' => $owner->user_id, 'status' => 'deceased', 'flagged_by' => 1, 'lifted_at' => now(), 'lifted_by' => 1, 'lift_reason' => 'entered in error']);
    Sanctum::actingAs(panelStaff());

    $this->getJson("/api/v1/document-requests/{$request->request_id}/representative")
        ->assertOk()
        ->assertJsonPath('requestor_flag', null);
});

test('a requestor cannot read the panel endpoint', function () {
    [$owner, $request] = panelRequest();
    Sanctum::actingAs($owner);

    $this->getJson("/api/v1/document-requests/{$request->request_id}/representative")->assertForbidden();
});
