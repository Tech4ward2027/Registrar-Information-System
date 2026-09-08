<?php

namespace App\Http\Requests\Auth;

use App\Models\SystemUser;
use Illuminate\Foundation\Http\FormRequest;

class SetLocalPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route is already gated by ['auth:sanctum', 'role:4'] middleware
        // (routes/api.php). This check is defense-in-depth, matching the
        // pattern used elsewhere (e.g. SystemUserPolicy) — cheap to keep
        // and means this class stays correct even if the route
        // middleware is ever refactored.
        //
        // BUG FIX (session-assumed-role authorization gap): must read
        // through SystemUser::isSuperAdmin() (which resolves the
        // session's ASSUMED role via assumedRoleId()), not the raw
        // role_id column. An Admin whose account also holds an Active
        // Super Admin role_assignment and has switched into it via
        // POST /auth/switch-role is a Super Admin for the rest of this
        // session in every other gate (RoleMiddleware, EnsureModuleAccess,
        // RoleAssignmentPolicy) — this was the one remaining spot still
        // reading the raw column, so a switched-in Super Admin passed
        // the route's 'role:4' middleware and then got 403'd right back
        // out by this check. Fully backward compatible: for a classic,
        // never-switched Super Admin, isSuperAdmin() resolves to exactly
        // the same answer as the old raw-column check.
        return $this->user()?->isSuperAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            // Break-glass access is deliberately restricted to a small,
            // watched set of accounts (Super Admins only) rather than
            // being an option on every admin — see LocalAuthService docblock.
            // This rule enforces that at the point local auth is actually
            // enabled/updated for a target, regardless of what the UI sends.
            'user_id' => [
                'required',
                'integer',
                'exists:users,user_id',
                function (string $attribute, mixed $value, \Closure $fail) {
                    /** @var SystemUser|null $target */
                    $target = SystemUser::find($value);

                    if ($target && $target->role_id !== SystemUser::ROLE_SUPER_ADMIN) {
                        $fail('Local fallback access is limited to Super Admin accounts.');
                    }
                },
            ],
            'password'              => 'required|string|min:8|confirmed',
            'password_confirmation' => 'required|string',
        ];
    }
}