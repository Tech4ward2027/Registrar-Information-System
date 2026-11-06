<?php

use App\Models\SystemUser;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Item 9.12 — App\Http\Requests\PaginatedIndexRequest on list endpoints.
// Exercised through GET /security-events (Super Admin only), which uses the
// same request class as the calendar and cashier list endpoints.

test('malformed paging input is rejected with 422 instead of reaching the paginator', function (string $query) {
    $super = SystemUser::factory()->create([
        'role_id' => SystemUser::ROLE_SUPER_ADMIN,
        'status'  => 'Activated',
    ]);

    $this->actingAs($super, 'sanctum')
        ->getJson('/api/v1/security-events?' . $query)
        ->assertStatus(422);
})->with([
    'non-numeric'   => 'per_page=abc',
    'zero'          => 'per_page=0',
    'negative'      => 'per_page=-1',
    'array'         => 'per_page[]=1',
    'huge page'     => 'page=99999999999999999999',
    'page too high' => 'page=100001',
]);

test('an oversized but well-formed per_page is clamped to the endpoint ceiling', function () {
    $super = SystemUser::factory()->create([
        'role_id' => SystemUser::ROLE_SUPER_ADMIN,
        'status'  => 'Activated',
    ]);

    $this->actingAs($super, 'sanctum')
        ->getJson('/api/v1/security-events?per_page=5000')
        ->assertOk()
        ->assertJsonPath('meta.per_page', 100);
});

test('a valid per_page and the default both work', function () {
    $super = SystemUser::factory()->create([
        'role_id' => SystemUser::ROLE_SUPER_ADMIN,
        'status'  => 'Activated',
    ]);

    $this->actingAs($super, 'sanctum')
        ->getJson('/api/v1/security-events?per_page=7&page=1')
        ->assertOk()
        ->assertJsonPath('meta.per_page', 7);

    $this->actingAs($super, 'sanctum')
        ->getJson('/api/v1/security-events')
        ->assertOk()
        ->assertJsonPath('meta.per_page', 20);
});
