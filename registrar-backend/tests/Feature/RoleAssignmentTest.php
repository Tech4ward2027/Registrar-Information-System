<?php

use App\Models\AuditLog;
use App\Models\Policy;
use App\Models\RoleAssignment;
use App\Models\SystemUser;
use App\Services\RoleAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

// Locally-scoped helper name (not a generic "actingRequest") to avoid any
// risk of colliding with a same-named helper introduced later in another
// Feature test file — Pest loads all Feature/*.php into one process.
function roleAssignmentTestRequest(SystemUser $actor): Request
{
    $request = Request::create('/test', 'POST');
    $request->setUserResolver(fn () => $actor);
    return $request;
}

// ═════════════════════════════════════════════════════════════════════════════
// RoleAssignmentService::grant()
// ═════════════════════════════════════════════════════════════════════════════

test('grants a second, concurrent role to a user who already holds one', function () {
    $superAdmin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN]);
    $student    = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT]);
    $policy     = Policy::create(['name' => 'Dashboard + Inbox Only', 'permissions' => ['dashboard' => ['Access'], 'inbox' => ['Access']]]);

    $service = app(RoleAssignmentService::class);

    $assignment = $service->grant([
        'user_id'    => $student->user_id,
        'role_id'    => SystemUser::ROLE_ADMIN,
        'policy_id'  => $policy->policy_id,
        'expires_at' => now()->addMonths(4),
    ], roleAssignmentTestRequest($superAdmin));

    expect($assignment->status)->toBe(RoleAssignment::STATUS_ACTIVE);
    expect($student->activeRoleAssignments()->pluck('role_id')->all())
        ->toContain(SystemUser::ROLE_ADMIN);

    $this->assertDatabaseHas('audit_logs', [
        'action'         => AuditLog::ACTION_ROLE_ASSIGNED,
        'target_user_id' => $student->user_id,
    ]);
});

test('grant() backfills a missing baseline row for the users current role before adding the new one', function () {
    // Gap 1 regression: $student here (like every SystemUser::factory()
    // fixture) has ZERO role_assignments rows going in — this is the
    // "only the new row exists" shape that used to slip through
    // grant() untouched. After this fix, grant() must leave the user
    // holding BOTH their original role and the newly granted one.
    $superAdmin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN]);
    $student    = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT]);

    expect(RoleAssignment::where('user_id', $student->user_id)->exists())->toBeFalse();

    app(RoleAssignmentService::class)->grant([
        'user_id'    => $student->user_id,
        'role_id'    => SystemUser::ROLE_ADMIN,
        'expires_at' => now()->addMonths(4),
    ], roleAssignmentTestRequest($superAdmin));

    $rows = RoleAssignment::where('user_id', $student->user_id)
        ->where('status', RoleAssignment::STATUS_ACTIVE)
        ->pluck('role_id');

    expect($rows)->toHaveCount(2);
    expect($rows)->toContain(SystemUser::ROLE_STUDENT, SystemUser::ROLE_ADMIN);

    // The backfilled baseline row is system-derived, not a human grant —
    // it must not show up as if a Super Admin explicitly granted the
    // user their own pre-existing role.
    $baseline = RoleAssignment::where('user_id', $student->user_id)
        ->where('role_id', SystemUser::ROLE_STUDENT)
        ->first();
    expect($baseline->granted_by)->toBeNull();
});

test('rejects granting a role to a Deactivated account', function () {
    // Bug #5 (QA) — grant() must enforce this itself and not rely solely
    // on searchGrantableUsers() filtering the picker, since a direct API
    // call bypasses that filter entirely.
    $superAdmin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN]);
    $student    = SystemUser::factory()->create([
        'role_id' => SystemUser::ROLE_STUDENT,
        'status'  => 'Deactivated',
    ]);

    $service = app(RoleAssignmentService::class);

    expect(fn () => $service->grant([
        'user_id'    => $student->user_id,
        'role_id'    => SystemUser::ROLE_ADMIN,
        'expires_at' => now()->addMonths(4),
    ], roleAssignmentTestRequest($superAdmin)))->toThrow(ValidationException::class);

    expect(RoleAssignment::where('user_id', $student->user_id)->exists())->toBeFalse();
});

test('rejects granting a role to a Pending Activation account', function () {
    $superAdmin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN]);
    $pending    = SystemUser::factory()->create([
        'role_id' => SystemUser::ROLE_ADMIN,
        'status'  => 'Pending Activation',
    ]);

    $service = app(RoleAssignmentService::class);

    expect(fn () => $service->grant([
        'user_id'    => $pending->user_id,
        'role_id'    => SystemUser::ROLE_ADMIN,
        'expires_at' => now()->addMonths(4),
    ], roleAssignmentTestRequest($superAdmin)))->toThrow(ValidationException::class);
});

test('rejects granting a role the user already actively holds', function () {
    $superAdmin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN]);
    $admin      = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN]);

    RoleAssignment::create([
        'user_id' => $admin->user_id,
        'role_id' => SystemUser::ROLE_ADMIN,
        'status'  => RoleAssignment::STATUS_ACTIVE,
    ]);

    $service = app(RoleAssignmentService::class);

    expect(fn () => $service->grant([
        'user_id' => $admin->user_id,
        'role_id' => SystemUser::ROLE_ADMIN,
    ], roleAssignmentTestRequest($superAdmin)))->toThrow(ValidationException::class);
});

// ═════════════════════════════════════════════════════════════════════════════
// Work Item #2 — Admin Management Consolidation:
// grant() direction-constraint validation
// ═════════════════════════════════════════════════════════════════════════════

test('allows granting an Admin-tier role to a base-identity (Student) account', function () {
    $superAdmin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN]);
    $student    = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT]);
    $policy     = Policy::create(['name' => 'Front Desk', 'permissions' => ['dashboard' => ['Access']]]);

    $assignment = app(RoleAssignmentService::class)->grant([
        'user_id'   => $student->user_id,
        'role_id'   => SystemUser::ROLE_ADMIN,
        'policy_id' => $policy->policy_id,
    ], roleAssignmentTestRequest($superAdmin));

    expect($assignment->role_id)->toBe(SystemUser::ROLE_ADMIN);
});

test('allows granting an Admin-tier role to a base-identity (Alumni) account', function () {
    $superAdmin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN]);
    $alumni     = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ALUMNI]);
    $policy     = Policy::create(['name' => 'Front Desk', 'permissions' => ['dashboard' => ['Access']]]);

    $assignment = app(RoleAssignmentService::class)->grant([
        'user_id'   => $alumni->user_id,
        'role_id'   => SystemUser::ROLE_SUPER_ADMIN,
    ], roleAssignmentTestRequest($superAdmin));

    expect($assignment->role_id)->toBe(SystemUser::ROLE_SUPER_ADMIN);
});

test('rejects granting a Student role to an account whose primary role is Admin', function () {
    $superAdmin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN]);
    $admin      = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN]);

    $service = app(RoleAssignmentService::class);

    expect(fn () => $service->grant([
        'user_id' => $admin->user_id,
        'role_id' => SystemUser::ROLE_STUDENT,
    ], roleAssignmentTestRequest($superAdmin)))->toThrow(ValidationException::class);

    expect(RoleAssignment::where('user_id', $admin->user_id)->where('role_id', SystemUser::ROLE_STUDENT)->exists())
        ->toBeFalse();
});

test('rejects granting an Alumni role to an account whose primary role is Super Admin', function () {
    $superAdmin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN]);
    $target     = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN]);

    $service = app(RoleAssignmentService::class);

    expect(fn () => $service->grant([
        'user_id' => $target->user_id,
        'role_id' => SystemUser::ROLE_ALUMNI,
    ], roleAssignmentTestRequest($superAdmin)))->toThrow(ValidationException::class);
});

test('direction constraint is keyed off the raw primary role_id, not an assumed/switched role', function () {
    // Regression guard: even if some future code path resolves a
    // session-assumed role for the ACTOR making the grant call, the
    // constraint here must still be evaluated against the TARGET's own
    // raw users.role_id — never anything session/assumption-based.
    $superAdmin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN]);
    $admin      = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN]);

    expect($admin->role_id)->toBe(SystemUser::ROLE_ADMIN);

    expect(fn () => app(RoleAssignmentService::class)->grant([
        'user_id' => $admin->user_id,
        'role_id' => SystemUser::ROLE_ALUMNI,
    ], roleAssignmentTestRequest($superAdmin)))->toThrow(ValidationException::class);
});

// ═════════════════════════════════════════════════════════════════════════════
// Work Item #2 — Admin Management Consolidation:
// RoleAssignmentService::editPolicy() — in-place policy edit, no
// revoke/regrant cycle. Direct replacement for the retired
// PolicyService::attachToUser().
// ═════════════════════════════════════════════════════════════════════════════

test('editPolicy() updates the assignment and, for a baseline row, the mirrored users.policy_id', function () {
    $superAdmin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN]);
    $oldPolicy  = Policy::create(['name' => 'Front Desk', 'permissions' => ['dashboard' => ['Access']]]);
    $newPolicy  = Policy::create(['name' => 'Records Staff', 'permissions' => ['dashboard' => ['Access'], 'inbox' => ['Access']]]);

    $admin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN, 'policy_id' => $oldPolicy->policy_id]);

    // The baseline row every admin now gets (see
    // UserProvisioningService::ensureBaselineRoleAssignment()) — its
    // role_id matches the user's own primary role_id.
    $baseline = RoleAssignment::create([
        'user_id'    => $admin->user_id,
        'role_id'    => SystemUser::ROLE_ADMIN,
        'policy_id'  => $oldPolicy->policy_id,
        'status'     => RoleAssignment::STATUS_ACTIVE,
        'granted_at' => now(),
    ]);

    $updated = app(RoleAssignmentService::class)->editPolicy(
        $baseline,
        $newPolicy->policy_id,
        roleAssignmentTestRequest($superAdmin)
    );

    expect($updated->policy_id)->toBe($newPolicy->policy_id);
    expect($admin->fresh()->policy_id)->toBe($newPolicy->policy_id);

    $this->assertDatabaseHas('audit_logs', [
        'action'         => AuditLog::ACTION_ROLE_POLICY_EDITED,
        'target_user_id' => $admin->user_id,
    ]);
});

test('editPolicy() detaching a policy (null) also clears the mirrored users.policy_id for a baseline row', function () {
    $superAdmin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN]);
    $policy     = Policy::create(['name' => 'Front Desk', 'permissions' => ['dashboard' => ['Access']]]);
    $admin      = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN, 'policy_id' => $policy->policy_id]);

    $baseline = RoleAssignment::create([
        'user_id'    => $admin->user_id,
        'role_id'    => SystemUser::ROLE_ADMIN,
        'policy_id'  => $policy->policy_id,
        'status'     => RoleAssignment::STATUS_ACTIVE,
        'granted_at' => now(),
    ]);

    app(RoleAssignmentService::class)->editPolicy($baseline, null, roleAssignmentTestRequest($superAdmin));

    expect($baseline->fresh()->policy_id)->toBeNull();
    expect($admin->fresh()->policy_id)->toBeNull();
});

test('editPolicy() on a secondary (student-staff) grant does not touch the users primary policy_id', function () {
    $superAdmin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN]);
    $oldPolicy  = Policy::create(['name' => 'Front Desk', 'permissions' => ['dashboard' => ['Access']]]);
    $newPolicy  = Policy::create(['name' => 'Records Staff', 'permissions' => ['dashboard' => ['Access']]]);

    // Primary role is Student — users.policy_id is not meaningful for this
    // account at all. The Admin grant below is a SECONDARY, concurrent
    // role (the "student staff" case), not this user's baseline row.
    $studentStaff = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT, 'policy_id' => null]);

    $secondaryGrant = RoleAssignment::create([
        'user_id'    => $studentStaff->user_id,
        'role_id'    => SystemUser::ROLE_ADMIN,
        'policy_id'  => $oldPolicy->policy_id,
        'status'     => RoleAssignment::STATUS_ACTIVE,
        'granted_by' => $superAdmin->user_id,
        'granted_at' => now(),
    ]);

    app(RoleAssignmentService::class)->editPolicy($secondaryGrant, $newPolicy->policy_id, roleAssignmentTestRequest($superAdmin));

    expect($secondaryGrant->fresh()->policy_id)->toBe($newPolicy->policy_id);
    // users.policy_id must stay untouched — this grant isn't the user's
    // baseline/primary row (their primary role is Student, not Admin).
    expect($studentStaff->fresh()->policy_id)->toBeNull();
});

test('editPolicy() rejects a non-Admin assignment', function () {
    $superAdmin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN]);
    $student    = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT]);
    $policy     = Policy::create(['name' => 'Front Desk', 'permissions' => ['dashboard' => ['Access']]]);

    $studentAssignment = RoleAssignment::create([
        'user_id'    => $student->user_id,
        'role_id'    => SystemUser::ROLE_STUDENT,
        'status'     => RoleAssignment::STATUS_ACTIVE,
        'granted_at' => now(),
    ]);

    $service = app(RoleAssignmentService::class);

    expect(fn () => $service->editPolicy(
        $studentAssignment,
        $policy->policy_id,
        roleAssignmentTestRequest($superAdmin)
    ))->toThrow(ValidationException::class);
});

test('editPolicy() rejects editing a Revoked assignment', function () {
    $superAdmin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN]);
    $policy     = Policy::create(['name' => 'Front Desk', 'permissions' => ['dashboard' => ['Access']]]);
    $admin      = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN]);

    $revoked = RoleAssignment::create([
        'user_id'    => $admin->user_id,
        'role_id'    => SystemUser::ROLE_ADMIN,
        'policy_id'  => $policy->policy_id,
        'status'     => RoleAssignment::STATUS_REVOKED,
        'granted_at' => now(),
        'revoked_at' => now(),
    ]);

    $service = app(RoleAssignmentService::class);

    expect(fn () => $service->editPolicy(
        $revoked,
        $policy->policy_id,
        roleAssignmentTestRequest($superAdmin)
    ))->toThrow(ValidationException::class);
});

test('editPolicy() does not touch another users role_assignments row or policy_id', function () {
    $superAdmin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN]);
    $oldPolicy  = Policy::create(['name' => 'Front Desk', 'permissions' => ['dashboard' => ['Access']]]);
    $newPolicy  = Policy::create(['name' => 'Records Staff', 'permissions' => ['dashboard' => ['Access']]]);

    $admin      = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN, 'policy_id' => $oldPolicy->policy_id]);
    $otherAdmin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN, 'policy_id' => $oldPolicy->policy_id]);

    $baseline = RoleAssignment::create([
        'user_id'    => $admin->user_id,
        'role_id'    => SystemUser::ROLE_ADMIN,
        'policy_id'  => $oldPolicy->policy_id,
        'status'     => RoleAssignment::STATUS_ACTIVE,
        'granted_at' => now(),
    ]);

    $otherBaseline = RoleAssignment::create([
        'user_id'    => $otherAdmin->user_id,
        'role_id'    => SystemUser::ROLE_ADMIN,
        'policy_id'  => $oldPolicy->policy_id,
        'status'     => RoleAssignment::STATUS_ACTIVE,
        'granted_at' => now(),
    ]);

    app(RoleAssignmentService::class)->editPolicy($baseline, $newPolicy->policy_id, roleAssignmentTestRequest($superAdmin));

    expect($otherBaseline->fresh()->policy_id)->toBe($oldPolicy->policy_id);
    expect($otherAdmin->fresh()->policy_id)->toBe($oldPolicy->policy_id);
});

// ═════════════════════════════════════════════════════════════════════════════
// RoleAssignmentService::revoke()  — explicit "they left" offboarding
// ═════════════════════════════════════════════════════════════════════════════

test('revoking one role assignment does not touch the users other active assignments', function () {
    $superAdmin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN]);
    $person     = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT]);

    $studentAssignment = RoleAssignment::create([
        'user_id' => $person->user_id,
        'role_id' => SystemUser::ROLE_STUDENT,
        'status'  => RoleAssignment::STATUS_ACTIVE,
    ]);

    $adminAssignment = RoleAssignment::create([
        'user_id' => $person->user_id,
        'role_id' => SystemUser::ROLE_ADMIN,
        'status'  => RoleAssignment::STATUS_ACTIVE,
    ]);

    $service = app(RoleAssignmentService::class);
    $service->revoke($adminAssignment, 'No longer working the front desk.', roleAssignmentTestRequest($superAdmin));

    expect($adminAssignment->fresh()->status)->toBe(RoleAssignment::STATUS_REVOKED);
    expect($studentAssignment->fresh()->status)->toBe(RoleAssignment::STATUS_ACTIVE);

    $this->assertDatabaseHas('audit_logs', [
        'action'         => AuditLog::ACTION_ROLE_REVOKED,
        'target_user_id' => $person->user_id,
    ]);
});

test('revoking a role assignment invalidates the users existing sessions', function () {
    $superAdmin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN]);
    $person     = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN]);

    $person->createToken('sanctum-idp');
    expect($person->tokens()->count())->toBe(1);

    // Two Active rows so this revoke isn't blocked by the "can't revoke
    // someone's only active role" guard below — that guard is exactly
    // what this test would otherwise trip.
    RoleAssignment::create([
        'user_id' => $person->user_id,
        'role_id' => SystemUser::ROLE_STUDENT,
        'status'  => RoleAssignment::STATUS_ACTIVE,
    ]);

    $adminAssignment = RoleAssignment::create([
        'user_id' => $person->user_id,
        'role_id' => SystemUser::ROLE_ADMIN,
        'status'  => RoleAssignment::STATUS_ACTIVE,
    ]);

    app(RoleAssignmentService::class)->revoke($adminAssignment, 'Left the office.', roleAssignmentTestRequest($superAdmin));

    expect($person->tokens()->count())->toBe(0);
});

test('rejects revoking an assignment that is already revoked', function () {
    $superAdmin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN]);
    $person     = SystemUser::factory()->create();

    $assignment = RoleAssignment::create([
        'user_id'    => $person->user_id,
        'role_id'    => SystemUser::ROLE_ADMIN,
        'status'     => RoleAssignment::STATUS_REVOKED,
        'revoked_at' => now(),
    ]);

    $service = app(RoleAssignmentService::class);

    expect(fn () => $service->revoke($assignment, 'again', roleAssignmentTestRequest($superAdmin)))
        ->toThrow(ValidationException::class);
});

test('rejects revoking a users only active role assignment, pointing at deactivation instead', function () {
    // Gap #2 regression: revoking someone's last remaining role_assignments
    // row used to succeed and looked like it worked (status flips to
    // Revoked, tokens get deleted) but had zero real effect — the person's
    // NEXT login resolves their role straight from the untouched
    // users.role_id column and they're back in with identical access.
    $superAdmin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN]);
    $person     = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN]);

    $onlyAssignment = RoleAssignment::create([
        'user_id' => $person->user_id,
        'role_id' => SystemUser::ROLE_ADMIN,
        'status'  => RoleAssignment::STATUS_ACTIVE,
    ]);

    $service = app(RoleAssignmentService::class);

    expect(fn () => $service->revoke($onlyAssignment, 'trying to offboard', roleAssignmentTestRequest($superAdmin)))
        ->toThrow(ValidationException::class);

    // Nothing should have changed — the guard fires before any mutation.
    expect($onlyAssignment->fresh()->status)->toBe(RoleAssignment::STATUS_ACTIVE);
});

test('allows revoking a role once a second active role no longer makes it the last one', function () {
    $superAdmin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN]);
    $person     = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT]);

    $studentAssignment = RoleAssignment::create([
        'user_id' => $person->user_id,
        'role_id' => SystemUser::ROLE_STUDENT,
        'status'  => RoleAssignment::STATUS_ACTIVE,
    ]);

    $adminAssignment = RoleAssignment::create([
        'user_id' => $person->user_id,
        'role_id' => SystemUser::ROLE_ADMIN,
        'status'  => RoleAssignment::STATUS_ACTIVE,
    ]);

    $service = app(RoleAssignmentService::class);
    $service->revoke($adminAssignment, 'No longer working the front desk.', roleAssignmentTestRequest($superAdmin));

    expect($adminAssignment->fresh()->status)->toBe(RoleAssignment::STATUS_REVOKED);
    expect($studentAssignment->fresh()->status)->toBe(RoleAssignment::STATUS_ACTIVE);
});

// ═════════════════════════════════════════════════════════════════════════════
// `php artisan role-assignments:expire` — automatic offboarding
// (the "graduated / grant not renewed" path — see command docblock for
// why this is time-boxed rather than event-driven)
// ═════════════════════════════════════════════════════════════════════════════

test('expires a role assignment past its expires_at and revokes tokens', function () {
    $person = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT]);
    $person->createToken('sanctum-idp');

    $expiredAssignment = RoleAssignment::create([
        'user_id'    => $person->user_id,
        'role_id'    => SystemUser::ROLE_ADMIN,
        'status'     => RoleAssignment::STATUS_ACTIVE,
        'expires_at' => now()->subDay(),
    ]);

    $stillGood = RoleAssignment::create([
        'user_id'    => $person->user_id,
        'role_id'    => SystemUser::ROLE_STUDENT,
        'status'     => RoleAssignment::STATUS_ACTIVE,
        'expires_at' => null,
    ]);

    $this->artisan('role-assignments:expire')->assertExitCode(0);

    expect($expiredAssignment->fresh()->status)->toBe(RoleAssignment::STATUS_EXPIRED);
    expect($stillGood->fresh()->status)->toBe(RoleAssignment::STATUS_ACTIVE);
    expect($person->tokens()->count())->toBe(0);

    $this->assertDatabaseHas('audit_logs', [
        'action'             => AuditLog::ACTION_ROLE_EXPIRED,
        'target_user_id'     => $person->user_id,
    ]);
});

test('does not touch an assignment with no expires_at (indefinite)', function () {
    $person = SystemUser::factory()->create();

    $indefinite = RoleAssignment::create([
        'user_id'    => $person->user_id,
        'role_id'    => SystemUser::ROLE_ADMIN,
        'status'     => RoleAssignment::STATUS_ACTIVE,
        'expires_at' => null,
    ]);

    $this->artisan('role-assignments:expire')->assertExitCode(0);

    expect($indefinite->fresh()->status)->toBe(RoleAssignment::STATUS_ACTIVE);
});

// ═════════════════════════════════════════════════════════════════════════════
// RoleAssignmentService::switchTo()  — Step 3, session-scoped role switching
// ═════════════════════════════════════════════════════════════════════════════

test('switches to a role the user actively holds and stamps the new token', function () {
    // Base account is Student; the Admin side is a second, concurrent
    // grant — the "student staff" shape this whole feature exists for.
    $person = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT]);

    $studentAssignment = RoleAssignment::create([
        'user_id' => $person->user_id,
        'role_id' => SystemUser::ROLE_STUDENT,
        'status'  => RoleAssignment::STATUS_ACTIVE,
    ]);

    $adminAssignment = RoleAssignment::create([
        'user_id' => $person->user_id,
        'role_id' => SystemUser::ROLE_ADMIN,
        'status'  => RoleAssignment::STATUS_ACTIVE,
    ]);

    $person->createToken('sanctum-idp');
    expect($person->tokens()->count())->toBe(1);

    $result = app(RoleAssignmentService::class)
        ->switchTo($person, SystemUser::ROLE_ADMIN, roleAssignmentTestRequest($person));

    expect($result['assignment']->id)->toBe($adminAssignment->id);
    expect($result['token'])->toBeString()->not->toBeEmpty();

    // Exactly one token survives the switch (old deleted, new issued —
    // not "old kept plus a new one added").
    $person->refresh();
    expect($person->tokens()->count())->toBe(1);
    expect($person->tokens()->first()->active_role_assignment_id)->toBe($adminAssignment->id);
    // Auth-method marker preserved so logout() still knows which flow to run.
    expect($person->tokens()->first()->name)->toBe('sanctum-idp');

    // The OTHER active assignment is completely untouched by the switch.
    expect($studentAssignment->fresh()->status)->toBe(RoleAssignment::STATUS_ACTIVE);

    $this->assertDatabaseHas('audit_logs', [
        'action'         => AuditLog::ACTION_ROLE_SWITCHED,
        'target_user_id' => $person->user_id,
    ]);
});

test('rejects switching to a role the user does not actively hold', function () {
    $person = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT]);

    RoleAssignment::create([
        'user_id' => $person->user_id,
        'role_id' => SystemUser::ROLE_STUDENT,
        'status'  => RoleAssignment::STATUS_ACTIVE,
    ]);

    expect(fn () => app(RoleAssignmentService::class)
        ->switchTo($person, SystemUser::ROLE_ADMIN, roleAssignmentTestRequest($person)))
        ->toThrow(ValidationException::class);
});

test('rejects switching to an assignment that has been revoked', function () {
    $person = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT]);

    RoleAssignment::create([
        'user_id'    => $person->user_id,
        'role_id'    => SystemUser::ROLE_ADMIN,
        'status'     => RoleAssignment::STATUS_REVOKED,
        'revoked_at' => now(),
    ]);

    expect(fn () => app(RoleAssignmentService::class)
        ->switchTo($person, SystemUser::ROLE_ADMIN, roleAssignmentTestRequest($person)))
        ->toThrow(ValidationException::class);
});

test('rejects switching to an assignment whose status is still Active but whose expires_at has already elapsed', function () {
    // Guards the gap between "expires_at elapsed" and "the daily sweep
    // (role-assignments:expire) actually ran" — isCurrentlyActive() must
    // be checked live, not just the status column, see
    // RoleAssignment::isCurrentlyActive().
    $person = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT]);

    RoleAssignment::create([
        'user_id'    => $person->user_id,
        'role_id'    => SystemUser::ROLE_ADMIN,
        'status'     => RoleAssignment::STATUS_ACTIVE,
        'expires_at' => now()->subMinute(),
    ]);

    expect(fn () => app(RoleAssignmentService::class)
        ->switchTo($person, SystemUser::ROLE_ADMIN, roleAssignmentTestRequest($person)))
        ->toThrow(ValidationException::class);
});

// ═════════════════════════════════════════════════════════════════════════════
// End-to-end: a revoked/expired assignment's session must lose access on
// the VERY NEXT request — not just have its DB row flip. Exercises the
// real HTTP + Sanctum token layer, not just the service in isolation.
// ═════════════════════════════════════════════════════════════════════════════

test('a session that switched to a role loses that live token the instant the assignment is revoked', function () {
    $person = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT, 'status' => 'Activated']);

    RoleAssignment::create([
        'user_id' => $person->user_id,
        'role_id' => SystemUser::ROLE_STUDENT,
        'status'  => RoleAssignment::STATUS_ACTIVE,
    ]);

    $superAdminAssignment = RoleAssignment::create([
        'user_id' => $person->user_id,
        'role_id' => SystemUser::ROLE_SUPER_ADMIN,
        'status'  => RoleAssignment::STATUS_ACTIVE,
    ]);

    $result         = app(RoleAssignmentService::class)
        ->switchTo($person, SystemUser::ROLE_SUPER_ADMIN, roleAssignmentTestRequest($person));
    $plainTextToken = $result['token'];

    // Prove the switch actually unlocked a Super-Admin-only route
    // (GET /api/v1/audit-logs is behind 'role:4') using the real bearer
    // token, not Sanctum::actingAs().
    $this->withHeader('Authorization', "Bearer {$plainTextToken}")
        ->getJson('/api/v1/audit-logs')
        ->assertOk();

    $offboardingSuperAdmin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN]);
    app(RoleAssignmentService::class)->revoke(
        $superAdminAssignment->fresh(),
        'Offboarded — no longer super admin staff.',
        roleAssignmentTestRequest($offboardingSuperAdmin)
    );

    // The exact same token that worked a moment ago must now be rejected
    // outright (401, not merely 403) — revoke() deletes every token on
    // the account, so this isn't "lost Super Admin, kept Student", it's
    // "this session doesn't authenticate at all anymore" (see
    // RoleAssignmentService::revoke() docblock on why it's this blunt).
    //
    // Laravel's Sanctum guard (RequestGuard) caches the resolved user for
    // the lifetime of the guard instance, and that instance persists
    // across every HTTP call made within this single test method. Without
    // forgetting it here, this call would return the user cached by the
    // /api/v1/audit-logs call above instead of re-validating the (now
    // deleted) token against the database — a Laravel/Sanctum testing
    // quirk that never occurs in production.
    $this->app['auth']->forgetGuards();

    $this->withHeader('Authorization', "Bearer {$plainTextToken}")
        ->getJson('/api/v1/audit-logs')
        ->assertStatus(401);
});

test('a role assignment that lapses via the daily sweep invalidates its live token on the next request', function () {
    $person = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT, 'status' => 'Activated']);

    RoleAssignment::create([
        'user_id' => $person->user_id,
        'role_id' => SystemUser::ROLE_STUDENT,
        'status'  => RoleAssignment::STATUS_ACTIVE,
    ]);

    RoleAssignment::create([
        'user_id'    => $person->user_id,
        'role_id'    => SystemUser::ROLE_SUPER_ADMIN,
        'status'     => RoleAssignment::STATUS_ACTIVE,
        // Not yet elapsed at switch time — elapses moments later, below.
        'expires_at' => now()->addSecond(),
    ]);

    $result         = app(RoleAssignmentService::class)
        ->switchTo($person, SystemUser::ROLE_SUPER_ADMIN, roleAssignmentTestRequest($person));
    $plainTextToken = $result['token'];

    $this->withHeader('Authorization', "Bearer {$plainTextToken}")
        ->getJson('/api/v1/audit-logs')
        ->assertOk();

    $this->travel(2)->seconds();
    $this->artisan('role-assignments:expire')->assertExitCode(0);

    // See the identical comment in the test above: the Sanctum guard
    // caches the resolved user across HTTP calls within one test method,
    // so it must be forgotten before re-checking a token whose backing
    // role_assignments row was just swept to Expired.
    $this->app['auth']->forgetGuards();

    $this->withHeader('Authorization', "Bearer {$plainTextToken}")
        ->getJson('/api/v1/audit-logs')
        ->assertStatus(401);
});