<?php

namespace App\Http\Requests\RoleAssignment;

use App\Models\SystemUser;
use Illuminate\Foundation\Http\FormRequest;

class StoreRoleAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route middleware ('role:4') + RoleAssignmentPolicy::grant()
        // already restrict this to Super Admin — see routes/api.php.
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id'   => 'required|integer|exists:users,user_id',
            // Undergrad Requestor Registration — Phase 5: deliberately
            // NOT added here, unlike Student/Alumni. Considered and
            // rejected — see RoleAssignmentService::assertDirectionAllowed()'s
            // matching note for the full reasoning: this role has
            // exactly one valid path into existence (the public
            // onboarding + Admin approval pipeline, D4), and a
            // role_assignments grant would produce a role_id = 5
            // account with no undergrad_requestor_verifications row —
            // permanently and correctly refused by
            // EnsureUndergradRequestorApproved, since that middleware
            // has no way to distinguish "never verified" from "not yet
            // approved." A dead-end account, not a usable grant.
            'role_id'   => 'required|integer|in:' . implode(',', [
                SystemUser::ROLE_STUDENT,
                SystemUser::ROLE_ALUMNI,
                SystemUser::ROLE_ADMIN,
                SystemUser::ROLE_SUPER_ADMIN,
            ]),
            'policy_id' => 'nullable|integer|exists:policies,policy_id|required_if:role_id,' . SystemUser::ROLE_ADMIN,
            // Deliberately no default applied server-side (see
            // RoleAssignmentService::grant() docblock) — null is a valid,
            // explicit choice for "indefinite," but the caller has to
            // send it, not omit it by accident. 'sometimes' + nullable
            // lets the request omit the key OR send null; the service
            // treats both the same way.
            'expires_at' => 'sometimes|nullable|date|after:now',
        ];
    }

    public function messages(): array
    {
        return [
            'policy_id.required_if' => 'A policy is required when granting the Admin role.',
        ];
    }
}