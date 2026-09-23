<?php

namespace App\Contracts;

use App\Models\SystemUser;
use App\Models\UndergradRequestorVerification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Undergrad Requestor Registration — Phase 4 (D6, D7, D9).
 *
 * The Registrar Admin verification workflow: the queue of pending
 * submissions, the advisory checks surfaced when one is opened, and the
 * two terminal decisions.
 *
 * Bound to UndergradRequestorVerificationService in AppServiceProvider,
 * following the same interface-first convention as
 * DocumentRequestServiceInterface and
 * UndergradRequestorRegistrationServiceInterface (Phase 2).
 *
 * ── Invariants every implementation must hold ─────────────────────────
 *
 *  1. ADMIN DECISION IS FINAL AND HUMAN (D6). Nothing in this service
 *     may approve or reject automatically, or weight the two advisory
 *     checks into a score/recommendation. Both checks are hints for a
 *     person; neither is authoritative in either direction.
 *
 *  2. NO EXTERNAL DEPENDENCY MAY BLOCK A REVIEW. OGOS being unreachable
 *     degrades to "check unavailable" (see
 *     UndergradEnrollmentLookupClientInterface), never to an error
 *     response or a blocked decision.
 *
 *  3. EMAIL-VERIFIED ONLY (D10). A submission whose onboarding email has
 *     not been confirmed is invisible to the queue AND undecidable —
 *     it must not be approvable by guessing its URL either.
 *
 *  4. EVERY DECISION IS AUDITED (D9). approve()/reject() write through
 *     AuditLogger's tamper-evident hash chain with the reviewing Admin
 *     as the actor and the requestor as target_user_id, matching
 *     ACTION_ACCESS_REQUEST_APPROVED/REJECTED's established shape.
 *
 *  5. NO IDENTITY DOCUMENTS (D7). This service records that checks were
 *     performed, their results, who decided, and when — never an
 *     uploaded ID, scan, or file of any kind.
 */
interface UndergradRequestorVerificationServiceInterface
{
    /**
     * The Admin verification queue.
     *
     * Always scoped to role_id = ROLE_UNDERGRAD_REQUESTOR and to
     * email-verified submissions only (invariant 3). Ordered
     * oldest-submission-first, because this is a work queue with an SLA,
     * not a newsfeed — the longest-waiting person should be at the top.
     *
     * @param  array{status?: string, search?: string, per_page?: int}  $filters
     */
    public function queue(array $filters): LengthAwarePaginator;

    /**
     * A single submission, with both advisory checks freshly run and
     * persisted onto the verification record.
     *
     * Returns the profile, the verification record, and the advisory
     * hints in the shape UndergradRequestorVerificationDetailResource
     * expects.
     *
     * @return array{
     *     user: SystemUser,
     *     verification: UndergradRequestorVerification,
     *     hints: array<string, mixed>
     * }
     *
     * @throws ValidationException if the account is not a reviewable
     *         Undergrad Requestor submission.
     */
    public function reviewDetail(SystemUser $user, Request $request): array;

    /**
     * Approve a pending submission.
     *
     * Transitions the verification to Approved and moves the account
     * from 'Pending Verification' (awaiting a human) to
     * 'Pending Activation' (awaiting the person's first IDP login) —
     * the exact status an admin invite sits at between creation and
     * first SSO login, which is what Phase 3's auto-activation branch
     * keys off. This service never sets 'Activated' itself: linking
     * idp_user_id is UserProvisioningService's job and happens only on
     * a real, successful IDP login (D4).
     *
     * @throws ValidationException if the submission is not decidable.
     */
    public function approve(SystemUser $user, Request $request): UndergradRequestorVerification;

    /**
     * Reject a pending submission. A reason is mandatory — it is the
     * only thing the requestor and any future reviewer will have to
     * explain the decision, and it is what the 90-day purge later
     * disposes of (D9).
     *
     * @throws ValidationException if the submission is not decidable.
     */
    public function reject(SystemUser $user, string $reason, Request $request): UndergradRequestorVerification;
}
