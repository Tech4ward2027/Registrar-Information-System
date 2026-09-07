<?php

namespace App\Policies;

use App\Models\DocumentRequest;
use App\Models\SystemUser;

class DocumentRequestPolicy
{
    // -------------------------------------------------------
    // List all requests
    // All authenticated roles can access index()
    // (controller filters results by role)
    // -------------------------------------------------------
    public function viewAny(SystemUser $user): bool
    {
        return in_array($user->role_id, [
            SystemUser::ROLE_STUDENT,
            SystemUser::ROLE_ALUMNI,
            SystemUser::ROLE_ADMIN,
            SystemUser::ROLE_SUPER_ADMIN,
        ]);
    }

    // -------------------------------------------------------
    // View a specific request
    // Admin/Super Admin → any request
    // Student/Alumni → only their own
    // -------------------------------------------------------
    public function view(SystemUser $user, DocumentRequest $request): bool
    {
        if ($user->isStaff()) {
            return true;
        }

        return (int) $request->user_id === (int) $user->user_id;
    }

    // -------------------------------------------------------
    // Create a request
    // Only students and alumni can submit document requests
    // -------------------------------------------------------
    public function create(SystemUser $user): bool
    {
        return in_array($user->role_id, [
            SystemUser::ROLE_STUDENT,
            SystemUser::ROLE_ALUMNI,
        ]);
    }

    // -------------------------------------------------------
    // Update a request
    // Only admin/super admin can update requests.
    // Students cannot edit their own submitted requests —
    // once submitted, it enters the registrar's workflow.
    //
    // Work Item #1 — Granular Per-Action Permissions: this is the
    // COARSE gate only — "does this admin have any dashboard WRITE
    // action at all (Process or Complete)". PUT /document-requests/{id}
    // is one endpoint that handles every status transition (and OR
    // number / receipt date edits), so a static per-request check like
    // this one cannot tell which specific action a given call actually
    // needs — only DocumentRequestService::updateRequest() can, once
    // the real target status_id (or which fields are changing) is
    // known. That fine-grained check is what actually blocks a Student
    // Staff account (View + Complete only) from setting Ready/Awaiting-
    // Signature, even though it passes this coarse check.
    // -------------------------------------------------------
    public function update(SystemUser $user, DocumentRequest $request): bool
    {
        if (!$user->isStaff()) {
            return false;
        }

        return $user->hasModuleAccess('dashboard', 'Process')
            || $user->hasModuleAccess('dashboard', 'Complete');
    }

    // -------------------------------------------------------
    // Delete a request
    // Only admin/super admin can delete requests
    // -------------------------------------------------------
    public function delete(SystemUser $user, DocumentRequest $request): bool
    {
        return $user->isStaff();
    }

    // -------------------------------------------------------
    // Claim a request (QR scan or manual claim_code entry)
    // Only admin/super admin operate the claiming counter —
    // same authorization shape as update(), since claiming is
    // ultimately just a specific status transition performed by staff.
    //
    // Work Item #1 — Granular Per-Action Permissions: unlike update(),
    // this stays a clean single-action gate. claimRequest() can only
    // ever produce ReadyToClaim -> Completed (see
    // DocumentRequestService::claimRequest()), so there's no
    // conditional-on-request-content ambiguity here — it always
    // requires exactly 'Complete', never 'Process'.
    // -------------------------------------------------------
    public function claim(SystemUser $user): bool
    {
        return $user->isStaff() && $user->hasModuleAccess('dashboard', 'Complete');
    }

    // -------------------------------------------------------
    // Archive / restore a request
    // Per the Archive Eligibility Policy – Administrator, any
    // authorized admin/super admin may archive or restore a request
    // regardless of its current status.
    // -------------------------------------------------------
    public function archive(SystemUser $user, DocumentRequest $request): bool
    {
        return $user->isStaff();
    }

    public function restore(SystemUser $user, DocumentRequest $request): bool
    {
        return $user->isStaff();
    }

    // -------------------------------------------------------
    // Withdraw a request (Deficiency Notice & Withdrawn Status — Phase 1)
    // Same tier as other admin status actions — requires the 'Process'
    // dashboard action, same as update() requires for every target
    // status except Completed. Unlike update(), this is always exactly
    // one action (never conditional on request content), same clean
    // single-action shape as claim() above.
    // -------------------------------------------------------
    public function withdraw(SystemUser $user, DocumentRequest $request): bool
    {
        return $user->isStaff() && $user->hasModuleAccess('dashboard', 'Process');
    }

    // -------------------------------------------------------
    // Issue a Deficiency Notice (Deficiency Notice & Withdrawn Status —
    // Phase 3). Same tier as withdraw() above — always exactly
    // 'Process', never conditional on request content, same clean
    // single-action shape. Fine-grained business rules (archived/
    // withdrawn parent, an already-open notice) are enforced by
    // DeficiencyNoticeService::issue(), not here — this policy only
    // answers "may this user issue notices at all."
    // -------------------------------------------------------
    public function issueDeficiencyNotice(SystemUser $user, DocumentRequest $request): bool
    {
        return $user->isStaff() && $user->hasModuleAccess('dashboard', 'Process');
    }

    // -------------------------------------------------------
    // Close a request as "Closed — Unable to Process" (Data Retention &
    // Disposal Policy §3.4). Same coarse tier as withdraw()/
    // issueDeficiencyNotice() above — 'Process' module access — for
    // consistency with every other admin status-write action on this
    // resource. FLAG FOR FUTURE REVIEW: this action's real-world
    // gravity (permanently closing a case on proof of a requestor's
    // death or incapacity) may warrant a stricter capability than the
    // same 'Process' tier that also covers routine actions like
    // issuing a Deficiency Notice — e.g. requiring a distinct
    // 'Close' action token, or restricting to Registrar Staff with a
    // specific policy grant, or Super Admin only. Implemented at
    // parity with the existing tier for now to avoid inventing a new
    // permission primitive as a side effect of this feature; revisit
    // with whoever owns the Policy/module-grant system if the
    // Registrar's Office wants tighter gating here specifically.
    // -------------------------------------------------------
    public function closeUnableToProcess(SystemUser $user, DocumentRequest $request): bool
    {
        return $user->isStaff() && $user->hasModuleAccess('dashboard', 'Process');
    }
}