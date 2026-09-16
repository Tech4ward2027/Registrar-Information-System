<?php

namespace App\Services;

use App\Contracts\NotificationServiceInterface;
use App\Contracts\UndergradEnrollmentLookupClientInterface;
use App\Contracts\UndergradRequestorVerificationServiceInterface;
use App\DTOs\Ogos\OgosEnrollmentLookupResult;
use App\Enums\UndergradRequestorVerificationStatusEnum;
use App\Mail\UndergradRequestorDecisionMail;
use App\Models\AuditLog;
use App\Models\StudentAcademicRecord;
use App\Models\SystemUser;
use App\Models\UndergradRequestorProfile;
use App\Models\UndergradRequestorVerification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Undergrad Requestor Registration — Phase 4.
 *
 * See UndergradRequestorVerificationServiceInterface for the contract
 * and the five invariants this class exists to hold.
 *
 * ── Why the two advisory checks are persisted, not just displayed ─────
 * graduate_verifications set the precedent this feature deliberately
 * mirrors (D6): the durable record is not the identity document, it is
 * the fact that a check was performed, what it said, who looked, and
 * when. Displaying the OGOS/local hints without persisting them would
 * leave a verification record that says "Approved by Admin #7" with no
 * trace of what that Admin was actually shown — impossible to review
 * later, and impossible to distinguish "OGOS said they're enrolled and
 * the Admin approved anyway" from "OGOS was down that day."
 *
 * Both checks are therefore refreshed and written in refreshAdvisoryChecks()
 * at two moments: when a reviewer opens the record, and again at the
 * moment of decision — so the persisted hints always describe the state
 * at decision time even if the decision arrives via a direct API call
 * that never opened the detail view.
 */
class UndergradRequestorVerificationService implements UndergradRequestorVerificationServiceInterface
{
    /**
     * Cap on the "is this student number shared with other submissions?"
     * hint. The number itself is the signal; enumerating hundreds of
     * colliding rows would be neither useful to a reviewer nor cheap.
     */
    private const DUPLICATE_SAMPLE_LIMIT = 5;

    public function __construct(
        private AuditLogger                              $auditLogger,
        private NotificationServiceInterface             $notificationService,
        private UndergradEnrollmentLookupClientInterface $enrollmentLookup,
    ) {}

    // ─────────────────────────────────────────────────────────────
    // Queue
    // ─────────────────────────────────────────────────────────────

    public function queue(array $filters): LengthAwarePaginator
    {
        $status  = $this->resolveStatusFilter($filters['status'] ?? 'pending');
        $search  = trim((string) ($filters['search'] ?? ''));
        $perPage = min(max((int) ($filters['per_page'] ?? 20), 1), 100);

        $query = SystemUser::query()
            ->where('role_id', SystemUser::ROLE_UNDERGRAD_REQUESTOR)
            // Invariant 3 (D10) — an unconfirmed email never reaches a
            // reviewer. Expressed through the model's own scope rather
            // than an inline whereNotNull so this predicate has exactly
            // one definition (see UndergradRequestorProfile::scopeEmailVerified()).
            ->whereHas('undergradRequestorProfile', fn ($q) => $q->emailVerified())
            ->whereHas('undergradRequestorVerification', fn ($q) => $q->where('status', $status->value))
            ->with([
                'undergradRequestorProfile',
                'undergradRequestorVerification.reviewer',
            ]);

        if ($search !== '') {
            $query->where(function ($outer) use ($search) {
                $outer->where('email', 'like', "%{$search}%")
                    ->orWhereHas('undergradRequestorProfile', function ($q) use ($search) {
                        $q->where('student_number', 'like', "%{$search}%")
                            ->orWhere('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%");
                    });
            });
        }

        // Oldest first: this is an SLA-bearing work queue, so the person
        // who has been waiting longest is the one a reviewer should see
        // first. Deliberately the opposite of AccessRequestController::
        // index()'s latest() ordering, which is a log, not a queue.
        return $query
            ->orderBy('created_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    // ─────────────────────────────────────────────────────────────
    // Detail (with advisory checks)
    // ─────────────────────────────────────────────────────────────

    public function reviewDetail(SystemUser $user, Request $request): array
    {
        [$profile, $verification] = $this->resolveReviewable($user);

        // Refreshed on open as well as on decision. This is a write on a
        // GET, which is normally worth avoiding — it is deliberate here
        // because the advisory columns are a record of "a check was
        // performed and shown to a reviewer," and that is exactly the
        // event being recorded. It is idempotent (same columns, same
        // shape, overwritten each time) and touches no decision field.
        // Skipped once a decision exists, so reopening a closed record
        // can never rewrite the hints the decision was actually made on.
        $lookup = $verification->isPending()
            ? $this->refreshAdvisoryChecks($profile, $verification)
            : null;

        return [
            'user'         => $user->setRelation('undergradRequestorProfile', $profile),
            'verification' => $verification->fresh(['reviewer', 'matchedStudentProfile']),
            'hints'        => $this->buildHints($profile, $verification->fresh(), $lookup),
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // Decisions
    // ─────────────────────────────────────────────────────────────

    public function approve(SystemUser $user, Request $request): UndergradRequestorVerification
    {
        [$profile, $verification] = $this->resolveDecidable($user);

        $lookup = $this->refreshAdvisoryChecks($profile, $verification);

        $verification = DB::transaction(function () use ($user, $verification, $request) {
            $verification->update([
                'status'           => UndergradRequestorVerificationStatusEnum::Approved,
                'reviewed_by'      => $request->user()->user_id,
                'reviewed_at'      => now(),
                'rejection_reason' => null,
            ]);

            // 'Pending Verification' (waiting on a human) -> 'Pending
            // Activation' (waiting on the person's first IDP login).
            // This is the same status an admin invite sits at between
            // creation and first SSO login, which is precisely what
            // UserProvisioningService::provision()'s auto-activation
            // branch keys off (D4) — one mechanism, widened, not a
            // parallel one. This service never writes 'Activated':
            // only a real IDP login can link idp_user_id.
            //
            // pending_expires_at is cleared rather than extended. That
            // column encodes D9's 14-day ABANDONMENT window — "nobody
            // acted on this submission." Someone just did. Leaving it
            // set would let the nightly sweep expire an account the
            // Registrar had already approved, purely because the person
            // took more than a fortnight to log in.
            $user->forceFill([
                'status'             => 'Pending Activation',
                'pending_expires_at' => null,
            ])->save();

            $this->auditLogger->log($request, $request->user(), AuditLog::ACTION_UNDERGRAD_REQUESTOR_APPROVED, [
                'target_user_id'                      => $user->user_id,
                'target_email'                        => $user->email,
                'undergrad_requestor_verification_id' => $verification->undergrad_requestor_verification_id,
                // The advisory state AT THE MOMENT OF DECISION, copied
                // into the immutable audit entry as well as the mutable
                // verification row — so a later re-run of the checks can
                // never rewrite what the approver was looking at.
                'local_match_found'                   => (bool) $verification->local_match_found,
                'ogos_lookup_performed'               => $verification->ogosLookupWasPerformed(),
                'ogos_match_found'                    => $verification->ogos_match_found,
            ]);

            return $verification;
        });

        $this->notifyDecision($user, $profile, $verification, $lookup);

        return $verification;
    }

    public function reject(SystemUser $user, string $reason, Request $request): UndergradRequestorVerification
    {
        [$profile, $verification] = $this->resolveDecidable($user);

        $lookup = $this->refreshAdvisoryChecks($profile, $verification);

        $verification = DB::transaction(function () use ($user, $verification, $reason, $request) {
            $verification->update([
                'status'           => UndergradRequestorVerificationStatusEnum::Rejected,
                'reviewed_by'      => $request->user()->user_id,
                'reviewed_at'      => now(),
                'rejection_reason' => $reason,
            ]);

            // 'Rejected' on the account itself, not merely on the
            // verification row — that single column is what
            // UserProvisioningService checks before role resolution to
            // raise AccountRejectedException and revoke the IdP token
            // (D8), with no join required on the login hot path.
            //
            // pending_expires_at cleared for the same reason as approve():
            // the submission has been actioned, so the abandonment window
            // no longer applies. The 90-day PII purge (D9) takes over
            // from here, keyed off reviewed_at.
            $user->forceFill([
                'status'             => 'Rejected',
                'pending_expires_at' => null,
            ])->save();

            $this->auditLogger->log($request, $request->user(), AuditLog::ACTION_UNDERGRAD_REQUESTOR_REJECTED, [
                'target_user_id'                      => $user->user_id,
                'target_email'                        => $user->email,
                'undergrad_requestor_verification_id' => $verification->undergrad_requestor_verification_id,
                'reason'                              => $reason,
                'local_match_found'                   => (bool) $verification->local_match_found,
                'ogos_lookup_performed'               => $verification->ogosLookupWasPerformed(),
                'ogos_match_found'                    => $verification->ogos_match_found,
            ]);

            return $verification;
        });

        $this->notifyDecision($user, $profile, $verification, $lookup);

        return $verification;
    }

    // ─────────────────────────────────────────────────────────────
    // Advisory checks (D6) — both hints, never authoritative
    // ─────────────────────────────────────────────────────────────

    /**
     * Run both advisory checks and persist their outcome onto the
     * verification record. Returns the OGOS result so the caller can
     * include it in the response without a second lookup.
     */
    private function refreshAdvisoryChecks(
        UndergradRequestorProfile      $profile,
        UndergradRequestorVerification $verification,
    ): OgosEnrollmentLookupResult {
        // ── Check 1: local historical mirror ──────────────────────
        // student_academic_record is RIS's own mirror of OGOS data for
        // people who have logged in as Students at some point. A hit
        // here means "we have seen this student number before" — useful
        // corroborating context for a reviewer, and nothing more. It is
        // read-only: this feature must never write to an OGOS-owned
        // table (D5).
        $localMatch = StudentAcademicRecord::query()
            ->where('student_number', $profile->student_number)
            ->with('studentProfile')
            ->first();

        // ── Check 2: live OGOS enrollment lookup ──────────────────
        // Never throws; "OGOS unreachable" is an ordinary outcome here.
        $lookup = $this->enrollmentLookup->lookup($profile->student_number, $profile->user?->email);

        $verification->forceFill([
            'local_match_found'          => $localMatch !== null,
            'matched_student_profile_id' => $localMatch?->student_profile_id,
            // NULL when the lookup could not be performed, so "we never
            // checked" stays distinguishable from "we checked and OGOS
            // doesn't know them" — see OgosEnrollmentLookupResult.
            'ogos_lookup_performed_at'   => $lookup->performed ? now() : null,
            'ogos_match_found'           => $lookup->matchFoundColumnValue(),
        ])->save();

        return $lookup;
    }

    /**
     * The reviewer-facing shape of both checks.
     *
     * Every key here is explicitly labelled advisory in the API
     * response, and the OGOS branch carries its own plain-language
     * interpretation string. That wording is not decoration: the single
     * most likely way this feature fails in production is a reviewer
     * reading "no OGOS match" as "OGOS confirms this person is eligible,"
     * when it actually means "OGOS does not track this population at
     * all." Phase 8's training material covers the same point; encoding
     * it in the payload means the UI cannot accidentally omit it.
     */
    private function buildHints(
        UndergradRequestorProfile       $profile,
        UndergradRequestorVerification  $verification,
        ?OgosEnrollmentLookupResult     $lookup,
    ): array {
        $duplicates = UndergradRequestorProfile::query()
            ->where('student_number', $profile->student_number)
            ->where('user_id', '!=', $profile->user_id)
            ->with('user:user_id,email,status')
            ->limit(self::DUPLICATE_SAMPLE_LIMIT)
            ->get();

        $duplicateCount = UndergradRequestorProfile::query()
            ->where('student_number', $profile->student_number)
            ->where('user_id', '!=', $profile->user_id)
            ->count();

        return [
            'advisory_notice' => 'All checks below are advisory only. None of them approves, rejects, or '
                . 'recommends a decision — the reviewing Admin decides.',

            'local_records_check' => [
                'label'        => 'Local historical records (RIS mirror)',
                'match_found'  => (bool) $verification->local_match_found,
                'matched_student_profile_id' => $verification->matched_student_profile_id,
                'interpretation' => $verification->local_match_found
                    ? 'RIS has seen this student number before. Corroborating context only — it does not '
                        . 'establish that this person is the same individual.'
                    : 'RIS has no historical record for this student number. This is common and proves nothing: '
                        . 'the mirror only contains students who have previously logged into RIS.',
            ],

            'ogos_enrollment_check' => [
                'label'     => 'Live OGOS enrollment lookup',
                'performed' => $lookup?->performed ?? $verification->ogosLookupWasPerformed(),
                'performed_at' => $verification->ogos_lookup_performed_at?->toIso8601String(),
                'match_found'  => $verification->ogos_match_found,
                'severity'     => $this->ogosSeverity($verification, $lookup),
                'interpretation' => $this->ogosInterpretation($verification, $lookup),
            ],

            'duplicate_student_number' => [
                'label' => 'Other submissions using this student number',
                'count' => $duplicateCount,
                'sample' => $duplicates->map(fn (UndergradRequestorProfile $other) => [
                    'user_id'   => $other->user_id,
                    'email'     => $other->user?->email,
                    'full_name' => $other->full_name,
                    'status'    => $other->user?->status,
                ])->values(),
                'interpretation' => $duplicateCount > 0
                    ? 'This student number appears on other onboarding submissions. Duplicates are permitted by '
                        . 'design (the column is deliberately non-unique) and are a review flag, not an error.'
                    : 'No other submission uses this student number.',
            ],
        ];
    }

    private function ogosSeverity(
        UndergradRequestorVerification $verification,
        ?OgosEnrollmentLookupResult    $lookup,
    ): string {
        if (!($lookup?->performed ?? $verification->ogosLookupWasPerformed())) {
            return 'unavailable';
        }

        return $verification->ogos_match_found ? 'warning' : 'info';
    }

    private function ogosInterpretation(
        UndergradRequestorVerification $verification,
        ?OgosEnrollmentLookupResult    $lookup,
    ): string {
        if (!($lookup?->performed ?? $verification->ogosLookupWasPerformed())) {
            return 'OGOS could not be reached, so this check did not run. This is NOT a "no match" result — '
                . 'nothing has been confirmed or ruled out. Retry later if the answer would change your decision.';
        }

        if ($verification->ogos_match_found) {
            return 'WARNING: OGOS reports this person as CURRENTLY ENROLLED. An Undergrad Requestor should not be. '
                . 'This is either a misclassification (they should be using the Student flow) or a potential '
                . 'misrepresentation — investigate before approving.';
        }

        return 'OGOS does not know this person as a currently enrolled student. This is the EXPECTED result for a '
            . 'genuine Undergrad Requestor and confirms nothing about their eligibility — OGOS does not track '
            . 'this population at all.';
    }

    // ─────────────────────────────────────────────────────────────
    // Notifications
    // ─────────────────────────────────────────────────────────────

    /**
     * In-app notification AND email, deliberately both.
     *
     * A decided requestor may never have held a RIS session — an
     * Approved one has not logged in yet by definition, and a Rejected
     * one now never can (AccountRejectedException, D8). An in-app
     * notification alone would therefore be delivered to an inbox the
     * rejected person is structurally unable to open. The in-app row is
     * still written for the approved case (it is waiting for them the
     * moment they first log in) and for the permanent in-system record.
     *
     * Both are best-effort and run AFTER the decision transaction has
     * committed: a mail-transport or broadcast hiccup must never roll
     * back a Registrar decision that has already been audited.
     */
    private function notifyDecision(
        SystemUser                     $user,
        UndergradRequestorProfile      $profile,
        UndergradRequestorVerification $verification,
        ?OgosEnrollmentLookupResult    $lookup,
    ): void {
        $approved = $verification->isApproved();

        $this->notificationService->send(
            recipient:    $user,
            triggerEvent: $approved ? 'undergrad_requestor_approved' : 'undergrad_requestor_rejected',
            data:         array_filter([
                'full_name' => $profile->full_name,
                'reason'    => $verification->rejection_reason,
            ]),
        );

        Mail::to($user->email)->send(
            new UndergradRequestorDecisionMail(
                firstName:       $profile->first_name,
                approved:        $approved,
                rejectionReason: $verification->rejection_reason,
            )
        );
    }

    // ─────────────────────────────────────────────────────────────
    // Guards
    // ─────────────────────────────────────────────────────────────

    /**
     * Load the profile + verification for an account that is a genuine,
     * reviewable Undergrad Requestor submission.
     *
     * @return array{0: UndergradRequestorProfile, 1: UndergradRequestorVerification}
     *
     * @throws ValidationException
     */
    private function resolveReviewable(SystemUser $user): array
    {
        if ((int) $user->role_id !== SystemUser::ROLE_UNDERGRAD_REQUESTOR) {
            throw ValidationException::withMessages([
                'user' => 'This account is not an Undergrad Requestor submission.',
            ]);
        }

        $profile      = $user->undergradRequestorProfile;
        $verification = $user->undergradRequestorVerification;

        if (!$profile || !$verification) {
            // Only reachable if a row was removed out-of-band (e.g. the
            // 90-day PII purge). Treated as not-reviewable rather than a
            // 500: there is genuinely nothing left to review.
            throw ValidationException::withMessages([
                'user' => 'This submission no longer has a reviewable record.',
            ]);
        }

        // Invariant 3 (D10). Enforced here, not only in queue()'s
        // filter — otherwise an unconfirmed submission would be
        // invisible in the list yet still decidable by anyone who knew
        // or guessed its user_id.
        if (!$profile->isEmailVerified()) {
            throw ValidationException::withMessages([
                'user' => 'This submission\'s email address has not been confirmed yet, so it is not '
                    . 'available for review.',
            ]);
        }

        return [$profile, $verification];
    }

    /**
     * As resolveReviewable(), plus the checks that only matter when a
     * decision is about to be written.
     *
     * @return array{0: UndergradRequestorProfile, 1: UndergradRequestorVerification}
     *
     * @throws ValidationException
     */
    private function resolveDecidable(SystemUser $user): array
    {
        [$profile, $verification] = $this->resolveReviewable($user);

        if (!$verification->isPending()) {
            throw ValidationException::withMessages([
                'status' => "This submission was already {$verification->status->label()}"
                    . ($verification->reviewed_at ? ' on ' . $verification->reviewed_at->format('M j, Y g:i A') : '')
                    . ' and can no longer be decided.',
            ]);
        }

        // Mirrors AccessRequestService::assertPending()'s time-aware
        // guard (QA #11): the nightly sweep runs once a day, so a
        // submission whose 14-day abandonment window has already elapsed
        // can still read 'Pending Verification' here for up to ~24h.
        // Deciding one in that window would resurrect a record the
        // system has already, by policy, given up on.
        if ($user->pending_expires_at && $user->pending_expires_at->isPast()) {
            throw ValidationException::withMessages([
                'status' => 'This submission was abandoned on '
                    . $user->pending_expires_at->format('M j, Y g:i A')
                    . ' (no action within the '
                    . config('undergrad_requestor.abandonment_days', 14)
                    . '-day window) and can no longer be decided. The requestor must submit the form again.',
            ]);
        }

        if ($user->status === 'Expired') {
            throw ValidationException::withMessages([
                'status' => 'This submission has expired and can no longer be decided. The requestor must '
                    . 'submit the onboarding form again.',
            ]);
        }

        return [$profile, $verification];
    }

    /**
     * Map the API's lowercase status filter onto the enum. An
     * unrecognised value is rejected outright rather than silently
     * defaulting to pending — a typo'd filter that quietly returns the
     * wrong queue is worse than an error.
     *
     * @throws ValidationException
     */
    private function resolveStatusFilter(string $status): UndergradRequestorVerificationStatusEnum
    {
        $resolved = UndergradRequestorVerificationStatusEnum::tryFrom(ucfirst(strtolower(trim($status))));

        if (!$resolved) {
            throw ValidationException::withMessages([
                'status' => 'Unknown status filter. Valid values: pending, approved, rejected.',
            ]);
        }

        return $resolved;
    }
}
