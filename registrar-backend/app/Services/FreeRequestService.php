<?php

namespace App\Services;

use App\Contracts\DocumentRequestServiceInterface;
use App\DTOs\FreeRequest\FreeRequestFilingResult;
use App\Enums\RequestChannelEnum;
use App\Exceptions\FreeRequestIneligibleException;
use App\Models\GraduateVerification;
use App\Models\SystemUser;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * FESPEC-0008 — Free Document/Certificate Request.
 *
 * Backend for the admin Free Request page. Deliberately does NOT call
 * AuditLogger anywhere in this class — this codebase's established
 * convention (see DocumentRequestController/CashierOrOverrideController)
 * is that audit logging is a controller-layer concern, performed after
 * a service call succeeds, using the live Illuminate\Http\Request for
 * IP/user-agent. FreeRequestFilingResult carries everything the Phase 3
 * controller needs to write accurate, specific audit_log rows without
 * re-querying anything this class already computed.
 *
 * Originally scoped to "reuse NameMatcher for account matching" — that
 * turned out not to be buildable: NameMatcher generates name-string
 * variants to retry against the Cashier payment API for OR verification
 * and has nothing to do with looking up a registered account. This
 * class's searchAccounts() is the real, lightweight account lookup this
 * feature actually needs, built against student_profile / alumni_profile
 * directly (same query shape as CashierOrOverrideController::
 * searchUsers(), extended with the academic info the Free Request page
 * and FreeRequestEligibilityService both need to display/decide on).
 *
 * ── Concurrency: why the target user row is locked, not a partial index ──
 * Production runs MySQL, which does not support partial/filtered unique
 * indexes — see the 2026_09_04_000001_add_channel_to_document_request
 * migration's docblock. The one-free-copy-per-graduate guarantee is
 * instead enforced here: fileFreeRequest() locks the TARGET user's row
 * (`users`, via SystemUser::lockForUpdate()) for the duration of the
 * transaction, re-runs FreeRequestEligibilityService::checkMany() with
 * that lock held, and only then creates the request. Locking the target
 * user row (rather than any existing document_request row for them)
 * is deliberate: it is guaranteed to exist and be lockable even for a
 * graduate's very FIRST free request, when there is no prior
 * admin_filed_free row yet to lock. Two admins racing to file the same
 * graduate's first free TOR therefore serialize on this lock — the
 * second one re-evaluates eligibility against the first one's
 * now-committed request and correctly sees the limit already reached.
 */
class FreeRequestService
{
    public function __construct(
        private DocumentRequestServiceInterface $documentRequestService,
        private FreeRequestEligibilityService $eligibilityService,
    ) {}

    /**
     * Typeahead account search for the Free Request page — find the
     * student/alumni account a staff member is about to file a free
     * request on behalf of.
     *
     * Per Free Documents/Certificates Request Policy §3.3, the Admin
     * enters "Full Name (required), Student Number (optional), Program
     * (optional)" and the system auto-matches the registered account.
     * $name drives the base match (email / first / last name); the two
     * extra params exist purely to disambiguate when a name search
     * returns more than one plausible account (e.g. two students named
     * "Juan Dela Cruz") — both are optional filters layered on top of
     * the name match with AND semantics, not alternate search modes.
     *
     * Scoped to Activated student/alumni accounts only, same as
     * CashierOrOverrideController::searchUsers(). Eager-loads the
     * academic info needed both for display (student number, program,
     * year of graduation) and for FreeRequestEligibilityService's role
     * check downstream — the caller shouldn't need a second query per
     * result to get eligibility-relevant context.
     */
    public function searchAccounts(string $name, ?string $studentNumber = null, ?string $program = null): Collection
    {
        $name = trim($name);

        // Escape LIKE metacharacters in the raw input so a literal '%'
        // or '_' typed by staff is matched literally — same guard
        // CashierOrOverrideController::searchUsers() already applies.
        // Applied identically to all three fields below.
        $namePrefix = addcslashes($name, '%_\\') . '%';

        $query = SystemUser::query()
            ->where('status', 'Activated')
            ->whereIn('role_id', [SystemUser::ROLE_STUDENT, SystemUser::ROLE_ALUMNI])
            ->where(function ($q) use ($namePrefix) {
                $q->where('email', 'like', $namePrefix)
                    ->orWhereHas('studentProfile', fn ($p) => $p
                        ->where('first_name', 'like', $namePrefix)
                        ->orWhere('last_name', 'like', $namePrefix))
                    ->orWhereHas('alumniProfile', fn ($p) => $p
                        ->where('first_name', 'like', $namePrefix)
                        ->orWhere('last_name', 'like', $namePrefix));
            });

        $studentNumber = trim((string) $studentNumber);

        if ($studentNumber !== '') {
            // student_academic_record.student_number and
            // alumni_academic_record.student_number are the same logical
            // field on two different tables (a student's academic record
            // is renamed/carried forward, not replaced, on graduation —
            // see AlumniProvisioningService) — match against whichever
            // one this account actually has.
            $numberPrefix = addcslashes($studentNumber, '%_\\') . '%';

            $query->where(function ($q) use ($numberPrefix) {
                $q->whereHas('academicRecord', fn ($r) => $r->where('student_number', 'like', $numberPrefix))
                    ->orWhereHas('alumniProfile.academicRecord', fn ($r) => $r->where('student_number', 'like', $numberPrefix));
            });
        }

        $program = trim((string) $program);

        if ($program !== '') {
            // 'course' is this schema's actual column for a student/
            // alumni's program (see StudentAcademicRecord's docblock —
            // it's a point-in-time snapshot of programs.name, not a live
            // FK lookup), which is what the policy calls "Program".
            $programPrefix = addcslashes($program, '%_\\') . '%';

            $query->where(function ($q) use ($programPrefix) {
                $q->whereHas('academicRecord', fn ($r) => $r->where('course', 'like', $programPrefix))
                    ->orWhereHas('alumniProfile.academicRecord', fn ($r) => $r->where('course', 'like', $programPrefix));
            });
        }

        return $query
            ->with([
                'studentProfile',
                'academicRecord',
                'alumniProfile',
                'alumniProfile.academicRecord',
                'alumni',
            ])
            ->orderBy('email')
            ->limit(10)
            ->get();
    }

    /**
     * Eligibility snapshot for every item a staff member is currently
     * considering, shaped exactly like StoreDocumentRequestRequest's
     * 'documents' / 'certificates' arrays. Read-only — this is the
     * GET /free-requests/eligibility-style check shown BEFORE filing;
     * it is intentionally re-run again, under a lock, inside
     * fileFreeRequest() itself, since staff may spend real time on the
     * verification step between seeing this and actually confirming.
     *
     * @param array<int,array{document_type_id:int}> $documents
     * @param array<int,array{certificate_type_id:int}> $certificates
     * @return \App\DTOs\FreeRequest\FreeRequestEligibilityResult[]
     */
    public function checkEligibility(SystemUser $targetUser, array $documents, array $certificates): array
    {
        return $this->eligibilityService->checkMany($targetUser, $documents, $certificates);
    }

    /**
     * File a free document/certificate request on behalf of $targetUser,
     * acted on by $actor (the Registrar Admin at the counter).
     *
     * $validated: ['request_purpose_id' => int, 'documents' => [...],
     * 'certificates' => [...]] — same shape StoreDocumentRequestRequest
     * already validates for a normal self-service filing; Phase 3's
     * StoreFreeDocumentRequest FormRequest is expected to validate the
     * same shape before this is ever called.
     *
     * $options:
     *   - 'override' (bool): staff have determined via other means that
     *     an ineligible item should be filed anyway. Requires the actor
     *     hold the 'free_requests','Override' capability AND a non-empty
     *     'override_reason'.
     *   - 'override_reason' (?string)
     *   - 'verification' (array{credentials_verified?:bool,
     *     records_checked?:bool}): required, and both flags must be
     *     true, whenever any item in this filing requires graduate
     *     verification (see FreeRequestEligibilityService). Requires
     *     the actor hold the 'free_requests','Verify' capability.
     *
     * @throws FreeRequestIneligibleException if any item is ineligible
     *         and no valid override was supplied.
     * @throws \Illuminate\Http\Exceptions\HttpResponseException (via
     *         abort()) for authorization/shape failures that don't need
     *         the full structured eligibility payload — invalid target
     *         role, empty item list, missing override reason, missing
     *         verification confirmation, or a missing capability.
     */
    public function fileFreeRequest(
        SystemUser $actor,
        SystemUser $targetUser,
        array $validated,
        array $options = [],
    ): FreeRequestFilingResult {
        $documents    = $validated['documents'] ?? [];
        $certificates = $validated['certificates'] ?? [];

        if (empty($documents) && empty($certificates)) {
            abort(422, 'At least one document or certificate must be requested.');
        }

        if (!in_array((int) $targetUser->role_id, [SystemUser::ROLE_STUDENT, SystemUser::ROLE_ALUMNI], true)) {
            abort(422, 'A free request can only be filed on behalf of a student or alumni account.');
        }

        $override       = (bool) ($options['override'] ?? false);
        $overrideReason = $options['override_reason'] ?? null;
        $verification   = $options['verification'] ?? [];

        // Coarse pre-check before the transaction even opens, so an
        // actor who lacks the capability fails fast without taking a
        // row lock for nothing. Re-checked again below, inside the
        // transaction, immediately before it's actually relied upon —
        // the same "coarse gate up front, fine gate at the point of the
        // actual write" defense-in-depth pattern
        // DocumentRequestService::authorizeStatusChange() already uses.
        if ($override) {
            $this->assertCapability($actor, 'Override');

            if (trim((string) $overrideReason) === '') {
                abort(422, 'An override reason is required to file an ineligible free request.');
            }
        }

        return DB::transaction(function () use (
            $actor, $targetUser, $documents, $certificates, $validated,
            $override, $overrideReason, $verification,
        ) {
            // See this class's docblock — locking the target user's own
            // row (guaranteed to exist) rather than any existing
            // admin_filed_free document_request row (which may not
            // exist yet) is what makes this safe even for a graduate's
            // very first free request.
            $lockedTarget = SystemUser::where('user_id', $targetUser->user_id)
                ->lockForUpdate()
                ->firstOrFail();

            $eligibilityResults = $this->eligibilityService->checkMany($lockedTarget, $documents, $certificates);
            $ineligible = array_values(array_filter($eligibilityResults, fn ($r) => !$r->eligible));

            $overriddenLabels = [];

            if (!empty($ineligible)) {
                if (!$override) {
                    throw new FreeRequestIneligibleException($eligibilityResults);
                }

                $this->assertCapability($actor, 'Override');

                $overriddenLabels = array_values(array_map(fn ($r) => $r->typeLabel, $ineligible));
            }

            $requiresGraduateVerification = $this->eligibilityService->anyRequiresGraduateVerification($eligibilityResults);

            if ($requiresGraduateVerification) {
                $this->assertCapability($actor, 'Verify');

                if (empty($verification['credentials_verified']) || empty($verification['records_checked'])) {
                    abort(422, 'Graduate credential and records verification must both be confirmed before filing a Certificate of Graduation / TOR free request.');
                }
            }

            $documentRequest = $this->documentRequestService->createRequest(
                $lockedTarget,
                [
                    'request_purpose_id' => $validated['request_purpose_id'],
                    'documents'          => $documents,
                    'certificates'       => $certificates,
                ],
                RequestChannelEnum::AdminFiledFree,
            );

            $graduateVerification = null;

            if ($requiresGraduateVerification) {
                // Both checks performed by the same acting admin today
                // — see GraduateVerification's docblock for why the two
                // columns stay separate regardless.
                $graduateVerification = GraduateVerification::create([
                    'document_request_id'     => $documentRequest->request_id,
                    'credentials_verified_by' => $actor->user_id,
                    'credentials_verified_at' => now(),
                    'records_checked_by'      => $actor->user_id,
                    'records_checked_at'      => now(),
                ]);
            }

            return new FreeRequestFilingResult(
                documentRequest: $documentRequest,
                eligibilitySnapshots: $eligibilityResults,
                wasOverridden: !empty($overriddenLabels),
                overrideReason: !empty($overriddenLabels) ? $overrideReason : null,
                overriddenTypeLabels: $overriddenLabels,
                graduateVerificationPerformed: $requiresGraduateVerification,
                graduateVerification: $graduateVerification,
            );
        });
    }

    /**
     * Fine-grained capability gate against the Phase 1 policy-config
     * role mapping (Policy::MODULE_ACTIONS['free_requests']) — the
     * policy-configurable verifier/override authorization called for in
     * the original spec, built on this codebase's existing
     * SystemUser::hasModuleAccess() rather than a new table (see
     * Policy.php's docblock). Reassigning who may verify/override is a
     * Policy Management change, never a deployment.
     */
    private function assertCapability(SystemUser $actor, string $action): void
    {
        if (!$actor->hasModuleAccess('free_requests', $action)) {
            abort(403, "Your account's assigned policy does not grant the '{$action}' action on the free_requests module.");
        }
    }
}