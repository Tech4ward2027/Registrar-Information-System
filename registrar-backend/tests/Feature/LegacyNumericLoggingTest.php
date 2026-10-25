<?php

use App\Models\DocumentRequest;
use App\Models\RequestStatus;
use App\Models\SystemUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The legacy-numeric-id path is logged so it is clear when it is safe to
| set PUBLIC_IDS_ACCEPT_LEGACY_NUMERIC=false.
|--------------------------------------------------------------------------
| Place at tests/Feature/LegacyNumericLoggingTest.php.
*/

function lnlAdmin(): SystemUser
{
    foreach ([1 => 'Processing', 2 => 'Ready to Claim', 3 => 'Completed', 4 => 'Forfeited'] as $id => $name) {
        RequestStatus::firstOrCreate(['status_id' => $id], ['status_name' => $name]);
    }

    $user = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN, 'status' => 'Activated']);
    grantFullDashboardAccess($user);
    Sanctum::actingAs($user);

    return $user;
}

it('logs once when a request is addressed by its integer id', function () {
    config(['public_ids.accept_legacy_numeric_request_ids' => true, 'public_ids.log_legacy_numeric_use' => true]);
    $request = DocumentRequest::factory()->create();
    lnlAdmin();
    Log::spy();

    $this->getJson("/api/v1/document-requests/{$request->request_id}")->assertOk();

    Log::shouldHaveReceived('info')
        ->withArgs(fn ($message) => $message === 'public_ids.legacy_numeric_id_used')
        ->once();
});

it('logs once per call even when a bulk call carries many integer ids', function () {
    config(['public_ids.accept_legacy_numeric_request_ids' => true, 'public_ids.log_legacy_numeric_use' => true]);
    $a = DocumentRequest::factory()->create();
    $b = DocumentRequest::factory()->create();
    lnlAdmin();
    Log::spy();

    $this->postJson('/api/v1/document-requests/archive-bulk', ['request_ids' => [$a->request_id, $b->request_id]])
        ->assertOk();

    Log::shouldHaveReceived('info')
        ->withArgs(fn ($message) => $message === 'public_ids.legacy_numeric_id_used')
        ->once();
});

it('does not log when the request is addressed by public_id', function () {
    config(['public_ids.accept_legacy_numeric_request_ids' => true, 'public_ids.log_legacy_numeric_use' => true]);
    $request = DocumentRequest::factory()->create();
    lnlAdmin();
    Log::spy();

    $this->getJson("/api/v1/document-requests/{$request->public_id}")->assertOk();

    Log::shouldNotHaveReceived('info', [Mockery::on(fn ($m) => $m === 'public_ids.legacy_numeric_id_used'), Mockery::any()]);
});

it('does not log when logging is switched off', function () {
    config(['public_ids.accept_legacy_numeric_request_ids' => true, 'public_ids.log_legacy_numeric_use' => false]);
    $request = DocumentRequest::factory()->create();
    lnlAdmin();
    Log::spy();

    $this->getJson("/api/v1/document-requests/{$request->request_id}")->assertOk();

    Log::shouldNotHaveReceived('info', [Mockery::on(fn ($m) => $m === 'public_ids.legacy_numeric_id_used'), Mockery::any()]);
});
