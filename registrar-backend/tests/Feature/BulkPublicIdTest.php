<?php

use App\Models\DocumentRequest;
use App\Models\RequestStatus;
use App\Models\SystemUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Bulk endpoints accept public_id (ULID) request references.
|--------------------------------------------------------------------------
| Place at tests/Feature/BulkPublicIdTest.php.
*/

function bpiAdmin(): SystemUser
{
    foreach ([1 => 'Processing', 2 => 'Ready to Claim', 3 => 'Completed', 4 => 'Forfeited'] as $id => $name) {
        RequestStatus::firstOrCreate(['status_id' => $id], ['status_name' => $name]);
    }

    $user = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN, 'status' => 'Activated']);
    grantFullDashboardAccess($user);
    Sanctum::actingAs($user);

    return $user;
}

it('archives and restores by public_id', function () {
    $request = DocumentRequest::factory()->create();
    bpiAdmin();

    $this->postJson('/api/v1/document-requests/archive-bulk', ['request_ids' => [$request->public_id]])
        ->assertOk()
        ->assertJsonPath('archived', [$request->request_id]);

    $this->assertDatabaseHas('document_request', ['request_id' => $request->request_id, 'is_archived' => true]);

    // Archived rows are hidden by default; restore must still resolve them.
    $this->postJson('/api/v1/document-requests/restore-bulk', ['request_ids' => [$request->public_id]])
        ->assertOk()
        ->assertJsonPath('restored', [$request->request_id]);

    $this->assertDatabaseHas('document_request', ['request_id' => $request->request_id, 'is_archived' => false]);
});

it('accepts a mix of public_id and legacy integer ids while the switch is on', function () {
    config(['public_ids.accept_legacy_numeric_request_ids' => true]);
    $a = DocumentRequest::factory()->create();
    $b = DocumentRequest::factory()->create();
    bpiAdmin();

    $this->postJson('/api/v1/document-requests/archive-bulk', [
        'request_ids' => [$a->public_id, $b->request_id],
    ])->assertOk()
      ->assertJsonCount(2, 'archived');
});

it('counts an integer id and the public_id of the same request once', function () {
    $request = DocumentRequest::factory()->create();
    bpiAdmin();

    $this->postJson('/api/v1/document-requests/archive-bulk', [
        'request_ids' => [$request->public_id, $request->request_id],
    ])->assertOk()
      ->assertJsonPath('archived', [$request->request_id]);
});

it('rejects integer ids once the legacy switch is off', function () {
    config(['public_ids.accept_legacy_numeric_request_ids' => false]);
    $request = DocumentRequest::factory()->create();
    bpiAdmin();

    $this->postJson('/api/v1/document-requests/archive-bulk', ['request_ids' => [$request->request_id]])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['request_ids.0']);

    $this->postJson('/api/v1/document-requests/archive-bulk', ['request_ids' => [$request->public_id]])
        ->assertOk();
});

it('rejects a malformed reference and the claim uuid', function () {
    $request = DocumentRequest::factory()->create();
    bpiAdmin();

    $this->postJson('/api/v1/document-requests/archive-bulk', ['request_ids' => ['not-an-id']])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['request_ids.0']);

    $this->postJson('/api/v1/document-requests/archive-bulk', ['request_ids' => [$request->uuid]])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['request_ids.0']);
});

it('fails validation when no public_id matches any request', function () {
    bpiAdmin();

    $this->postJson('/api/v1/document-requests/archive-bulk', [
        'request_ids' => [strtolower((string) \Illuminate\Support\Str::ulid())],
    ])->assertStatus(422)
      ->assertJsonValidationErrors(['request_ids']);
});

it('still forbids a student from the bulk endpoints', function () {
    $request = DocumentRequest::factory()->create();
    Sanctum::actingAs(SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT, 'status' => 'Activated']));

    $this->postJson('/api/v1/document-requests/archive-bulk', ['request_ids' => [$request->public_id]])
        ->assertForbidden();
});
