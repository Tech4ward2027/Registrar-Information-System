<?php

namespace App\Policies;

use App\Models\RequestRepresentative;
use App\Models\SystemUser;

/**
 * Notification Policy §3.6 authorization.
 *
 * Staff (Admin with the dashboard 'Process' tier, or Super Admin) record that
 * a requestor is deceased/incapacitated and add a representative. Reviewing
 * proof, verifying, revoking and lifting a flag are Super Admin only: those
 * are the controls that decide whose inbox receives someone else's records.
 */
class RequestRepresentativePolicy
{
    /** Flag a requestor (no model: acts on a user). */
    public function flag(SystemUser $user): bool
    {
        return $user->isStaff() && $user->hasModuleAccess('dashboard', 'Process');
    }

    /** Lift a flag (no model: acts on a user). */
    public function liftFlag(SystemUser $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function viewQueue(SystemUser $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function verify(SystemUser $user, RequestRepresentative $representative): bool
    {
        return $user->isSuperAdmin();
    }

    public function revoke(SystemUser $user, RequestRepresentative $representative): bool
    {
        return $user->isSuperAdmin();
    }

    public function viewProof(SystemUser $user, RequestRepresentative $representative): bool
    {
        return $user->isSuperAdmin();
    }
}
