<?php

use App\Models\Policy;
use App\Models\RoleAssignment;
use App\Models\SystemUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// ═════════════════════════════════════════════════════════════════════════════
// POST /role-assignments/{roleAssignment}/revoke — self-target guard
// (Gap #3 fix: mirrors SystemUserController::destroy()'s "can't delete
// your own account" guard. Without it, a Super Admin revoking their own
// assignment force-deletes every one of their own active Sanctum tokens
// mid-request — logging themselves out with no confirmation.)
// ═════════════════════════════════════════════════════════════════════════════

test('a Super Admin cannot revoke their own role assignment', function () {
    $superAdmin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN, 'status' => 'Activated']);

    // A second Active row so this would otherwise be a legal revoke (not
    // blocked by the separate "only active role" guard) — isolates this
    // test to the self-target guard specifically.
    RoleAssignment::create([
        'user_id'    => $superAdmin->user_id,
        'role_id'    => SystemUser::ROLE_STUDENT,
        'status'     => RoleAssignment::STATUS_ACTIVE,
        'granted_at' => now(),
    ]);

    $ownAssignment = RoleAssignment::create([
        'user_id'    => $superAdmin->user_id,
        'role_id'    => SystemUser::ROLE_SUPER_ADMIN,
        'status'     => RoleAssignment::STATUS_ACTIVE,
        'granted_at' => now(),
    ]);

    Sanctum::actingAs($superAdmin);

    $this->postJson("/api/v1/role-assignments/{$ownAssignment->id}/revoke", ['reason' => 'testing'])
        ->assertStatus(403)
        ->assertJson(['message' => 'You cannot revoke your own role assignment.']);

    expect($ownAssignment->fresh()->status)->toBe(RoleAssignment::STATUS_ACTIVE);
});

test('a Super Admin can revoke another users role assignment', function () {
    $superAdmin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN, 'status' => 'Activated']);
    $person     = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT]);

    RoleAssignment::create([
        'user_id'    => $person->user_id,
        'role_id'    => SystemUser::ROLE_STUDENT,
        'status'     => RoleAssignment::STATUS_ACTIVE,
        'granted_at' => now(),
    ]);

    $adminAssignment = RoleAssignment::create([
        'user_id'    => $person->user_id,
        'role_id'    => SystemUser::ROLE_ADMIN,
        'status'     => RoleAssignment::STATUS_ACTIVE,
        'granted_at' => now(),
    ]);

    Sanctum::actingAs($superAdmin);

    $this->postJson("/api/v1/role-assignments/{$adminAssignment->id}/revoke", ['reason' => 'testing'])
        ->assertOk();

    expect($adminAssignment->fresh()->status)->toBe(RoleAssignment::STATUS_REVOKED);
});

// ═════════════════════════════════════════════════════════════════════════════
// Work Item #2 — Admin Management Consolidation.
// PATCH /role-assignments/{roleAssignment}/policy — direct HTTP-layer
// replacement for the retired PATCH /system-users/{id}/policy.
// ═════════════════════════════════════════════════════════════════════════════

test('a Super Admin can edit the policy on an Active Admin role assignment in place', function () {
    $superAdmin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN, 'status' => 'Activated']);
    $target     = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN]);
    $newPolicy  = Policy::create(['name' => 'Records Staff', 'permissions' => ['dashboard' => ['Access']]]);

    $assignment = RoleAssignment::create([
        'user_id'    => $target->user_id,
        'role_id'    => SystemUser::ROLE_ADMIN,
        'status'     => RoleAssignment::STATUS_ACTIVE,
        'granted_at' => now(),
    ]);

    Sanctum::actingAs($superAdmin);

    $this->patchJson("/api/v1/role-assignments/{$assignment->id}/policy", ['policy_id' => $newPolicy->policy_id])
        ->assertOk()
        ->assertJsonPath('data.policy.policy_id', $newPolicy->policy_id);

    expect($assignment->fresh()->policy_id)->toBe($newPolicy->policy_id);
});

test('editing the policy on a non-Admin role assignment is rejected', function () {
    $superAdmin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN, 'status' => 'Activated']);
    $target     = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT]);
    $policy     = Policy::create(['name' => 'Records Staff', 'permissions' => ['dashboard' => ['Access']]]);

    $assignment = RoleAssignment::create([
        'user_id'    => $target->user_id,
        'role_id'    => SystemUser::ROLE_STUDENT,
        'status'     => RoleAssignment::STATUS_ACTIVE,
        'granted_at' => now(),
    ]);

    Sanctum::actingAs($superAdmin);

    $this->patchJson("/api/v1/role-assignments/{$assignment->id}/policy", ['policy_id' => $policy->policy_id])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['role_id']);
});

test('editing the policy fails validation for a nonexistent policy_id', function () {
    $superAdmin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN, 'status' => 'Activated']);
    $target     = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN]);

    $assignment = RoleAssignment::create([
        'user_id'    => $target->user_id,
        'role_id'    => SystemUser::ROLE_ADMIN,
        'status'     => RoleAssignment::STATUS_ACTIVE,
        'granted_at' => now(),
    ]);

    Sanctum::actingAs($superAdmin);

    $this->patchJson("/api/v1/role-assignments/{$assignment->id}/policy", ['policy_id' => 999])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['policy_id']);
});

test('a non-Super-Admin cannot edit a role assignment policy', function () {
    $admin  = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN, 'status' => 'Activated']);
    $target = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN]);
    $policy = Policy::create(['name' => 'Records Staff', 'permissions' => ['dashboard' => ['Access']]]);

    $assignment = RoleAssignment::create([
        'user_id'    => $target->user_id,
        'role_id'    => SystemUser::ROLE_ADMIN,
        'status'     => RoleAssignment::STATUS_ACTIVE,
        'granted_at' => now(),
    ]);

    Sanctum::actingAs($admin);

    // Route middleware 'role:4' rejects this before it ever reaches the
    // controller/policy — 403, same as any other role:4 group route.
    $this->patchJson("/api/v1/role-assignments/{$assignment->id}/policy", ['policy_id' => $policy->policy_id])
        ->assertStatus(403);
});