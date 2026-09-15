<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\RoleAssignment;
use App\Models\SystemUser;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Grants and revokes role_assignments rows — the entitlement layer that
 * sits alongside (not yet replacing) users.role_id. See
 * database/migrations/2026_08_10_000000_create_role_assignments_table.php
 * for why this table exists separately.
 *
 * grant() is how a "student staff" gets onboarded: the person already
 * has a Student role_assignment (from the backfill, or from
 * UserProvisioningService::provision() on their first SSO login), and a
 * Super Admin grants them a second, Admin role_assignment with a
 * restricted policy and a bounded expires_at.
 *
 * editPolicy() (Work Item #2) is the in-place counterpart to grant()/
 * revoke(): swapping the policy on an already-Active Admin grant without
 * needing a full revoke/regrant cycle (and the forced re-login that
 * revoke() intentionally causes for a real offboarding event).
 *
 * revoke() is the deterministic "they left" offboarding path — explicit,
 * audited, and immediately session-invalidating (see the token-revocation
 * note on revoke() below). The non-deterministic "they graduated" case
 * relies on expires_at + role-assignments:expire instead, since RIS has
 * no live signal for graduation (confirmed: student_academic_record has
 * no graduation_date column, and OGOS's DTO exposes no enrollment-status
 * field) — see Console\Commands\ExpireRoleAssignments.
 */
class RoleAssignmentService
{
    public function __construct(private AuditLogger $auditLogger) {}

    /**
     * @throws ValidationException
     */
    public function grant(array $validated, Request $request): RoleAssignment
    {
        $targetUser = SystemUser::findOrFail($validated['user_id']);

        // Work Item #2 — direction constraint, enforced server-side (not
        // just a UI convention a direct API call could bypass). Keyed off
        // the target's actual PRIMARY role (users.role_id), never the
        // session-assumed role — a Super Admin previewing as something
        // else must not change what this check sees for someone else's
        // account. See assertDirectionAllowed() docblock for the full
        // reasoning.
        $this->assertDirectionAllowed($targetUser, (int) $validated['role_id']);

        return DB::transaction(function () use ($validated, $targetUser, $request) {
            // Bug #5 (QA) — "Roles Assigned to Deactivated Accounts".
            // searchGrantableUsers() already excludes non-Activated
            // accounts from the picker, but that's a read-time filter on
            // a UI convenience endpoint, not an enforced invariant — a
            // direct API call bypasses it entirely, and even a
            // picker-driven request can race a concurrent deactivation
            // between when the list loaded and when this submits. Re-fetch
            // and lock the row here (same lockForUpdate pattern
            // editPolicy() and the duplicate check below already use) so
            // the status actually being written to is the one enforced,
            // not a stale copy read before the transaction opened.
            $targetUser = SystemUser::where('user_id', $targetUser->user_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($targetUser->status !== 'Activated') {
                throw ValidationException::withMessages([
                    'user_id' => "This account is '{$targetUser->status}' and cannot be granted a role. "
                        . 'Only Activated accounts are eligible.',
                ]);
            }

            // Enforced here rather than a DB partial-unique index (MySQL
            // has no native partial unique constraint without a generated
            // column) — the transaction + row lock below makes this safe
            // against a concurrent grant() racing for the same
            // (user_id, role_id) pair.
            $duplicate = RoleAssignment::where('user_id', $targetUser->user_id)
                ->where('role_id', $validated['role_id'])
                ->active()
                ->lockForUpdate()
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages([
                    'role_id' => 'This user already holds an active assignment for that role.',
                ]);
            }

            // Defense-in-depth against the "one-row" bug: grant() used to
            // assume a baseline row for the user's current primary role
            // already existed (from the backfill, or from
            // UserProvisioningService::provision()). For any account that
            // reached grant() before ensureBaselineRoleAssignment() shipped
            // there — e.g. one provisioned in the window between the
            // backfill running and this fix deploying — that assumption
            // was false, and this user would end up with exactly one
            // role_assignments row (the one being granted right now)
            // instead of two. That hides the role switcher entirely
            // (Navigation.jsx gates on roleAssignments.length > 1) and
            // leaves switchTo() unable to return them to their original
            // role. Backfilling it here, under the same row lock as the
            // duplicate check above, closes that gap regardless of which
            // path created the account.
            //
            // Skipped when the role being granted IS the user's current
            // primary role_id — in that case the assignment created below
            // already serves as the baseline row for that role, and
            // inserting both would put two Active rows on the same role,
            // which is exactly the invariant the duplicate check above
            // exists to prevent.
            $hasAnyAssignment = RoleAssignment::where('user_id', $targetUser->user_id)
                ->lockForUpdate()
                ->exists();

            if (!$hasAnyAssignment && $targetUser->role_id !== $validated['role_id']) {
                RoleAssignment::create([
                    'user_id'    => $targetUser->user_id,
                    'role_id'    => $targetUser->role_id,
                    'policy_id'  => $targetUser->role_id === SystemUser::ROLE_ADMIN
                        ? $targetUser->policy_id
                        : null,
                    'status'     => RoleAssignment::STATUS_ACTIVE,
                    'granted_by' => null,
                    'granted_at' => now(),
                    'expires_at' => null,
                ]);
            }

            $assignment = RoleAssignment::create([
                'user_id'    => $targetUser->user_id,
                'role_id'    => $validated['role_id'],
                // Only admins carry a policy — same convention as
                // users.policy_id / AdminUserService::create().
                'policy_id'  => $validated['role_id'] === SystemUser::ROLE_ADMIN
                    ? ($validated['policy_id'] ?? null)
                    : null,
                'status'     => RoleAssignment::STATUS_ACTIVE,
                'granted_by' => $request->user()->user_id,
                'granted_at' => now(),
                // Deliberately no fallback default here (e.g. "+1 term") —
                // that's a registrar policy decision, not a code default to
                // guess at. The frontend/caller must pass expires_at
                // explicitly; null is allowed (indefinite) but has to be a
                // deliberate choice, enforced by StoreRoleAssignmentRequest.
                'expires_at' => $validated['expires_at'] ?? null,
            ]);

            $this->auditLogger->log($request, $request->user(), AuditLog::ACTION_ROLE_ASSIGNED, [
                'target_user_id'      => $targetUser->user_id,
                'target_email'        => $targetUser->email,
                'role_assignment_id'  => $assignment->id,
                'role_id'             => $assignment->role_id,
                'policy_id'           => $assignment->policy_id,
                'expires_at'          => optional($assignment->expires_at)->toIso8601String(),
            ]);

            return $assignment;
        });
    }

    /**
     * Work Item #2 — Admin Management Consolidation: enforces a one-way
     * street between the two "tiers" of role a person can hold.
     *
     *   - A base-identity account (Student/Alumni primary role) CAN pick
     *     up an Admin-tier grant on top — this is the entire "student
     *     staff" feature the role_assignments table exists for.
     *   - An account whose PRIMARY role is already Admin-tier
     *     (Admin/Super Admin) can NEVER be handed a Student/Alumni role
     *     back through this endpoint. That's not a real-world transition
     *     (a staff member doesn't "become" a student by being granted a
     *     role) and silently allowing it would let role_assignments
     *     disagree with what the account actually is. If a Super Admin's
     *     employment situation genuinely changes, that's an account
     *     lifecycle decision (deactivate this account, provision a new
     *     one under the person's student identity) — not a role grant.
     *
     * Deliberately keyed off $targetUser->role_id — the raw, PRIMARY
     * role column — rather than assumedRoleId() or any role_assignments
     * row. The primary role is the durable fact about what kind of
     * account this is; a session-scoped "assumed role" override must
     * never be able to change what this guard sees for a DIFFERENT
     * user's account.
     *
     * @throws ValidationException
     */
    private function assertDirectionAllowed(SystemUser $targetUser, int $grantedRoleId): void
    {
        $adminTier = [SystemUser::ROLE_ADMIN, SystemUser::ROLE_SUPER_ADMIN];
        // Undergrad Requestor Registration — Phase 5. ROLE_UNDERGRAD_REQUESTOR
        // added to this DIRECTION GUARD for the same structural reason
        // Student/Alumni are here — but, unlike them, NOT also added to
        // StoreRoleAssignmentRequest's grantable role_id whitelist. That
        // was a deliberate, considered decision, not an oversight:
        //
        //   Student/Alumni are durable academic identities a person can
        //   plausibly hold ALONGSIDE another role — the whitelist
        //   genuinely allows granting them as a secondary role_assignment
        //   today, and this guard protects that real feature from being
        //   pointed at an Admin-tier target.
        //
        //   Undergrad Requestor has exactly ONE valid path into
        //   existence: the public onboarding form + Admin approval
        //   pipeline (D4), which creates the users row AND its
        //   undergrad_requestor_verifications row together, atomically
        //   (see UndergradRequestorRegistrationService::register()). A
        //   role_assignments grant would produce a role_id = 5 row with
        //   NO verification row — and EnsureUndergradRequestorApproved
        //   (Phase 5) has no way to tell "never verified" apart from
        //   "not yet approved," so that account would be permanently,
        //   correctly refused at every request-flow endpoint. Not a
        //   usable secondary role — a dead end.
        //
        // This array entry is therefore pure defense-in-depth: since the
        // FormRequest whitelist already rejects role_id = 5 before this
        // method ever runs, the branch below is currently unreachable
        // for that value — kept anyway, at zero cost, in case a future
        // change ever widens that whitelist without re-deriving this
        // reasoning first.
        $baseTier  = [SystemUser::ROLE_STUDENT, SystemUser::ROLE_ALUMNI, SystemUser::ROLE_UNDERGRAD_REQUESTOR];

        if (in_array($targetUser->role_id, $adminTier, true) && in_array($grantedRoleId, $baseTier, true)) {
            throw ValidationException::withMessages([
                'role_id' => 'This account\'s primary role is Admin-tier (Admin or Super Admin) and cannot be '
                    . 'handed a Student, Alumni, or Undergrad Requestor role assignment. Deactivate the account '
                    . 'instead if this person no longer holds this role.',
            ]);
        }
    }

    /**
     * Work Item #2 — Admin Management Consolidation: edit the policy on
     * an already-Active Admin role_assignment IN PLACE, without a
     * revoke/regrant cycle (which would needlessly force-log-out the
     * account — see revoke()'s SECURITY note — for what is really just a
     * permissions change, not an offboarding event).
     *
     * Only meaningful for role_id = ROLE_ADMIN — Student/Alumni/Super
     * Admin assignments don't carry a policy at all (Super Admin bypasses
     * the policy system entirely; see SystemUser::hasModuleAccess()).
     *
     * Mirrors — in the opposite direction — the sync PolicyService::
     * attachToUser() used to maintain: when $assignment IS the target
     * user's baseline/primary row (their role_assignments row whose
     * role_id equals their own users.role_id), this also updates
     * users.policy_id in the same transaction. That mirror is required,
     * not cosmetic — SystemUser::assumedPolicyId() only reads a
     * role_assignments row's own policy_id once a SESSION has explicitly
     * switched into that role (POST /auth/switch-role); for the common
     * case of an admin who never switched, it falls straight through to
     * the raw users.policy_id column. Leaving that column stale after an
     * in-place edit would silently keep enforcing the OLD policy for
     * every such session until they happened to switch roles and back.
     *
     * A SECONDARY grant (e.g. the Admin side of a "student staff" whose
     * primary role is Student) has no such mirror — its policy only ever
     * applies once a session has assumed that specific role_assignment,
     * which always reads the assignment's own column directly.
     *
     * @throws ValidationException
     */
    public function editPolicy(RoleAssignment $assignment, ?int $policyId, Request $request): RoleAssignment
    {
        if ($assignment->role_id !== SystemUser::ROLE_ADMIN) {
            throw ValidationException::withMessages([
                'role_id' => 'Only Admin-role assignments carry a policy. Student, Alumni, and Super Admin '
                    . 'assignments are not policy-gated.',
            ]);
        }

        if ($assignment->status !== RoleAssignment::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'status' => "This role assignment is '{$assignment->status}' and can no longer be edited. "
                    . 'Only Active assignments can have their policy changed in place.',
            ]);
        }

        return DB::transaction(function () use ($assignment, $policyId, $request) {
            // Row-lock both the assignment and its owning user for the
            // duration of the edit — same defense against a concurrent
            // grant()/revoke()/another editPolicy() race that grant()
            // itself already applies via lockForUpdate() above.
            $assignment = RoleAssignment::where('id', $assignment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($assignment->status !== RoleAssignment::STATUS_ACTIVE) {
                throw ValidationException::withMessages([
                    'status' => "This role assignment is '{$assignment->status}' and can no longer be edited.",
                ]);
            }

            $targetUser = SystemUser::where('user_id', $assignment->user_id)
                ->lockForUpdate()
                ->first();

            $assignment->update(['policy_id' => $policyId]);

            if ($targetUser && $targetUser->role_id === SystemUser::ROLE_ADMIN) {
                $targetUser->update(['policy_id' => $policyId]);
            }

            $this->auditLogger->log($request, $request->user(), AuditLog::ACTION_ROLE_POLICY_EDITED, [
                'target_user_id'      => $assignment->user_id,
                'target_email'        => $targetUser?->email,
                'role_assignment_id'  => $assignment->id,
                'role_id'             => $assignment->role_id,
                'policy_id'           => $policyId,
            ]);

            return $assignment->fresh(['policy']);
        });
    }

    /**
     * Powers the "Grant a Role" picker (GET /role-assignments/search-users).
     * Searches across ALL roles by design — a grant target can be a
     * student, alumni, admin, or another super admin, unlike
     * SystemUserController::index(), which is intentionally scoped to
     * admin/super-admin accounts only.
     *
     * Uses PREFIX matching (LIKE 'term%') rather than substring
     * matching — this is what lets MySQL use the B-tree indexes added
     * in the 2026_08_12 migration instead of a full table scan, which
     * is what keeps this fast as the student roster grows into the
     * thousands. Results are capped at 10 (typeahead, not a directory
     * listing) and exclude Deactivated / Pending Activation accounts —
     * granting a role to an account that can't yet log in isn't
     * meaningful.
     */
    public function searchGrantableUsers(string $term): Collection
    {
        // Escape LIKE metacharacters in the raw user input. The query
        // builder already parameterizes this value (no SQL injection
        // risk), but an unescaped '%' or '_' typed by the admin would
        // change LIKE's matching semantics (e.g. a literal "%" alone
        // would match every row) rather than being treated as a literal
        // character to search for.
        $escaped = addcslashes($term, '%_\\');
        $prefix  = $escaped . '%';

        return SystemUser::query()
            ->where('status', 'Activated')
            ->where(function ($q) use ($prefix) {
                $q->where('email', 'like', $prefix)
                    ->orWhereHas('studentProfile', fn ($p) => $p
                        ->where('first_name', 'like', $prefix)
                        ->orWhere('last_name', 'like', $prefix))
                    ->orWhereHas('adminProfile', fn ($p) => $p
                        ->where('first_name', 'like', $prefix)
                        ->orWhere('last_name', 'like', $prefix))
                    ->orWhereHas('alumniProfile', fn ($p) => $p
                        ->where('first_name', 'like', $prefix)
                        ->orWhere('last_name', 'like', $prefix))
                    // Undergrad Requestor Registration — Phase 5. This
                    // search is the TARGET picker — "who do I grant a
                    // role assignment TO" — and is deliberately
                    // role-agnostic (no whereIn('role_id', ...) above);
                    // an Approved Undergrad Requestor is an Activated
                    // account like any other and can legitimately be
                    // the target of an Admin-tier grant (the classic
                    // "staff" pattern, symmetric to student-staff). This
                    // is about search-BY-NAME reaching such an account;
                    // it was already findable by exact email even
                    // without this clause.
                    ->orWhereHas('undergradRequestorProfile', fn ($p) => $p
                        ->where('first_name', 'like', $prefix)
                        ->orWhere('last_name', 'like', $prefix));
            })
            ->with(['studentProfile', 'adminProfile', 'alumniProfile', 'undergradRequestorProfile', 'activeRoleAssignments'])
            ->orderBy('email')
            ->limit(10)
            ->get();
    }

    /**
     * Cascade-revoke every Active role assignment a user holds — the
     * Layer-1-to-Layer-2 cascade your account/entitlement model calls
     * for: deactivating an account (Layer 1: "can they log in at all")
     * should automatically end every entitlement they hold (Layer 2:
     * "what can they currently do"), not leave role_assignments rows
     * sitting there marked Active forever.
     *
     * Called from AdminUserService::update() when status moves away
     * from 'Activated'. Deliberately does NOT delete tokens itself —
     * the caller already does that as part of the same deactivation
     * transaction (see EnsureAccountActive's docblock on why that has
     * to happen regardless of this method), so doing it again here
     * would just be a second, redundant DELETE.
     *
     * Reason is auto-generated rather than asked of the caller — this
     * always fires as a side effect of a status change already being
     * audited in its own right (ACTION_ADMIN_UPDATED), so each
     * individual ACTION_ROLE_REVOKED entry just needs to point back at
     * that cause, not collect a second human-authored reason for the
     * same action.
     *
     * @return Collection<int, RoleAssignment>
     */
    public function revokeAllForUser(SystemUser $user, Request $request): Collection
    {
        return DB::transaction(function () use ($user, $request) {
            $assignments = RoleAssignment::where('user_id', $user->user_id)
                ->active()
                ->lockForUpdate()
                ->get();

            $reason = 'Account deactivated — all role assignments automatically revoked.';
            $actor  = $request->user();

            foreach ($assignments as $assignment) {
                $assignment->update([
                    'status'            => RoleAssignment::STATUS_REVOKED,
                    'revoked_by'        => $actor?->user_id,
                    'revoked_at'        => now(),
                    'revocation_reason' => $reason,
                ]);

                $this->auditLogger->log($request, $actor ?? $user, AuditLog::ACTION_ROLE_REVOKED, [
                    'target_user_id'     => $user->user_id,
                    'target_email'       => $user->email,
                    'role_assignment_id' => $assignment->id,
                    'role_id'            => $assignment->role_id,
                    'reason'             => $reason,
                ]);
            }

            return $assignments;
        });
    }

    /**
     * Explicit offboarding: revoke a specific role assignment. Does NOT
     * touch the user's other role assignments — revoking the Admin side
     * of a student-staff account leaves their Student assignment (and
     * access) untouched, which is the whole point of separating these
     * from a single users.role_id column.
     *
     * SECURITY: revokes ALL of the user's active Sanctum tokens, not just
     * ones somehow tied to the revoked role — RIS sessions aren't
     * currently role-scoped at the token level (see AuthController /
     * SsoAuthService — every login issues one token for the account),
     * so a revoked-but-still-logged-in admin must be forced to
     * re-authenticate entirely, not just lose access to the specific
     * role client-side. This mirrors how a Deactivated account is
     * already treated (UserProvisioningService::provision() rejects it
     * outright on next login) — a revocation should take effect
     * immediately, not at the next natural token expiry.
     *
     * DELIBERATE GUARD: refuses to revoke a user's ONLY currently
     * Active assignment. RoleResolver::resolve() (and therefore every
     * login) always derives the account's role from the raw
     * users.role_id column, never from role_assignments — so revoking
     * someone's last remaining row would force one re-login (their
     * tokens get deleted) and then silently do nothing: they'd log
     * back in with the exact same access, because role_assignments was
     * never the thing gating it. That leaves a Revoked row on record
     * for an account that was never actually denied anything — exactly
     * the kind of state that misleads whoever reads the Roles tab
     * later. Ending ALL of someone's access is Layer 1's job
     * (deactivate the account — see AdminUserService::update() /
     * RoleAssignmentService::revokeAllForUser()), not Layer 2's;
     * revoke() here is specifically for offboarding ONE role off a
     * multi-role account while the rest of their access continues.
     *
     * @throws ValidationException
     */
    public function revoke(RoleAssignment $assignment, string $reason, Request $request): RoleAssignment
    {
        if ($assignment->status !== RoleAssignment::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'status' => "This role assignment is already '{$assignment->status}' and cannot be revoked again.",
            ]);
        }

        return DB::transaction(function () use ($assignment, $reason, $request) {
            $activeCount = RoleAssignment::where('user_id', $assignment->user_id)
                ->active()
                ->lockForUpdate()
                ->count();

            if ($activeCount <= 1) {
                throw ValidationException::withMessages([
                    'role_id' => 'This is the only active role this user holds. Revoking it here would '
                        . 'appear to end their access without actually doing so — deactivate the account '
                        . 'instead to end all access.',
                ]);
            }

            $assignment->update([
                'status'            => RoleAssignment::STATUS_REVOKED,
                'revoked_by'        => $request->user()->user_id,
                'revoked_at'        => now(),
                'revocation_reason' => $reason,
            ]);

            $targetUser = $assignment->user;
            $targetUser->tokens()->delete();

            $this->auditLogger->log($request, $request->user(), AuditLog::ACTION_ROLE_REVOKED, [
                'target_user_id'     => $targetUser->user_id,
                'target_email'       => $targetUser->email,
                'role_assignment_id' => $assignment->id,
                'role_id'            => $assignment->role_id,
                'reason'             => $reason,
            ]);

            return $assignment;
        });
    }

    /**
     * Step 3 — session-scoped role switching. Validates the caller
     * actually holds an Active (and not-yet-expired) assignment for
     * $roleId, then reissues the session's Sanctum token stamped with
     * that assignment's id in active_role_assignment_id.
     *
     * Reissues rather than mutating the existing token in place for the
     * same reason a fresh token is issued on login: the plaintext value
     * the browser is holding never changes meaning silently under it —
     * a new plaintext token means a new cookie write, which is the
     * honest signal to the client that its session's authority just
     * changed. RIS is already single-session-per-account (see
     * AuthController::login()'s `$user->tokens()->delete()`), so this
     * mirrors that: delete the current token, issue exactly one new
     * one carrying the same auth-method name (so logout()'s
     * sanctum-local/sanctum-idp check keeps working) plus the assumed
     * role.
     *
     * Does NOT touch other role_assignments rows or force-logout other
     * sessions — unlike revoke(), switching is a normal, expected,
     * reversible action by the account holder themselves, not an
     * offboarding event.
     *
     * @return array{assignment: RoleAssignment, token: string, token_model: \Laravel\Sanctum\PersonalAccessToken}
     * @throws ValidationException
     */
    public function switchTo(SystemUser $user, int $roleId, Request $request): array
    {
        return DB::transaction(function () use ($user, $roleId, $request) {
            $assignment = RoleAssignment::where('user_id', $user->user_id)
                ->where('role_id', $roleId)
                ->active()
                ->lockForUpdate()
                ->first();

            if (!$assignment || !$assignment->isCurrentlyActive()) {
                throw ValidationException::withMessages([
                    'role_id' => 'You do not currently hold an active assignment for that role.',
                ]);
            }

            // Preserve the auth-method marker ('sanctum-idp' /
            // 'sanctum-local') so AuthController::logout() can still
            // tell which flow to run after the switch.
            $currentToken = $user->currentAccessToken();
            $tokenName    = $currentToken?->name ?? 'sanctum-idp';

            $user->tokens()->delete();

            $newToken = $user->createToken($tokenName);
            $newToken->accessToken->forceFill([
                'active_role_assignment_id' => $assignment->id,
            ])->save();

            $this->auditLogger->log($request, $user, AuditLog::ACTION_ROLE_SWITCHED, [
                'target_user_id'      => $user->user_id,
                'target_email'        => $user->email,
                'role_assignment_id'  => $assignment->id,
                'role_id'             => $assignment->role_id,
                'policy_id'           => $assignment->policy_id,
            ]);

            return [
                'assignment'  => $assignment,
                'token'       => $newToken->plainTextToken,
                'token_model' => $newToken->accessToken,
            ];
        });
    }
}