<?php

use App\Models\DocumentRequest;
use App\Models\Policy;
use App\Models\RequestHistory;
use App\Models\RequestStatus;
use App\Models\SystemUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| GET /request-history is paginated and does not embed the full parent request
| or the full changing account. Place at tests/Feature/RequestHistoryPaginationTest.php.
|--------------------------------------------------------------------------
*/

const RHP_STAFF_IDP = 'idp-sub-history-7e2b';

function rhpSeed(int $rows): array
{
    foreach ([1 => 'Processing', 2 => 'Ready to Claim', 3 => 'Completed'] as $id => $name) {
        RequestStatus::firstOrCreate(['status_id' => $id], ['status_name' => $name]);
    }

    // The route needs module logbook/View, which grantFullDashboardAccess() does not give.
    $policy = Policy::firstOrCreate(
        ['name' => 'Test Logbook View (RHP)'],
        ['permissions' => ['logbook' => ['View']], 'is_system' => false]
    );

    $admin = SystemUser::factory()->create([
        'role_id'     => SystemUser::ROLE_ADMIN,
        'status'      => 'Activated',
        'idp_user_id' => RHP_STAFF_IDP,
        'policy_id'   => $policy->policy_id,
    ]);

    $request = DocumentRequest::factory()->create();

    $ids = [];
    for ($i = 0; $i < $rows; $i++) {
        // Identical changed_at on purpose: ordering must still be stable.
        $ids[] = RequestHistory::create([
            'request_id'    => $request->request_id,
            'old_status_id' => 1,
            'new_status_id' => 2,
            'changed_at'    => '2026-10-01 09:00:00',
            'changed_by'    => $admin->user_id,
        ])->request_history_id;
    }

    return ['admin' => $admin, 'request' => $request, 'ids' => $ids];
}

it('returns a page, not the whole table', function () {
    $s = rhpSeed(30);
    Sanctum::actingAs($s['admin']);

    $this->getJson('/api/v1/request-history')
        ->assertOk()
        ->assertJsonCount(25, 'data')
        ->assertJsonPath('total', 30)
        ->assertJsonPath('per_page', 25)
        ->assertJsonPath('last_page', 2);
});

it('caps per_page at 100 and honours a smaller one', function () {
    $s = rhpSeed(3);
    Sanctum::actingAs($s['admin']);

    $this->getJson('/api/v1/request-history?per_page=1000')->assertOk()->assertJsonPath('per_page', 100);
    $this->getJson('/api/v1/request-history?per_page=2')->assertOk()->assertJsonCount(2, 'data');
});

it('pages without repeating or skipping rows that share a timestamp', function () {
    $s = rhpSeed(5);
    Sanctum::actingAs($s['admin']);

    $first  = collect($this->getJson('/api/v1/request-history?per_page=3&page=1')->json('data'))->pluck('request_history_id');
    $second = collect($this->getJson('/api/v1/request-history?per_page=3&page=2')->json('data'))->pluck('request_history_id');

    expect($first->merge($second)->sort()->values()->all())->toBe(collect($s['ids'])->sort()->values()->all())
        ->and($first->intersect($second)->isEmpty())->toBeTrue();
});

it('reduces the embedded request and account to pointers', function () {
    $s = rhpSeed(1);
    Sanctum::actingAs($s['admin']);

    $response = $this->getJson('/api/v1/request-history')->assertOk();

    expect($response->getContent())->not->toContain(RHP_STAFF_IDP)
        ->and($response->getContent())->not->toContain($s['request']->uuid)
        ->and(array_keys($response->json('data.0.request')))
            ->toEqualCanonicalizing(['request_id', 'public_id', 'status_id', 'requested_at']);

    $this->getJson('/api/v1/request-history/' . $s['ids'][0])
        ->assertOk()
        ->assertJsonPath('request.public_id', $s['request']->public_id);
});

it('stays staff-only', function () {
    rhpSeed(1);
    Sanctum::actingAs(SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT, 'status' => 'Activated']));

    $this->getJson('/api/v1/request-history')->assertForbidden();
});
