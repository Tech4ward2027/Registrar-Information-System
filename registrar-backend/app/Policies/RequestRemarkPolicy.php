<?php

namespace App\Policies;

use App\Models\RequestRemark;
use App\Models\SystemUser;

/**
 * Deficiency Notice & Withdrawn Status — Phase 3.
 *
 * Auto-discovered by Laravel's model-to-policy naming convention
 * (App\Models\RequestRemark -> App\Policies\RequestRemarkPolicy), same
 * as every other policy in this codebase — no explicit mapping needed
 * in a service provider.
 *
 * Issuing a new notice is authorized against the PARENT DocumentRequest
 * instead (see DocumentRequestPolicy::issueDeficiencyNotice()), since no
 * RequestRemark instance exists yet at that point. clear()/void() below
 * act on an existing instance, so they belong here.
 */
class RequestRemarkPolicy
{
    // -------------------------------------------------------
    // Clear a Deficiency Notice — same tier as
    // DocumentRequestPolicy::issueDeficiencyNotice(): always exactly
    // 'Process'. Whether the notice is actually still open is a
    // business rule enforced by DeficiencyNoticeService::clear(), not
    // an authorization concern.
    // -------------------------------------------------------
    public function clear(SystemUser $user, RequestRemark $remark): bool
    {
        return $user->isStaff() && $user->hasModuleAccess('dashboard', 'Process');
    }

    // -------------------------------------------------------
    // Void a Deficiency Notice — same tier as clear() above. Voiding is
    // the "never resolved" escalation outcome, but it is still a normal
    // Process-tier staff action, not a higher-privilege one: any staff
    // member who can issue/clear a notice can also void it.
    // -------------------------------------------------------
    public function void(SystemUser $user, RequestRemark $remark): bool
    {
        return $user->isStaff() && $user->hasModuleAccess('dashboard', 'Process');
    }
}
