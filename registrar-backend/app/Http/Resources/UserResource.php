<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Models\Policy;
use App\Models\SystemUser;

class UserResource extends JsonResource
{
    public function toArray($request): array
    {
        // role_id/policy_id below reflect the session's ASSUMED role
        // (Step 3 — see SystemUser::assumedRoleId()/assumedPolicyId()),
        // which is identical to the raw users.role_id/policy_id columns
        // unless this session has switched via POST /auth/switch-role.
        $assumedRoleId   = $this->assumedRoleId();
        $assumedPolicyId = $this->assumedPolicyId();

        return [
            'user_id'  => $this->user_id,
            'email'    => $this->email,
            'role_id'  => $assumedRoleId,

            // Human-readable role name so the frontend never needs to
            // hardcode "if role_id === 3" checks — use role_name instead
            'role_name' => $this->resolveRoleName($assumedRoleId),

            // Student relations — only present if loaded
            'student_profile' => $this->whenLoaded('studentProfile'),
            'academic_record' => $this->whenLoaded('academicRecord'),

            // Alumni relation — only present if loaded
            // Will return data once alumni module is built
            'alumni_profile'  => $this->whenLoaded('alumniProfile'),

            // Undergrad Requestor Registration — Phase 5. Only present
            // if loaded (see SystemUser::loadIdentityRelations()'s new
            // branch) — no academic_record counterpart, since D5 gave
            // this role no such record to have.
            'undergrad_requestor_profile' => $this->whenLoaded('undergradRequestorProfile'),

            // Admin/Super Admin relation — only present if loaded
            // Will return data once admin profile module is built
            'admin_profile'   => $this->whenLoaded('adminProfile'),

            // Flattened copies of the same admin_profile fields, so callers
            // (e.g. the account-settings form after a save) can read
            // data.first_name directly instead of reaching into
            // data.admin_profile — only present when adminProfile is loaded.
            // Deliberately UNCHANGED (still adminProfile-only, not the
            // fallback-aware resolveDisplayName() below): this is the
            // shape the admin-profile EDIT form reads/writes, and editing
            // a student-staff account's own Student name through the
            // Admin Profile form would be editing the wrong table.
            'first_name'  => $this->whenLoaded('adminProfile', fn () => $this->adminProfile?->first_name),
            'middle_name' => $this->whenLoaded('adminProfile', fn () => $this->adminProfile?->middle_name),
            'last_name'   => $this->whenLoaded('adminProfile', fn () => $this->adminProfile?->last_name),
            'suffix'      => $this->whenLoaded('adminProfile', fn () => $this->adminProfile?->suffix),

            // BUG FIX (RIS-PROCESS-BUGS #10 — "Incorrect User Name Display
            // for Assigned Student Staff Role"): a resolved, always-present
            // display name for read-only UI (dashboard header, admin
            // logbook "acted by" column, etc.) to render instead of a
            // hardcoded "guest" fallback. See resolveDisplayName() below
            // and SystemUser::loadIdentityRelations() for why adminProfile
            // alone isn't enough for a secondary (student-staff) grant.
            'display_name' => $this->resolveDisplayName(),

            // Policy attachment — admin-only. Super admins always have
            // full access and never carry a policy_id (see RoleMiddleware).
            // Resolved independently of the `policy` eager-load below,
            // because that relation is bound to the raw policy_id
            // column and would show the wrong (or no) policy once a
            // session has switched to an assumed role with a different
            // one attached.
            'policy_id' => $assumedPolicyId,
            'policy'    => $this->when($assumedPolicyId !== null, function () use ($assumedPolicyId) {
                $policy = $this->relationLoaded('policy') && $this->policy?->policy_id === $assumedPolicyId
                    ? $this->policy
                    : Policy::where('policy_id', $assumedPolicyId)->first();

                return $policy ? new PolicyResource($policy) : null;
            }),

            // The module => actions map that ACTUALLY applies right now
            // (own policy, else the default policy, else nothing) — see
            // SystemUser::effectivePermissions(). This is what the
            // frontend should read to decide what to show/allow; it
            // should never re-derive this from policy_id/policy itself,
            // or the two could drift apart.
            'effective_permissions' => $this->when(
                $this->isAdmin(),
                fn () => $this->effectivePermissions()
            ),

            // BUG FIX (QA #11 — "Expired Status Not Auto-Tagged"): same
            // convention as RoleAssignmentResource's status/stored_status
            // split (see RoleAssignment::effectiveStatus() docblock).
            // effective_status reports 'Expired' the instant
            // pending_expires_at has elapsed, even if provisioning:expire-stale
            // hasn't swept this row yet — this is what the frontend
            // (status badge, status filter) should render. stored_status
            // is the raw column, for anyone specifically debugging the
            // sweep job itself.
            'status'        => $this->effective_status,
            'stored_status' => $this->status,
            // Only meaningful while status === 'Pending Activation' — when
            // this passes, provisioning:expire-stale flips status to
            // 'Expired' (see Console\Commands\ExpireStaleProvisioning).
            'pending_expires_at' => $this->pending_expires_at,
            'created_at' => $this->created_at,

            // -----------------------------------------------------------
            // Work Item #3 — Admin Accounts / Student Staff Visibility.
            //
            // base_role_id/base_role_name are the account's actual,
            // permanent identity (raw users.role_id) — deliberately NOT
            // assumedRoleId(), which reflects a SESSION override and is
            // meaningless when this resource represents someone else's
            // row in a listing rather than the caller's own account. This
            // is what the Admin Accounts table shows as "Student" /
            // "Alumni" for a student-staff row, distinct from the
            // administrative role granted to them (see admin_grant below).
            'base_role_id'   => $this->role_id,
            'base_role_name' => $this->resolveRoleName($this->role_id),

            // The administrative (Admin/Super Admin) access this account
            // currently holds, wherever it comes from — see
            // resolveAdminGrant(). Null for a row that has neither an
            // admin-tier primary role nor an active admin-tier grant (this
            // resource is still usable for non-admin contexts elsewhere,
            // e.g. AuthController@me, where admin_grant is simply null for
            // a plain Student/Alumni session).
            'admin_grant' => $this->resolveAdminGrant(),
        ];
    }

    /**
     * Work Item #3 — resolves "what administrative access does this
     * account currently hold, and where does its policy actually come
     * from" — this is NOT the same question as $this->policy_id /
     * $this->policy above (which reflect the SESSION-assumed role via
     * assumedPolicyId(), and for a THIRD PARTY'S row being listed here —
     * no currentAccessToken() on this model instance — fall straight
     * through to the raw users.policy_id column).
     *
     * That raw-column fallback is exactly right for a classic Admin/Super
     * Admin (their baseline role_assignments row is kept in sync with
     * users.policy_id by RoleAssignmentService::editPolicy() — see its
     * docblock), but WRONG for a secondary "student staff" grant: a
     * Student's users.policy_id is never set (only admin-tier primary
     * accounts use that column), so the account's real Admin policy would
     * silently read as "no policy attached" without this method.
     *
     * Prefers an actual loaded admin-tier role_assignments row (covers
     * both a classic admin's live baseline row AND a secondary grant),
     * and falls back to the raw role_id/policy_id columns only when no
     * such row is loaded/active — e.g. a Deactivated classic admin whose
     * baseline row was cascade-revoked by
     * RoleAssignmentService::revokeAllForUser(). That fallback is what
     * keeps existing Registrar Staff / Super Admin rows displaying
     * exactly as they did before this work item.
     */
    private function resolveAdminGrant(): ?array
    {
        $adminTier = [SystemUser::ROLE_ADMIN, SystemUser::ROLE_SUPER_ADMIN];

        if ($this->relationLoaded('activeRoleAssignments')) {
            $assignment = $this->activeRoleAssignments
                ->whereIn('role_id', $adminTier)
                // A user could in theory hold both an Admin and a Super
                // Admin active row at once — grant()'s duplicate check is
                // scoped per role_id, not per tier. Prefer the
                // higher-privilege one for display if that ever happens.
                ->sortByDesc('role_id')
                ->first();

            if ($assignment) {
                return [
                    'role_assignment_id' => $assignment->id,
                    'role_id'            => $assignment->role_id,
                    'role_name'          => $this->resolveRoleName($assignment->role_id),
                    // True when this grant sits ON TOP OF a non-admin base
                    // identity — the actual "student staff" case this
                    // work item exists to surface.
                    'is_secondary'       => $assignment->role_id !== $this->role_id,
                    'policy'             => ($assignment->role_id === SystemUser::ROLE_ADMIN && $assignment->policy)
                        ? new PolicyResource($assignment->policy)
                        : null,
                    'granted_at'         => optional($assignment->granted_at)->toIso8601String(),
                    'expires_at'         => optional($assignment->expires_at)->toIso8601String(),
                    // Always 'Active' here — the eager-loaded relation is
                    // pre-filtered to activeRoleAssignments() — exposed
                    // anyway so the frontend never has to assume that.
                    'status'             => $assignment->status,
                ];
            }
        }

        if (in_array($this->role_id, $adminTier, true)) {
            return [
                'role_assignment_id' => null,
                'role_id'            => $this->role_id,
                'role_name'          => $this->resolveRoleName($this->role_id),
                'is_secondary'       => false,
                'policy'             => ($this->role_id === SystemUser::ROLE_ADMIN && $this->policy)
                    ? new PolicyResource($this->policy)
                    : null,
                'granted_at'         => null,
                'expires_at'         => null,
                'status'             => null,
            ];
        }

        return null;
    }

    /**
     * BUG FIX (RIS-PROCESS-BUGS #10 — "Incorrect User Name Display for
     * Assigned Student Staff Role").
     *
     * Resolves the best available human name for this account, checking
     * each identity profile in priority order and falling through to the
     * next one whenever the higher-priority relation isn't loaded or has
     * no name recorded — rather than assuming "the assumed role is Admin,
     * so adminProfile is the only place a name could be," which is what
     * produced the "guest" placeholder in the first place (see
     * SystemUser::loadIdentityRelations() docblock).
     *
     *   1. adminProfile  — correct for a classic Admin/Super Admin, and
     *      for a secondary grant IF an admin profile happens to exist.
     *   2. studentProfile — the real name for a "student staff" account
     *      (base role Student, secondary Admin grant) — the exact case
     *      from the bug report (juan@gmail.com).
     *   3. alumniProfile  — same idea for a future alumni-staff grant.
     *
     * Returns null (never a hardcoded placeholder like "guest") if none
     * of the above are loaded/populated, so the frontend can render its
     * own empty state deliberately rather than being handed fabricated
     * data.
     */
    private function resolveDisplayName(): ?string
    {
        $profile = null;

        if ($this->relationLoaded('adminProfile') && $this->adminProfile) {
            $profile = $this->adminProfile;
        } elseif ($this->relationLoaded('studentProfile') && $this->studentProfile) {
            $profile = $this->studentProfile;
        } elseif ($this->relationLoaded('alumniProfile') && $this->alumniProfile) {
            $profile = $this->alumniProfile;
        } elseif ($this->relationLoaded('undergradRequestorProfile') && $this->undergradRequestorProfile) {
            // Undergrad Requestor Registration — Phase 5. Without this
            // branch an Approved, logged-in Undergrad Requestor saw a
            // blank display_name everywhere this resource is used
            // (dashboard header, etc.) — the same "guest" placeholder
            // gap RIS-PROCESS-BUGS #10 fixed for student-staff, now
            // reopened for a role that didn't exist when that fix
            // shipped.
            $profile = $this->undergradRequestorProfile;
        }

        if (!$profile || !$profile->first_name) {
            return null;
        }

        $parts = array_filter([
            $profile->first_name,
            $profile->middle_name,
            $profile->last_name,
            $profile->suffix,
        ]);

        return implode(' ', $parts);
    }

    // -------------------------------------------------------
    // Resolves role_id to a readable string.
    // Keeps frontend logic clean — check role_name, not numbers.
    // -------------------------------------------------------
    private function resolveRoleName(int $roleId): string
    {
        return match ($roleId) {
            SystemUser::ROLE_STUDENT             => 'student',
            SystemUser::ROLE_ALUMNI              => 'alumni',
            // Undergrad Requestor Registration — Phase 5. Used both for
            // this account's own role_name/base_role_name (frontend
            // dashboard chrome, routing) and — via GrantableUserResource,
            // a separate class with its own copy of this mapping — the
            // Cashier OR override and role-assignment pickers.
            SystemUser::ROLE_UNDERGRAD_REQUESTOR => 'undergrad_requestor',
            SystemUser::ROLE_ADMIN               => 'admin',
            SystemUser::ROLE_SUPER_ADMIN          => 'super_admin',
            default                               => 'unknown',
        };
    }
}