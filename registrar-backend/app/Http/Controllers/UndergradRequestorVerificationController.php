<?php

namespace App\Http\Controllers;

use App\Contracts\UndergradRequestorVerificationServiceInterface;
use App\Http\Requests\UndergradRequestor\RejectUndergradRequestorVerificationRequest;
use App\Http\Resources\UndergradRequestorQueueResource;
use App\Http\Resources\UndergradRequestorVerificationDetailResource;
use App\Models\SystemUser;
use Illuminate\Http\Request;

/**
 * Undergrad Requestor Registration — Phase 4.
 *
 * The Registrar Admin verification queue. Every action here is gated at
 * the route level by BOTH 'role:3,4' and
 * 'module:undergrad_verification,<Action>' — the module identifier
 * registered in Phase 0 — so who may merely View the queue can be
 * separated in policy configuration from who may Approve or Reject,
 * without a code change. See routes/api.php and Policy::MODULE_ACTIONS.
 *
 * Deliberately thin: no query building, no advisory-check logic, no
 * status transitions. All of that lives in
 * UndergradRequestorVerificationService, which is the single place the
 * feature's five invariants are enforced (see its interface). This class
 * only binds HTTP to that service and shapes the response.
 *
 * ── Route-model binding ───────────────────────────────────────────────
 * {undergradRequestor} binds to SystemUser by its primary key (user_id).
 * The account is keyed on rather than the verification row's own id
 * because every other identifier a Registrar Admin has to hand — the
 * email on a support ticket, the account row in an audit log entry — is
 * the user. The service is what asserts the bound account is actually a
 * reviewable Undergrad Requestor submission; an arbitrary user_id
 * belonging to, say, an Admin gets a validation error from
 * resolveReviewable(), never a leaked record.
 */
class UndergradRequestorVerificationController extends Controller
{
    public function __construct(
        private UndergradRequestorVerificationServiceInterface $verificationService,
    ) {}

    /**
     * GET /api/admin/undergrad-requestors?status=pending
     *
     * Email-verified submissions only (D10), oldest first. `status`
     * defaults to pending — the queue's reason for existing — with
     * approved/rejected available for follow-up and support lookups.
     */
    public function index(Request $request)
    {
        $submissions = $this->verificationService->queue([
            'status'   => $request->query('status', 'pending'),
            'search'   => $request->query('search'),
            'per_page' => $request->query('per_page'),
        ]);

        return UndergradRequestorQueueResource::collection($submissions);
    }

    /**
     * GET /api/admin/undergrad-requestors/{undergradRequestor}
     *
     * The full review record, with both advisory checks (D6) freshly run
     * and persisted. Note this endpoint intentionally has a side effect
     * for a still-pending submission: it records that the checks were
     * performed. See the service's reviewDetail() for why.
     */
    public function show(SystemUser $undergradRequestor, Request $request)
    {
        $detail = $this->verificationService->reviewDetail($undergradRequestor, $request);

        return new UndergradRequestorVerificationDetailResource($detail);
    }

    /**
     * POST /api/admin/undergrad-requestors/{undergradRequestor}/approve
     *
     * Moves the account to 'Pending Activation' — approved, awaiting the
     * person's own first IDP login, which is what actually activates it
     * (D4). No request body: an approval has nothing to say beyond who
     * made it and when, both of which come from the authenticated
     * session and the clock.
     */
    public function approve(SystemUser $undergradRequestor, Request $request)
    {
        $this->verificationService->approve($undergradRequestor, $request);

        return $this->show($undergradRequestor->refresh(), $request);
    }

    /**
     * POST /api/admin/undergrad-requestors/{undergradRequestor}/reject
     *
     * Requires a rejection reason — see
     * RejectUndergradRequestorVerificationRequest for the three separate
     * things that depend on it.
     */
    public function reject(SystemUser $undergradRequestor, RejectUndergradRequestorVerificationRequest $request)
    {
        $this->verificationService->reject(
            $undergradRequestor,
            $request->validated('rejection_reason'),
            $request,
        );

        return $this->show($undergradRequestor->refresh(), $request);
    }
}
