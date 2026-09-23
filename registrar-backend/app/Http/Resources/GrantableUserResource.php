<?php

namespace App\Http\Resources;

use App\Models\SystemUser;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Deliberately minimal — this resource is exposed via a typeahead search
 * that (unlike UserResource / SystemUserController) is not scoped to a
 * single already-known account, so it returns the least data needed to
 * let a Super Admin correctly identify a person before granting them a
 * role. No IdP tokens, no contact/address info, no policy internals —
 * see GrantableUserResource's use in RoleAssignmentController::searchUsers().
 */
class GrantableUserResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'user_id'   => $this->user_id,
            'email'     => $this->email,
            'full_name' => $this->resolveFullName(),
            'role_id'   => $this->role_id,
            'role_name' => $this->resolveRoleName($this->role_id),

            // Role IDs this person currently holds an Active assignment
            // for — lets the picker warn "already holds Admin" instead
            // of the Super Admin only finding out after submitting the
            // grant form and hitting RoleAssignmentService::grant()'s
            // duplicate-assignment rejection.
            'active_role_ids' => $this->whenLoaded(
                'activeRoleAssignments',
                fn () => $this->activeRoleAssignments->pluck('role_id')->values()
            ),
        ];
    }

    private function resolveFullName(): string
    {
        $profile = match ($this->role_id) {
            SystemUser::ROLE_STUDENT => $this->studentProfile,
            SystemUser::ROLE_ADMIN, SystemUser::ROLE_SUPER_ADMIN => $this->adminProfile,
            SystemUser::ROLE_ALUMNI => $this->alumniProfile,
            // Undergrad Requestor Registration — Phase 5. Without this
            // case, this resource — used by BOTH
            // RoleAssignmentController::searchUsers() and (Phase 5)
            // CashierOrOverrideController::searchUsers() — silently
            // fell through to `default => null` for every Undergrad
            // Requestor result, showing the admin their raw email in
            // the picker instead of a name, the moment role 5 became
            // searchable through either endpoint.
            SystemUser::ROLE_UNDERGRAD_REQUESTOR => $this->undergradRequestorProfile,
            default => null,
        };

        if (!$profile) {
            return $this->email;
        }

        return trim("{$profile->first_name} {$profile->last_name}");
    }

    private function resolveRoleName(int $roleId): string
    {
        return match ($roleId) {
            SystemUser::ROLE_STUDENT             => 'Student',
            SystemUser::ROLE_ALUMNI              => 'Alumni',
            // Undergrad Requestor Registration — Phase 5. Same gap as
            // resolveFullName() above — this role is now a real result
            // in both pickers this resource backs, so 'Unknown' is no
            // longer an acceptable fallback for it specifically.
            SystemUser::ROLE_UNDERGRAD_REQUESTOR => 'Undergrad Requestor',
            SystemUser::ROLE_ADMIN               => 'Admin',
            SystemUser::ROLE_SUPER_ADMIN          => 'Super Admin',
            default                               => 'Unknown',
        };
    }
}