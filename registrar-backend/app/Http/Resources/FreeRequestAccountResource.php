<?php

namespace App\Http\Resources;

use App\Models\SystemUser;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * FESPEC-0008 — Free Document/Certificate Request.
 *
 * Result shape for GET /free-requests/search-accounts
 * (FreeRequestService::searchAccounts()).
 *
 * Deliberately NOT GrantableUserResource: that resource is documented
 * and scoped for RoleAssignmentController's "pick who gets a role"
 * picker and carries no academic info. A Registrar Admin filing a free
 * request needs to actually confirm identity against the physical
 * credentials/records in front of them — student number, program, and
 * (for alumni) year of graduation are exactly what the First Copy
 * Free Issuance for Graduates Policy's records-check step (§3.4) is
 * checking against — so this resource surfaces that instead of
 * inventing a second near-duplicate of GrantableUserResource with no
 * real distinction.
 *
 * Expects the model to already have studentProfile / academicRecord /
 * alumniProfile / alumniProfile.academicRecord eager-loaded, exactly as
 * FreeRequestService::searchAccounts() loads them — this resource never
 * lazy-loads, so N+1 queries can't creep back in here later.
 */
class FreeRequestAccountResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'user_id'   => $this->user_id,
            'email'     => $this->email,
            'full_name' => $this->resolveFullName(),
            'role_id'   => $this->role_id,
            'role_name' => $this->resolveRoleName($this->role_id),

            'student_number'     => $this->resolveStudentNumber(),
            'program'            => $this->resolveProgram(),
            'year_of_graduation' => $this->role_id === SystemUser::ROLE_ALUMNI
                ? $this->alumniProfile?->academicRecord?->year_of_graduation
                : null,
        ];
    }

    private function resolveFullName(): string
    {
        $profile = match ((int) $this->role_id) {
            SystemUser::ROLE_STUDENT => $this->studentProfile,
            SystemUser::ROLE_ALUMNI  => $this->alumniProfile,
            default                  => null,
        };

        if (!$profile) {
            return $this->email;
        }

        return trim("{$profile->first_name} {$profile->last_name}");
    }

    private function resolveStudentNumber(): ?string
    {
        return match ((int) $this->role_id) {
            SystemUser::ROLE_STUDENT => $this->academicRecord?->student_number,
            SystemUser::ROLE_ALUMNI  => $this->alumniProfile?->academicRecord?->student_number,
            default                  => null,
        };
    }

    private function resolveProgram(): ?string
    {
        return match ((int) $this->role_id) {
            SystemUser::ROLE_STUDENT => $this->academicRecord?->course,
            SystemUser::ROLE_ALUMNI  => $this->alumniProfile?->academicRecord?->course,
            default                  => null,
        };
    }

    private function resolveRoleName(int $roleId): string
    {
        return match ($roleId) {
            SystemUser::ROLE_STUDENT     => 'Student',
            SystemUser::ROLE_ALUMNI      => 'Alumni',
            SystemUser::ROLE_ADMIN       => 'Admin',
            SystemUser::ROLE_SUPER_ADMIN => 'Super Admin',
            default                      => 'Unknown',
        };
    }
}
