<?php

use App\Contracts\AlumniSystemClientInterface;
use App\Models\SystemUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| /alumni-system returns alumni PII — staff only.
|--------------------------------------------------------------------------
| Place at tests/Feature/AlumniSystemAccessTest.php.
*/

function asaMockClient(): void
{
    $client = Mockery::mock(AlumniSystemClientInterface::class);
    $client->shouldReceive('tryListAlumni')->andReturn([
        'data' => [], 'total' => 0, 'current_page' => 1, 'last_page' => 1, 'per_page' => 15,
    ]);
    $client->shouldReceive('tryGetAlumni')->andReturn(null);
    app()->instance(AlumniSystemClientInterface::class, $client);
}

it('forbids students and alumni from listing or reading alumni records', function (int $role) {
    asaMockClient();
    Sanctum::actingAs(SystemUser::factory()->create(['role_id' => $role, 'status' => 'Activated']));

    $this->getJson('/api/v1/alumni-system')->assertForbidden();
    $this->getJson('/api/v1/alumni-system/1')->assertForbidden();
})->with([
    'student' => SystemUser::ROLE_STUDENT,
    'alumni'  => SystemUser::ROLE_ALUMNI,
]);

it('requires authentication', function () {
    asaMockClient();

    $this->getJson('/api/v1/alumni-system')->assertUnauthorized();
});

it('allows an admin with dashboard access', function () {
    asaMockClient();
    $admin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN, 'status' => 'Activated']);
    grantFullDashboardAccess($admin);
    Sanctum::actingAs($admin);

    $this->getJson('/api/v1/alumni-system')->assertOk();
});

it('forbids an admin who has no dashboard access', function () {
    asaMockClient();
    Sanctum::actingAs(SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN, 'status' => 'Activated']));

    $this->getJson('/api/v1/alumni-system')->assertForbidden();
});
