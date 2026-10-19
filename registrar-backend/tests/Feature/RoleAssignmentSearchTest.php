<?php

use App\Models\Alumni;
use App\Models\AlumniProfile;
use App\Models\AlumniType;
use App\Models\AdminProfile;
use App\Models\RoleAssignment;
use App\Models\StudentProfile;
use App\Models\SystemUser;
use App\Services\RoleAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// ── Helpers ───────────────────────────────────────────────────────────────────
// Named distinctly (not "makeStudent"/"makeAdmin") to avoid colliding with
// same-purpose helpers already defined in other Feature test files — Pest
// loads every Feature/*.php into one process, so helper names are effectively
// global.

function searchTestStudent(string $firstName, string $lastName, array $userAttrs = []): SystemUser
{
    $user = SystemUser::factory()->create(array_merge([
        'role_id' => SystemUser::ROLE_STUDENT,
        'status'  => 'Activated',
    ], $userAttrs));

    StudentProfile::factory()->create([
        'user_id'    => $user->user_id,
        'first_name' => $firstName,
        'last_name'  => $lastName,
    ]);

    return $user;
}

function searchTestAdmin(string $firstName, string $lastName, array $userAttrs = []): SystemUser
{
    $user = SystemUser::factory()->create(array_merge([
        'role_id' => SystemUser::ROLE_ADMIN,
        'status'  => 'Activated',
    ], $userAttrs));

    AdminProfile::create([
        'user_id'    => $user->user_id,
        'first_name' => $firstName,
        'last_name'  => $lastName,
    ]);

    return $user;
}

function searchTestAlumni(string $firstName, string $lastName, array $userAttrs = []): SystemUser
{
    $type = AlumniType::firstOrCreate(['alumni_type' => 'SIS']);

    $user = SystemUser::factory()->create(array_merge([
        'role_id' => SystemUser::ROLE_ALUMNI,
        'status'  => 'Activated',
    ], $userAttrs));

    $alumni = Alumni::create([
        'user_id'        => $user->user_id,
        'alumni_type_id' => $type->alumni_type_id,
    ]);

    AlumniProfile::create([
        'alumni_id'     => $alumni->alumni_id,
        'first_name'    => $firstName,
        'last_name'     => $lastName,
        'date_of_birth' => '2000-01-01',
        'sex_at_birth'  => 'Male',
    ]);

    return $user;
}

// ═════════════════════════════════════════════════════════════════════════════
// RoleAssignmentService::searchGrantableUsers()
// ═════════════════════════════════════════════════════════════════════════════

test('finds a student by first name prefix', function () {
    $juan = searchTestStudent('Juan', 'Dela Cruz');
    // Email pinned (not left to Faker) so it can never coincidentally
    // start with the 'Jua' search prefix below — searchGrantableUsers()
    // matches on email too, and an unseeded Faker safeEmail() collides
    // with 'jua...' just often enough to flake this exact-count
    // assertion. Same fix already applied to the admin/alumni search
    // tests further down this file.
    searchTestStudent('Maria', 'Santos', ['email' => 'msantos-noise@registrar.edu']); // noise — must not match

    $results = app(RoleAssignmentService::class)->searchGrantableUsers('Jua');

    expect($results->pluck('user_id'))->toContain($juan->user_id);
    expect($results)->toHaveCount(1);
});

test('finds a student by last name prefix', function () {
    $juan = searchTestStudent('Juan', 'Dela Cruz');

    $results = app(RoleAssignmentService::class)->searchGrantableUsers('Dela');

    expect($results->pluck('user_id'))->toContain($juan->user_id);
});

test('finds an admin account by email prefix', function () {
    $admin = searchTestAdmin('Maria', 'Santos', ['email' => 'msantos@registrar.edu']);

    $results = app(RoleAssignmentService::class)->searchGrantableUsers('msantos');

    expect($results->pluck('user_id'))->toContain($admin->user_id);
});

test('finds an alumni account by name', function () {
    $alumnus = searchTestAlumni('Pedro', 'Reyes');

    $results = app(RoleAssignmentService::class)->searchGrantableUsers('Reyes');

    expect($results->pluck('user_id'))->toContain($alumnus->user_id);
});

test('searches across every role, not just admin/super admin', function () {
    $student = searchTestStudent('Kristine', 'Bautista');
    $admin   = searchTestAdmin('Kristoffer', 'Bautista');

    $results = app(RoleAssignmentService::class)->searchGrantableUsers('Bautista');

    expect($results->pluck('user_id'))
        ->toContain($student->user_id)
        ->toContain($admin->user_id);
});

test('excludes deactivated accounts', function () {
    $deactivated = searchTestStudent('Ana', 'Villanueva', ['status' => 'Deactivated']);

    $results = app(RoleAssignmentService::class)->searchGrantableUsers('Ana');

    expect($results->pluck('user_id'))->not->toContain($deactivated->user_id);
});

test('excludes accounts still in Pending Activation', function () {
    $pending = searchTestAdmin('Carlo', 'Mendoza', ['status' => 'Pending Activation']);

    $results = app(RoleAssignmentService::class)->searchGrantableUsers('Carlo');

    expect($results->pluck('user_id'))->not->toContain($pending->user_id);
});

test('matches by prefix only — a mid-string fragment does not match', function () {
    // Proves this is LIKE 'term%' (indexable, scalable) and not
    // LIKE '%term%' — searching "uan" should NOT match "Juan", since
    // "Juan" does not START with "uan".
    searchTestStudent('Juan', 'Dela Cruz');

    $results = app(RoleAssignmentService::class)->searchGrantableUsers('uan');

    expect($results)->toHaveCount(0);
});

test('escapes literal LIKE wildcard characters typed by the searcher', function () {
    // A literal "%" typed into the search box must be treated as a
    // literal character to match against, not as a SQL LIKE wildcard
    // that would otherwise match every row in the table.
    searchTestStudent('Juan', 'Dela Cruz');
    searchTestStudent('Maria', 'Santos');

    $results = app(RoleAssignmentService::class)->searchGrantableUsers('%%');

    expect($results)->toHaveCount(0);
});

test('caps results at 10 even when more accounts match', function () {
    foreach (range(1, 15) as $i) {
        searchTestStudent("Student{$i}", 'Delacruz');
    }

    $results = app(RoleAssignmentService::class)->searchGrantableUsers('Student');

    expect($results)->toHaveCount(10);
});

test('reports the roles a matched account currently, actively holds', function () {
    // The "student staff" shape: one account, two concurrent Active
    // assignments. Confirms GrantableUserResource's active_role_ids
    // (via the eager-loaded activeRoleAssignments relation) surfaces
    // both, so the picker can warn "already holds Admin".
    $staffer = searchTestStudent('Liza', 'Aquino');

    RoleAssignment::create([
        'user_id' => $staffer->user_id,
        'role_id' => SystemUser::ROLE_STUDENT,
        'status'  => RoleAssignment::STATUS_ACTIVE,
    ]);
    RoleAssignment::create([
        'user_id' => $staffer->user_id,
        'role_id' => SystemUser::ROLE_ADMIN,
        'status'  => RoleAssignment::STATUS_ACTIVE,
    ]);

    $result = app(RoleAssignmentService::class)
        ->searchGrantableUsers('Liza')
        ->firstWhere('user_id', $staffer->user_id);

    expect($result->activeRoleAssignments->pluck('role_id')->all())
        ->toContain(SystemUser::ROLE_STUDENT)
        ->toContain(SystemUser::ROLE_ADMIN);
});

// ═════════════════════════════════════════════════════════════════════════════
// GET /role-assignments/search-users — HTTP layer (auth, validation, shape)
// ═════════════════════════════════════════════════════════════════════════════

test('super admin can search and receives matching results', function () {
    $superAdmin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN, 'status' => 'Activated']);
    Sanctum::actingAs($superAdmin);

    $juan = searchTestStudent('Juan', 'Dela Cruz');

    $this->getJson('/api/v1/role-assignments/search-users?q=Juan')
        ->assertOk()
        ->assertJsonFragment(['user_id' => $juan->user_id]);
});

test('a plain admin is forbidden from searching', function () {
    $admin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN, 'status' => 'Activated']);
    Sanctum::actingAs($admin);

    $this->getJson('/api/v1/role-assignments/search-users?q=Juan')
        ->assertStatus(403);
});

test('a student is forbidden from searching', function () {
    $student = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT, 'status' => 'Activated']);
    Sanctum::actingAs($student);

    $this->getJson('/api/v1/role-assignments/search-users?q=Juan')
        ->assertStatus(403);
});

test('an unauthenticated request is rejected', function () {
    $this->getJson('/api/v1/role-assignments/search-users?q=Juan')
        ->assertStatus(401);
});

test('rejects a missing search term', function () {
    $superAdmin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN, 'status' => 'Activated']);
    Sanctum::actingAs($superAdmin);

    $this->getJson('/api/v1/role-assignments/search-users')
        ->assertStatus(422)
        ->assertJsonValidationErrors('q');
});

test('rejects a search term shorter than the minimum length', function () {
    $superAdmin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN, 'status' => 'Activated']);
    Sanctum::actingAs($superAdmin);

    $this->getJson('/api/v1/role-assignments/search-users?q=J')
        ->assertStatus(422)
        ->assertJsonValidationErrors('q');
});

test('response never leaks sensitive account fields', function () {
    $superAdmin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN, 'status' => 'Activated']);
    Sanctum::actingAs($superAdmin);

    searchTestStudent('Juan', 'Dela Cruz');

    $response = $this->getJson('/api/v1/role-assignments/search-users?q=Juan')->assertOk();

    $payload = json_encode($response->json());

    // GrantableUserResource must stay minimal — a picker only needs
    // enough to identify the right person, nothing from UserResource's
    // full identity payload.
    expect($payload)
        ->not->toContain('idp_access_token')
        ->not->toContain('"password"')
        ->not->toContain('effective_permissions');
});

test('response shape includes the fields the picker relies on', function () {
    $superAdmin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN, 'status' => 'Activated']);
    Sanctum::actingAs($superAdmin);

    searchTestAdmin('Maria', 'Santos', ['email' => 'msantos@registrar.edu']);

    $this->getJson('/api/v1/role-assignments/search-users?q=Maria')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                ['user_id', 'email', 'full_name', 'role_id', 'role_name'],
            ],
        ])
        ->assertJsonFragment([
            'full_name' => 'Maria Santos',
            'role_name' => 'Admin',
        ]);
});