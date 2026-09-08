<?php

namespace App\Policies;

use App\Models\SystemUser;

/**
 * Authorization for admin/super-admin account management
 * (System Users module — distinct from student/alumni accounts).
 *
 * Mirrors the DocumentRequestPolicy pattern: controllers call
 * $this->authorize(...) instead of inlining role_id checks.
 *
 * NOTE: Laravel resolves this automatically for App\Models\SystemUser
 * via naming convention — no manual registration needed (same as
 * DocumentRequestPolicy already relies on).
 */
class SystemUserPolicy
{
    private const MANAGEABLE_ROLES = [
        SystemUser::ROLE_ADMIN,
        SystemUser::ROLE_SUPER_ADMIN,
    ];

    // -------------------------------------------------------
    // GET /system-users
    // Only super admins manage the admin/super-admin roster.
    //
    // BUG FIX (session-assumed-role authorization gap): these checks
    // used to read $user->role_id directly — the raw PRIMARY role
    // column. That's correct for the isAdministrativelyManageable()
    // check further down (which evaluates $target, someone else's
    // account, where "primary identity" is exactly what matters), but
    // wrong here, where $user is the ACTOR making the request.
    //
    // An Admin whose account also holds an Active Super Admin
    // role_assignment (the "Admin + Super Admin" dual-role case — see
    // RoleAssignmentService::grant()) and who has switched their
    // session into that grant via POST /auth/switch-role is, for every
    // other purpose in the app (RoleMiddleware's route-level 'role:4'
    // gate, EnsureModuleAccess, RoleAssignmentPolicy), treated as a
    // Super Admin for the duration of that session — SystemUser::
    // isSuperAdmin() is the single source of truth for that, and reads
    // through assumedRoleId() (the session's assumed role if one is in
    // effect, else the raw column) rather than the raw column directly.
    //
    // This policy was the one place still bypassing that and reading
    // role_id directly, so a switched-in Super Admin passed the route
    // middleware (assumed-role-aware) and then got a 403 straight back
    // out of the controller's $this->authorize() call (raw-role-aware) —
    // visible as "everything 403s the moment I switch to Super Admin."
    // isSuperAdmin() is fully backward compatible: for a classic,
    // never-switched Super Admin account it's identical to the old
    // check, since assumedRoleId() falls through to the raw column
    // whenever no session override is in effect.
    // -------------------------------------------------------
    public function viewAny(SystemUser $user): bool
    {
        return $user->isSuperAdmin();
    }

    // -------------------------------------------------------
    // GET /system-users/{id}
    //
    // Work Item #3 — Admin Accounts / Student Staff Visibility:
    // SystemUserController::index() now also lists accounts whose
    // PRIMARY role is Student/Alumni but who hold an active Admin-tier
    // role_assignments grant (see isAdministrativelyManageable() below).
    // A row that's visible in that listing must also be viewable through
    // this same policy, or clicking into a newly-listed "student staff"
    // row would 403 the moment it's opened — this endpoint is still not
    // for looking up an ordinary student/alumni with no administrative
    // grant at all.
    // -------------------------------------------------------
    public function view(SystemUser $user, SystemUser $target): bool
    {
        // $user: session-assumed role (see viewAny() docblock above).
        // $target: raw role_id is correct here — isAdministrativelyManageable()
        // is deliberately about the TARGET's actual, durable identity.
        return $user->isSuperAdmin()
            && $this->isAdministrativelyManageable($target);
    }

    // -------------------------------------------------------
    // POST /system-users
    // -------------------------------------------------------
    public function create(SystemUser $user): bool
    {
        return $user->isSuperAdmin();
    }

    // -------------------------------------------------------
    // PUT /system-users/{id}
    //
    // Work Item #3: same reasoning as view() above — a Super Admin must
    // be able to edit identity/Status (the only fields this endpoint
    // still accepts as of Work Item #2) on a student-staff account, since
    // Admin Accounts now lists it. Deactivating such an account through
    // here already correctly cascades to revoke ALL of that user's role
    // assignments, not just the administrative one — see
    // AdminUserService::update().
    // -------------------------------------------------------
    public function update(SystemUser $user, SystemUser $target): bool
    {
        return $user->isSuperAdmin()
            && $this->isAdministrativelyManageable($target);
    }

    // -------------------------------------------------------
    // DELETE /system-users/{id}
    //
    // NOTE: this intentionally does NOT check for self-delete. The
    // controller checks that separately with its own specific error
    // message ("You cannot delete your own account.") because
    // Laravel's default AuthorizationException response ("This action
    // is unauthorized.") would otherwise replace that message with a
    // generic one — worse UX for a case the frontend specifically
    // surfaces to the user.
    //
    // Work Item #3: deliberately NOT extended to student-staff targets
    // the way view()/update() were. Hard-deleting a system user here
    // deletes the entire account, not just the administrative grant on
    // top of it — for a "student staff" row, that means deleting a real
    // student's whole account from what is meant to be an admin
    // management screen. Revoking their administrative access (Manage
    // Roles) or deactivating the account (Edit User → Status) are the
    // correct tools for that; a straight delete stays scoped to genuine
    // admin/super-admin primary accounts only, same as before.
    // -------------------------------------------------------
    public function delete(SystemUser $user, SystemUser $target): bool
    {
        return $user->isSuperAdmin()
            && in_array($target->role_id, self::MANAGEABLE_ROLES);
    }

    /**
     * Work Item #3: true if $target either has an Admin-tier PRIMARY
     * role, or currently holds an active Admin-tier role_assignments
     * grant on top of a base Student/Alumni identity — matching
     * SystemUserController::index()'s listing criteria exactly (see its
     * docblock). Queried directly rather than relying on an eager-loaded
     * relation, since $target here is a plain SystemUser::find($id)
     * lookup done by show()/update() — it never has activeRoleAssignments
     * preloaded the way index()'s collection does.
     */
    private function isAdministrativelyManageable(SystemUser $target): bool
    {
        if (in_array($target->role_id, self::MANAGEABLE_ROLES, true)) {
            return true;
        }

        return $target->activeRoleAssignments()
            ->whereIn('role_id', self::MANAGEABLE_ROLES)
            ->exists();
    }
}