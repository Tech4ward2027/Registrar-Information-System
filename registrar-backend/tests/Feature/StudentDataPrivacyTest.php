<?php

use App\Models\StudentProfile;
use App\Models\SystemUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function privacyActAs(int $roleId): SystemUser
{
    $user = SystemUser::factory()->create(['role_id' => $roleId, 'status' => 'Activated']);
    Sanctum::actingAs($user);

    return $user;
}

test('the list-all students endpoint no longer exists', function () {
    privacyActAs(SystemUser::ROLE_SUPER_ADMIN);

    $this->getJson('/api/v1/students')->assertStatus(405);
});

test('a single student profile can still be fetched by an admin', function () {
    $profile = StudentProfile::factory()->create();
    privacyActAs(SystemUser::ROLE_SUPER_ADMIN);

    $this->getJson("/api/v1/students/{$profile->student_profile_id}")->assertOk();
});

test('students cannot read other students through the per-student-number endpoints', function (string $path) {
    privacyActAs(SystemUser::ROLE_STUDENT);

    $this->getJson("/api/v1/students/{$path}")->assertForbidden();
})->with([
    'search'        => ['search?last_name=Cruz'],
    'ogos'          => ['2021-00001-MN-0/ogos'],
    'personal info' => ['2021-00001-MN-0/personal-info'],
    'addresses'     => ['2021-00001-MN-0/addresses'],
]);

test('alumni and undergrad requestors are forbidden too', function (int $role) {
    privacyActAs($role);

    $this->getJson('/api/v1/students/2021-00001-MN-0/personal-info')->assertForbidden();
})->with([
    'alumni'    => [SystemUser::ROLE_ALUMNI],
    'undergrad' => [SystemUser::ROLE_UNDERGRAD_REQUESTOR],
]);

test('guests are rejected', function () {
    $this->getJson('/api/v1/students/2021-00001-MN-0/ogos')->assertUnauthorized();
});