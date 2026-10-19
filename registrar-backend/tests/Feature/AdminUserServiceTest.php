<?php

use App\Models\AuditLog;
use App\Models\SystemUser;
use App\Services\AdminUserService;
use App\Services\Sso\IdpClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function ausActor(): SystemUser
{
    $actor = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN, 'status' => 'Activated']);
    Sanctum::actingAs($actor);
    return $actor;
}

function ausRequest(): Request
{
    // Same fix as AccessRequestServiceTest's arsRequest(): Request::create()
    // has no user resolver bound, so AdminUserService::create()'s call to
    // $request->user() would otherwise return null even after
    // Sanctum::actingAs() authenticates the guard.
    $request = Request::create('/api/v1/system-users', 'POST');
    $request->setUserResolver(fn () => auth()->user());
    return $request;
}

// ═════════════════════════════════════════════════════════════════════════════
// AdminUserService::create() — pre-register-then-link, no IdP call
// ═════════════════════════════════════════════════════════════════════════════

test('create() never calls IdpClient::createUser', function () {
    ausActor();

    $this->mock(IdpClient::class, function ($mock) {
        $mock->shouldNotReceive('createUser');
    });

    $service = app(AdminUserService::class);
    $service->create([
        'email'      => 'pending@example.com',
        'role_id'    => SystemUser::ROLE_ADMIN,
        'first_name' => 'Pending',
        'last_name'  => 'Admin',
    ], ausRequest());

    $this->assertDatabaseHas('users', ['email' => 'pending@example.com']);
});

test('create() sets Pending Activation, null idp_user_id, null password, local_auth_enabled 0', function () {
    ausActor();

    $service = app(AdminUserService::class);
    $user = $service->create([
        'email'      => 'pending2@example.com',
        'role_id'    => SystemUser::ROLE_ADMIN,
        'first_name' => 'Pending',
        'last_name'  => 'Admin',
    ], ausRequest());

    expect($user->status)->toBe('Pending Activation');
    expect($user->idp_user_id)->toBeNull();
    expect($user->password)->toBeNull();
    expect($user->local_auth_enabled)->toBe(0);
});

test('create() sets pending_expires_at 14 days out', function () {
    ausActor();

    $service = app(AdminUserService::class);
    $user = $service->create([
        'email'      => 'pending3@example.com',
        'role_id'    => SystemUser::ROLE_ADMIN,
        'first_name' => 'Pending',
        'last_name'  => 'Admin',
    ], ausRequest());

    expect($user->pending_expires_at)->not->toBeNull();
    expect($user->pending_expires_at->diffInDays(now()->addDays(14)))->toBeLessThan(1);
});

test('create() writes an admin_profile row and an ACTION_ADMIN_CREATED audit log', function () {
    $actor = ausActor();

    $service = app(AdminUserService::class);
    $user = $service->create([
        'email'       => 'pending4@example.com',
        'role_id'     => SystemUser::ROLE_ADMIN,
        'first_name'  => 'Pending',
        'middle_name' => 'M',
        'last_name'   => 'Admin',
        'suffix'      => 'Jr.',
    ], ausRequest());

    $this->assertDatabaseHas('admin_profile', [
        'user_id'     => $user->user_id,
        'first_name'  => 'Pending',
        'middle_name' => 'M',
        'last_name'   => 'Admin',
        'suffix'      => 'Jr.',
    ]);

    $this->assertDatabaseHas('audit_logs', [
        'action'         => AuditLog::ACTION_ADMIN_CREATED,
        'user_id'        => $actor->user_id,
        'target_user_id' => $user->user_id,
    ]);
});

test('create() only attaches policy_id for admin role, never super admin', function () {
    ausActor();

    $policy = \App\Models\Policy::create([
        'name'        => 'Front Desk',
        'permissions' => ['dashboard' => ['Access']],
        'is_system'   => false,
    ]);

    $service = app(AdminUserService::class);

    $admin = $service->create([
        'email'      => 'polladmin@example.com',
        'role_id'    => SystemUser::ROLE_ADMIN,
        'first_name' => 'A',
        'last_name'  => 'B',
        'policy_id'  => $policy->policy_id,
    ], ausRequest());
    expect($admin->policy_id)->toBe($policy->policy_id);

    $superAdmin = $service->create([
        'email'      => 'pollsuper@example.com',
        'role_id'    => SystemUser::ROLE_SUPER_ADMIN,
        'first_name' => 'A',
        'last_name'  => 'B',
        'policy_id'  => $policy->policy_id,
    ], ausRequest());
    expect($superAdmin->policy_id)->toBeNull();
});

// ═════════════════════════════════════════════════════════════════════════════
// AdminUserService::update() — deactivation cascades to role_assignments
// (Layer 1 -> Layer 2 cascade; see RoleAssignmentService::revokeAllForUser())
// ═════════════════════════════════════════════════════════════════════════════

test('deactivating a user revokes every Active role assignment they hold', function () {
    ausActor();

    $user = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN, 'status' => 'Activated']);

    \App\Models\RoleAssignment::create([
        'user_id'    => $user->user_id,
        'role_id'    => SystemUser::ROLE_ADMIN,
        'status'     => 'Active',
        'granted_at' => now(),
    ]);
    \App\Models\RoleAssignment::create([
        'user_id'    => $user->user_id,
        'role_id'    => SystemUser::ROLE_STUDENT,
        'status'     => 'Active',
        'granted_at' => now(),
    ]);

    app(AdminUserService::class)->update($user, ['status' => 'Deactivated'], ausRequest());

    $rows = \App\Models\RoleAssignment::where('user_id', $user->user_id)->get();

    expect($rows)->toHaveCount(2);
    $rows->each(fn ($row) => expect($row->status)->toBe('Revoked'));

    $this->assertDatabaseHas('audit_logs', [
        'action'         => AuditLog::ACTION_ROLE_REVOKED,
        'target_user_id' => $user->user_id,
    ]);
});

test('deactivating a user with no role assignments does not error', function () {
    ausActor();

    $user = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN, 'status' => 'Activated']);

    expect(fn () => app(AdminUserService::class)->update($user, ['status' => 'Deactivated'], ausRequest()))
        ->not->toThrow(\Throwable::class);
});

test('reactivating a previously-deactivated user does not resurrect their revoked role assignments', function () {
    ausActor();

    $user = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN, 'status' => 'Activated']);

    \App\Models\RoleAssignment::create([
        'user_id'    => $user->user_id,
        'role_id'    => SystemUser::ROLE_ADMIN,
        'status'     => 'Active',
        'granted_at' => now(),
    ]);

    $service = app(AdminUserService::class);
    $service->update($user, ['status' => 'Deactivated'], ausRequest());
    $service->update($user->fresh(), ['status' => 'Activated'], ausRequest());

    $assignment = \App\Models\RoleAssignment::where('user_id', $user->user_id)->first();

    // Reactivation only flips users.status back — it must not silently
    // flip a Revoked role_assignments row back to Active. Regaining a
    // role after deactivation should always go through a fresh, deliberate
    // grant(), never happen as a side effect of reactivation.
    expect($assignment->status)->toBe('Revoked');
});