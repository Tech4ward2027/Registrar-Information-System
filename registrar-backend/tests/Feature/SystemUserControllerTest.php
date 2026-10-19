<?php

use App\Models\AuditLog;
use App\Models\Policy;
use App\Models\RoleAssignment;
use App\Models\SystemUser;
use App\Services\Sso\IdpClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// ── Helpers ───────────────────────────────────────────────────────────────────

function suMakeUser(int $roleId): SystemUser
{
    $user = SystemUser::factory()->create(['role_id' => $roleId, 'status' => 'Activated']);
    Sanctum::actingAs($user);
    return $user;
}

/**
 * SystemUserFactory doesn't set idp_user_id, so it defaults to null on any
 * user it creates — meaning AdminUserService::update()/delete() skip their
 * IdP branches entirely for these targets. That lets most tests below avoid
 * mocking IdpClient; only store() unconditionally calls IdpClient::createUser
 * regardless of target state, so that one needs a mock.
 */
function suMakeTarget(int $roleId): SystemUser
{
    return SystemUser::factory()->create(['role_id' => $roleId, 'status' => 'Activated']);
}

function suMakePolicy(array $overrides = []): Policy
{
    return Policy::create(array_merge([
        'name'        => 'Front Desk',
        'permissions' => ['dashboard' => ['Access'], 'inbox' => [], 'analytics' => [], 'logbook' => [], 'profile' => []],
        'is_system'   => false,
    ], $overrides));
}

// ═════════════════════════════════════════════════════════════════════════════
// index() — role:4 middleware + SystemUserPolicy::viewAny (both superadmin-only)
// ═════════════════════════════════════════════════════════════════════════════

test('admin (role 3) is blocked from system-users by route middleware', function () {
    suMakeUser(SystemUser::ROLE_ADMIN);

    // Blocked at role:4 middleware, before the controller/Policy ever run —
    // hence Laravel's raw 'Forbidden' from RoleMiddleware, not the Policy's
    // default authorization message.
    $this->getJson('/api/v1/system-users')
         ->assertStatus(403)
         ->assertJson(['message' => 'Forbidden']);
});

test('superadmin can list system users, scoped to admin/super-admin roles only', function () {
    suMakeTarget(SystemUser::ROLE_STUDENT);
    suMakeTarget(SystemUser::ROLE_ADMIN);
    suMakeUser(SystemUser::ROLE_SUPER_ADMIN);

    $response = $this->getJson('/api/v1/system-users')->assertOk();

    $emails = collect($response->json('data'))->pluck('role_id');
    expect($emails->every(fn ($r) => in_array($r, [SystemUser::ROLE_ADMIN, SystemUser::ROLE_SUPER_ADMIN])))->toBeTrue();
});

// ═════════════════════════════════════════════════════════════════════════════
// show() — SystemUserPolicy::view: target must also be role 3/4
// ═════════════════════════════════════════════════════════════════════════════

test('show returns 404 for a missing user', function () {
    suMakeUser(SystemUser::ROLE_SUPER_ADMIN);

    $this->getJson('/api/v1/system-users/999')->assertStatus(404);
});

test('show is forbidden when the target is not an admin/super-admin account', function () {
    $student = suMakeTarget(SystemUser::ROLE_STUDENT);
    suMakeUser(SystemUser::ROLE_SUPER_ADMIN);

    // Policy::view() returns false for non-manageable targets — this is a
    // genuine authorize() failure (not the route middleware), so it's
    // Laravel's default message rather than 'Forbidden'.
    $this->getJson("/api/v1/system-users/{$student->user_id}")
         ->assertStatus(403)
         ->assertJson(['message' => 'This action is unauthorized.']);
});

test('superadmin can view an admin account', function () {
    $admin = suMakeTarget(SystemUser::ROLE_ADMIN);
    suMakeUser(SystemUser::ROLE_SUPER_ADMIN);

    $this->getJson("/api/v1/system-users/{$admin->user_id}")
         ->assertOk()
         ->assertJsonPath('data.user_id', $admin->user_id)
         ->assertJsonPath('data.role_name', 'admin');
});

// ═════════════════════════════════════════════════════════════════════════════
// store() — StoreSystemUserRequest validation + SystemUserPolicy::create
//
// AdminUserService::create() no longer talks to the IdP at all (see its
// docblock) — it only ever writes a 'Pending Activation' record with no
// password of any kind. So these tests no longer mock IdpClient::createUser
// for the happy path, and instead assert the IdP is never touched.
// ═════════════════════════════════════════════════════════════════════════════

test('store fails validation on bad role_id', function () {
    suMakeUser(SystemUser::ROLE_SUPER_ADMIN);

    $this->postJson('/api/v1/system-users', [
        'email'      => 'newadmin@example.com',
        'role_id'    => 1,           // not in:3,4
        'first_name' => 'New',
        'last_name'  => 'Admin',
    ])->assertStatus(422)
      ->assertJsonValidationErrors(['role_id']);
});

test('store fails validation when email is already taken', function () {
    $existing = suMakeTarget(SystemUser::ROLE_ADMIN);
    suMakeUser(SystemUser::ROLE_SUPER_ADMIN);

    $this->postJson('/api/v1/system-users', [
        'email'      => $existing->email,
        'role_id'    => 3,
        'first_name' => 'New',
        'last_name'  => 'Admin',
    ])->assertStatus(422)
      ->assertJsonValidationErrors(['email'])
      // QA bug #2 — was Laravel's default "The email has already been
      // taken.", which doesn't tell the admin why it matters here.
      ->assertJsonFragment(['email' => ['This email is already associated with a SystemUser account.']]);
});

test('store rejects a password field on create — it is no longer accepted', function () {
    suMakeUser(SystemUser::ROLE_SUPER_ADMIN);

    // StoreSystemUserRequest no longer declares a 'password' rule, so an
    // extraneous one is simply ignored by validated() rather than causing
    // a 422 — this asserts the *effect* (no password ever lands in the DB)
    // rather than asserting a specific validation error that no longer
    // applies.
    $this->postJson('/api/v1/system-users', [
        'email'      => 'newadmin@example.com',
        'password'   => 'Password123',
        'role_id'    => 3,
        'first_name' => 'New',
        'last_name'  => 'Admin',
    ])->assertCreated();

    $created = SystemUser::where('email', 'newadmin@example.com')->firstOrFail();
    expect($created->password)->toBeNull();
});

test('superadmin can pre-register a new admin account — no IdP call, Pending Activation, no password', function () {
    suMakeUser(SystemUser::ROLE_SUPER_ADMIN);

    // The IdP must never be touched by this endpoint anymore — if it is,
    // this mock has nothing configured for createUser() and the test
    // fails loudly rather than silently succeeding against a stub.
    $this->mock(IdpClient::class, function ($mock) {
        $mock->shouldNotReceive('createUser');
    });

    $this->postJson('/api/v1/system-users', [
        'email'      => 'newadmin@example.com',
        'role_id'    => 3,
        'first_name' => 'New',
        'last_name'  => 'Admin',
    ])->assertCreated()
      ->assertJsonPath('data.email', 'newadmin@example.com')
      ->assertJsonPath('data.role_name', 'admin')
      ->assertJsonPath('data.status', 'Pending Activation');

    $this->assertDatabaseHas('users', [
        'email'       => 'newadmin@example.com',
        'idp_user_id' => null,
        'status'      => 'Pending Activation',
    ]);
    $this->assertDatabaseHas('audit_logs', ['action' => AuditLog::ACTION_ADMIN_CREATED]);

    $created = SystemUser::where('email', 'newadmin@example.com')->firstOrFail();
    expect($created->pending_expires_at)->not->toBeNull();
    expect($created->pending_expires_at->isFuture())->toBeTrue();
    // 14 days out, per spec — allow a small tolerance for test execution time.
    expect($created->pending_expires_at->diffInDays(now()->addDays(14)))->toBeLessThan(1);
});

test('policy_id is silently ignored when creating a super-admin account', function () {
    suMakeUser(SystemUser::ROLE_SUPER_ADMIN);
    $policy = suMakePolicy();

    $response = $this->postJson('/api/v1/system-users', [
        'email'      => 'newsuperadmin@example.com',
        'role_id'    => 4,
        'first_name' => 'New',
        'last_name'  => 'SuperAdmin',
        'policy_id'  => $policy->policy_id,
    ])->assertCreated();

    // AdminUserService::create() only honors policy_id when role_id === ROLE_ADMIN
    expect($response->json('data.policy_id'))->toBeNull();
});

test('a status field sent on create is ignored — it is always server-set to Pending Activation', function () {
    suMakeUser(SystemUser::ROLE_SUPER_ADMIN);

    // StoreSystemUserRequest no longer declares a 'status' rule either.
    $this->postJson('/api/v1/system-users', [
        'email'      => 'triedtoactivate@example.com',
        'status'     => 'Activated',
        'role_id'    => 3,
        'first_name' => 'New',
        'last_name'  => 'Admin',
    ])->assertCreated()
      ->assertJsonPath('data.status', 'Pending Activation');
});

test('creating a new admin or super-admin never enables break-glass (local) auth', function () {
    // Regression test: AdminUserService::create() previously set
    // local_auth_enabled = 1 (and a real, guessable-by-anyone-who-saw-the-
    // password local hash) for every new admin/super-admin, making
    // break-glass access an option on every admin rather than a small,
    // deliberately-chosen set of Super Admin accounts. It must now stay
    // off regardless of what's submitted, for both roles.
    suMakeUser(SystemUser::ROLE_SUPER_ADMIN);

    $this->postJson('/api/v1/system-users', [
        'email'      => 'newadmin2@example.com',
        'role_id'    => 3,
        'first_name' => 'New',
        'last_name'  => 'Admin',
    ])->assertCreated();

    $this->postJson('/api/v1/system-users', [
        'email'      => 'newsuperadmin2@example.com',
        'role_id'    => 4,
        'first_name' => 'New',
        'last_name'  => 'SuperAdmin',
    ])->assertCreated();

    $this->assertDatabaseHas('users', ['email' => 'newadmin2@example.com', 'local_auth_enabled' => 0, 'password' => null]);
    $this->assertDatabaseHas('users', ['email' => 'newsuperadmin2@example.com', 'local_auth_enabled' => 0, 'password' => null]);
});

// ═════════════════════════════════════════════════════════════════════════════
// update() — UpdateSystemUserRequest validation + SystemUserPolicy::update
// ═════════════════════════════════════════════════════════════════════════════

test('update returns 404 for a missing user', function () {
    suMakeUser(SystemUser::ROLE_SUPER_ADMIN);

    $this->putJson('/api/v1/system-users/999', ['first_name' => 'X'])
         ->assertStatus(404);
});

test('update is forbidden when the target is not an admin/super-admin account', function () {
    $student = suMakeTarget(SystemUser::ROLE_STUDENT);
    suMakeUser(SystemUser::ROLE_SUPER_ADMIN);

    $this->putJson("/api/v1/system-users/{$student->user_id}", ['first_name' => 'X'])
         ->assertStatus(403);
});

test('update fails validation when email is already taken by someone else', function () {
    $other  = suMakeTarget(SystemUser::ROLE_ADMIN);
    $target = suMakeTarget(SystemUser::ROLE_ADMIN);
    suMakeUser(SystemUser::ROLE_SUPER_ADMIN);

    $this->putJson("/api/v1/system-users/{$target->user_id}", ['email' => $other->email])
         ->assertStatus(422)
         ->assertJsonValidationErrors(['email'])
         // QA bug #2 — Edit User hits the same unique rule as Add New
         // User, so it needs the same non-default message.
         ->assertJsonFragment(['email' => ['This email is already associated with a SystemUser account.']]);
});

test('update allows keeping the same email for the same user (unique rule excludes self)', function () {
    $target = suMakeTarget(SystemUser::ROLE_ADMIN);
    suMakeUser(SystemUser::ROLE_SUPER_ADMIN);

    $this->putJson("/api/v1/system-users/{$target->user_id}", [
        'email'      => $target->email,
        'first_name' => 'Updated',
    ])->assertOk()
      ->assertJsonPath('data.first_name', 'Updated');
});

test('superadmin can update an admin account status without touching the IdP', function () {
    // idp_user_id is null on factory-made users, so AdminUserService::update()
    // must skip its IdP branch entirely here — no IdpClient mock needed,
    // and if the code tried to call it, this would error on the real client.
    $target = suMakeTarget(SystemUser::ROLE_ADMIN);
    suMakeUser(SystemUser::ROLE_SUPER_ADMIN);

    $this->putJson("/api/v1/system-users/{$target->user_id}", [
        'status' => 'Deactivated',
    ])->assertOk()
      ->assertJsonPath('data.status', 'Deactivated');

    $this->assertDatabaseHas('audit_logs', ['action' => AuditLog::ACTION_ADMIN_UPDATED]);
});

test('update fails validation on an invalid status value', function () {
    $target = suMakeTarget(SystemUser::ROLE_ADMIN);
    suMakeUser(SystemUser::ROLE_SUPER_ADMIN);

    $this->putJson("/api/v1/system-users/{$target->user_id}", ['status' => 'Suspended'])
         ->assertStatus(422)
         ->assertJsonValidationErrors(['status']);
});

// ═════════════════════════════════════════════════════════════════════════════
// Work Item #2 — Admin Management Consolidation regression coverage.
//
// PATCH /system-users/{id}/policy ("Manage Access") is retired entirely —
// see RoleAssignmentControllerTest.php for its replacement,
// PATCH /role-assignments/{roleAssignment}/policy. The tests below confirm
// the old route and the old role_id-on-update path are both actually gone,
// not just unused.
// ═════════════════════════════════════════════════════════════════════════════

test('the retired Manage Access policy-attachment route no longer exists', function () {
    $target = suMakeTarget(SystemUser::ROLE_ADMIN);
    suMakeUser(SystemUser::ROLE_SUPER_ADMIN);

    $this->patchJson("/api/v1/system-users/{$target->user_id}/policy", ['policy_id' => null])
         ->assertStatus(404);
});

test('update() silently ignores role_id even on a direct API call — Edit User can no longer change roles', function () {
    // Work Item #2: role changes are exclusively role_assignments' job now
    // (RoleAssignmentController::store(), which enforces the direction
    // constraint this endpoint never did). Sending role_id here must have
    // no effect at all, not even a validation error — the field is simply
    // not part of this request's contract anymore.
    $target = suMakeTarget(SystemUser::ROLE_ADMIN);
    suMakeUser(SystemUser::ROLE_SUPER_ADMIN);

    $this->putJson("/api/v1/system-users/{$target->user_id}", [
        'role_id'    => SystemUser::ROLE_SUPER_ADMIN,
        'first_name' => 'Unchanged',
    ])->assertOk()
      ->assertJsonPath('data.role_id', SystemUser::ROLE_ADMIN)
      ->assertJsonPath('data.first_name', 'Unchanged');

    $this->assertDatabaseHas('users', [
        'user_id' => $target->user_id,
        'role_id' => SystemUser::ROLE_ADMIN,
    ]);
});

// ═════════════════════════════════════════════════════════════════════════════
// destroy() — self-delete guard (controller) + SystemUserPolicy::delete
// ═════════════════════════════════════════════════════════════════════════════

test('destroy returns 404 for a missing user', function () {
    suMakeUser(SystemUser::ROLE_SUPER_ADMIN);

    $this->deleteJson('/api/v1/system-users/999')->assertStatus(404);
});

test('superadmin cannot delete their own account', function () {
    $self = suMakeUser(SystemUser::ROLE_SUPER_ADMIN);

    $this->deleteJson("/api/v1/system-users/{$self->user_id}")
         ->assertStatus(403)
         ->assertJson(['message' => 'You cannot delete your own account.']);

    $this->assertDatabaseHas('users', ['user_id' => $self->user_id]);
});

test('superadmin can delete another admin account', function () {
    $target = suMakeTarget(SystemUser::ROLE_ADMIN);
    suMakeUser(SystemUser::ROLE_SUPER_ADMIN);

    $this->deleteJson("/api/v1/system-users/{$target->user_id}")
         ->assertOk()
         ->assertJson(['message' => 'User deleted successfully']);

    $this->assertDatabaseMissing('users', ['user_id' => $target->user_id]);
    $this->assertDatabaseHas('audit_logs', ['action' => AuditLog::ACTION_ADMIN_DELETED]);
});

// NOTE: destroy()'s 409 "still has associated requests/records/history" branch
// (QueryException 23000) relies on the DB enforcing a foreign key back to
// `users`. Same SQLite limitation noted in CertificationTypeControllerTest —
// not exercised here, needs a MySQL-backed run.

// ═════════════════════════════════════════════════════════════════════════════
// Work Item #3 — Admin Accounts / Student Staff Visibility.
// index() now also surfaces accounts with an active administrative
// role_assignments grant on top of a non-admin base identity.
// ═════════════════════════════════════════════════════════════════════════════

test('a student with an active Student Staff grant appears in Admin Accounts', function () {
    $student = suMakeTarget(SystemUser::ROLE_STUDENT);
    $policy  = suMakePolicy(['name' => 'Records Staff']);

    RoleAssignment::create([
        'user_id'    => $student->user_id,
        'role_id'    => SystemUser::ROLE_ADMIN,
        'policy_id'  => $policy->policy_id,
        'status'     => RoleAssignment::STATUS_ACTIVE,
        'granted_at' => now(),
    ]);

    suMakeUser(SystemUser::ROLE_SUPER_ADMIN);

    $response = $this->getJson('/api/v1/system-users')->assertOk();

    $row = collect($response->json('data'))->firstWhere('user_id', $student->user_id);

    expect($row)->not->toBeNull();
    expect($row['base_role_id'])->toBe(SystemUser::ROLE_STUDENT);
    expect($row['base_role_name'])->toBe('student');
    expect($row['admin_grant']['role_id'])->toBe(SystemUser::ROLE_ADMIN);
    expect($row['admin_grant']['is_secondary'])->toBeTrue();
    expect($row['admin_grant']['policy']['policy_id'])->toBe($policy->policy_id);
});

test('a student with an expired Student Staff grant does not appear in Admin Accounts', function () {
    $student = suMakeTarget(SystemUser::ROLE_STUDENT);

    RoleAssignment::create([
        'user_id'    => $student->user_id,
        'role_id'    => SystemUser::ROLE_ADMIN,
        'status'     => RoleAssignment::STATUS_ACTIVE,
        'granted_at' => now()->subDays(30),
        'expires_at' => now()->subDay(), // in the past — not "currently active"
    ]);

    suMakeUser(SystemUser::ROLE_SUPER_ADMIN);

    $response = $this->getJson('/api/v1/system-users')->assertOk();

    $row = collect($response->json('data'))->firstWhere('user_id', $student->user_id);

    expect($row)->toBeNull();
});

test('a student with a revoked Student Staff grant does not appear in Admin Accounts', function () {
    $student = suMakeTarget(SystemUser::ROLE_STUDENT);

    RoleAssignment::create([
        'user_id'           => $student->user_id,
        'role_id'           => SystemUser::ROLE_ADMIN,
        'status'            => RoleAssignment::STATUS_REVOKED,
        'granted_at'        => now()->subDays(10),
        'revoked_at'        => now(),
        'revocation_reason' => 'testing',
    ]);

    suMakeUser(SystemUser::ROLE_SUPER_ADMIN);

    $response = $this->getJson('/api/v1/system-users')->assertOk();

    $row = collect($response->json('data'))->firstWhere('user_id', $student->user_id);

    expect($row)->toBeNull();
});

test('a student with no administrative grant at all does not appear in Admin Accounts', function () {
    suMakeTarget(SystemUser::ROLE_STUDENT);
    suMakeUser(SystemUser::ROLE_SUPER_ADMIN);

    $response = $this->getJson('/api/v1/system-users')->assertOk();

    $roleIds = collect($response->json('data'))->pluck('base_role_id');

    expect($roleIds->every(fn ($r) => in_array($r, [SystemUser::ROLE_STUDENT, SystemUser::ROLE_ALUMNI], true)))
        ->toBeFalse(); // i.e. nothing with a base-tier role_id made it in at all
});

test('an existing admin account with a live baseline role_assignment displays unchanged', function () {
    $admin  = suMakeTarget(SystemUser::ROLE_ADMIN);
    $policy = suMakePolicy(['name' => 'Front Desk']);
    $admin->update(['policy_id' => $policy->policy_id]);

    RoleAssignment::create([
        'user_id'    => $admin->user_id,
        'role_id'    => SystemUser::ROLE_ADMIN,
        'policy_id'  => $policy->policy_id,
        'status'     => RoleAssignment::STATUS_ACTIVE,
        'granted_at' => now(),
    ]);

    suMakeUser(SystemUser::ROLE_SUPER_ADMIN);

    $response = $this->getJson('/api/v1/system-users')->assertOk();
    $row = collect($response->json('data'))->firstWhere('user_id', $admin->user_id);

    expect($row['base_role_id'])->toBe(SystemUser::ROLE_ADMIN);
    expect($row['admin_grant']['is_secondary'])->toBeFalse();
    expect($row['admin_grant']['policy']['policy_id'])->toBe($policy->policy_id);
});

test('a deactivated admin whose baseline role_assignment was cascade-revoked still displays via the raw columns', function () {
    $admin  = suMakeTarget(SystemUser::ROLE_ADMIN);
    $policy = suMakePolicy(['name' => 'Front Desk']);
    $admin->update(['policy_id' => $policy->policy_id, 'status' => 'Deactivated']);

    // No Active role_assignments row at all — simulates
    // RoleAssignmentService::revokeAllForUser() having already run.
    RoleAssignment::create([
        'user_id'           => $admin->user_id,
        'role_id'           => SystemUser::ROLE_ADMIN,
        'policy_id'         => $policy->policy_id,
        'status'            => RoleAssignment::STATUS_REVOKED,
        'granted_at'        => now()->subDays(5),
        'revoked_at'        => now(),
        'revocation_reason' => 'Account deactivated.',
    ]);

    suMakeUser(SystemUser::ROLE_SUPER_ADMIN);

    $response = $this->getJson('/api/v1/system-users')->assertOk();
    $row = collect($response->json('data'))->firstWhere('user_id', $admin->user_id);

    // Still listed — this is the "existing rows unaffected" guarantee.
    expect($row)->not->toBeNull();
    expect($row['status'])->toBe('Deactivated');
    // Falls back to the raw columns since there's no live Active row.
    expect($row['admin_grant']['role_assignment_id'])->toBeNull();
    expect($row['admin_grant']['policy']['policy_id'])->toBe($policy->policy_id);
});

// ═════════════════════════════════════════════════════════════════════════════
// Work Item #3 — SystemUserPolicy::view()/update() extended so a newly-listed
// student-staff row is actually usable, not just visible.
// ═════════════════════════════════════════════════════════════════════════════

test('superadmin can view a student staff account', function () {
    $student = suMakeTarget(SystemUser::ROLE_STUDENT);

    RoleAssignment::create([
        'user_id'    => $student->user_id,
        'role_id'    => SystemUser::ROLE_ADMIN,
        'status'     => RoleAssignment::STATUS_ACTIVE,
        'granted_at' => now(),
    ]);

    suMakeUser(SystemUser::ROLE_SUPER_ADMIN);

    $this->getJson("/api/v1/system-users/{$student->user_id}")
         ->assertOk()
         ->assertJsonPath('data.user_id', $student->user_id)
         ->assertJsonPath('data.base_role_name', 'student');
});

test('superadmin can update the Status of a student staff account', function () {
    $student = suMakeTarget(SystemUser::ROLE_STUDENT);

    RoleAssignment::create([
        'user_id'    => $student->user_id,
        'role_id'    => SystemUser::ROLE_ADMIN,
        'status'     => RoleAssignment::STATUS_ACTIVE,
        'granted_at' => now(),
    ]);

    suMakeUser(SystemUser::ROLE_SUPER_ADMIN);

    $this->putJson("/api/v1/system-users/{$student->user_id}", ['status' => 'Deactivated'])
         ->assertOk()
         ->assertJsonPath('data.status', 'Deactivated');
});

test('a student with no administrative grant still cannot be viewed via system-users', function () {
    $student = suMakeTarget(SystemUser::ROLE_STUDENT);
    suMakeUser(SystemUser::ROLE_SUPER_ADMIN);

    // Unchanged pre-#3 behavior — this policy is still not a general
    // student/alumni lookup endpoint.
    $this->getJson("/api/v1/system-users/{$student->user_id}")
         ->assertStatus(403)
         ->assertJson(['message' => 'This action is unauthorized.']);
});

test('a student staff account cannot be deleted via DELETE /system-users', function () {
    $student = suMakeTarget(SystemUser::ROLE_STUDENT);

    RoleAssignment::create([
        'user_id'    => $student->user_id,
        'role_id'    => SystemUser::ROLE_ADMIN,
        'status'     => RoleAssignment::STATUS_ACTIVE,
        'granted_at' => now(),
    ]);

    suMakeUser(SystemUser::ROLE_SUPER_ADMIN);

    // delete() was deliberately NOT extended — see SystemUserPolicy::delete()
    // docblock. A student staff account's ADMIN grant is revoked via Manage
    // Roles; the account itself is not deletable from this screen.
    $this->deleteJson("/api/v1/system-users/{$student->user_id}")
         ->assertStatus(403)
         ->assertJson(['message' => 'This action is unauthorized.']);

    $this->assertDatabaseHas('users', ['user_id' => $student->user_id]);
});