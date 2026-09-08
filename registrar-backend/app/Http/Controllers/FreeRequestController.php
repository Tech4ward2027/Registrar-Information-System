<?php

namespace App\Http\Controllers;

use App\Exceptions\FreeRequestIneligibleException;
use App\Http\Requests\FreeRequest\CheckFreeRequestEligibilityRequest;
use App\Http\Requests\FreeRequest\StoreFreeDocumentRequestRequest;
use App\Http\Resources\FreeRequestAccountResource;
use App\Models\AuditLog;
use App\Models\SystemUser;
use App\Services\AuditLogger;
use App\Services\FreeRequestLogger;
use App\Services\FreeRequestService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * FESPEC-0008 — Free Document/Certificate Request.
 *
 * Admin-facing HTTP layer for the Free Request page. Thin by design —
 * validate input (via the FreeRequest FormRequests), resolve the target
 * account, delegate to FreeRequestService, audit-log the outcome, return
 * JSON. All eligibility rules, the row-locked filing transaction, and
 * the policy-configured Verify/Override capability checks live in
 * FreeRequestService / FreeRequestEligibilityService — see those
 * classes' docblocks.
 *
 * Every route this controller serves sits behind 'role:3,4' plus a
 * 'module:free_requests,<Action>' gate in routes/api.php — the same
 * coarse-gate-at-the-route, fine-gate-in-the-service split
 * EnsureModuleAccess's own docblock documents for dashboard/Process
 * vs. Complete. The route-level tag only confirms the acting admin can
 * reach this endpoint AT ALL; FreeRequestService::assertCapability()
 * still separately confirms Verify/Override at the moment those specific
 * actions are actually exercised, since a single POST /free-requests
 * call can implicitly require either depending on what's being filed.
 *
 * No Laravel Policy class backs this controller (no FreeRequestPolicy).
 * Unlike DocumentRequestPolicy/AccessRequestPolicy, there is no natural
 * Eloquent model to key a policy off — a free request IS a
 * DocumentRequest row with channel = admin_filed_free, not a distinct
 * model — and this codebase's only precedent for a similarly
 * model-less, sensitive admin action (CashierOrOverrideController) also
 * has no policy class, gating purely through route middleware instead.
 * Matching that existing convention here rather than introducing the
 * only Policy class in the codebase with no corresponding model.
 */
class FreeRequestController extends Controller
{
    public function __construct(
        private FreeRequestService $freeRequestService,
        private AuditLogger        $auditLogger,
    ) {}

    /**
     * GET /free-requests/search-accounts?q=...&student_number=...&program=...
     *
     * Typeahead lookup so staff can find the student/alumni account
     * they're filing on behalf of. Free Documents/Certificates Request
     * Policy §3.3: "Full Name (required), Student Number (optional),
     * Program (optional)" — `q` carries the name search (kept as the
     * existing param name for frontend backward-compatibility);
     * `student_number`/`program` are optional disambiguation filters on
     * top of it. Still a simple enough shape to inline-validate rather
     * than add a dedicated FormRequest class, matching
     * CashierOrOverrideController::searchUsers()'s convention.
     */
    public function searchAccounts(Request $request)
    {
        $validated = $request->validate([
            'q'              => 'required|string|min:2|max:100',
            'student_number' => 'nullable|string|max:50',
            'program'        => 'nullable|string|max:255',
        ]);

        $accounts = $this->freeRequestService->searchAccounts(
            $validated['q'],
            $validated['student_number'] ?? null,
            $validated['program'] ?? null,
        );

        /** @var SystemUser $actor */
        $actor = Auth::user();

        $this->auditLogger->log($request, $actor, AuditLog::ACTION_FREE_REQUEST_ACCOUNT_SEARCHED, [
            'query'         => $validated['q'],
            'student_number'=> $validated['student_number'] ?? null,
            'program'       => $validated['program'] ?? null,
            'result_count'  => $accounts->count(),
        ]);

        // Phase 8 — structured, ops-facing log (see FreeRequestLogger's
        // docblock for why this exists alongside, not instead of, the
        // audit_logs row just above).
        FreeRequestLogger::log(FreeRequestLogger::ACTION_ACCOUNT_SEARCHED, $request, $actor, [
            'result_count' => $accounts->count(),
        ]);

        return FreeRequestAccountResource::collection($accounts);
    }

    /**
     * POST /free-requests/eligibility
     *
     * Read-only pre-check — shows staff the eligibility indicator for
     * every item they're considering BEFORE they commit to filing. No
     * writes, no audit log entry (nothing happened yet worth recording;
     * see FreeRequestService::fileFreeRequest()'s own docblock on why
     * this is intentionally re-run again, under a lock, at actual filing
     * time — this response can go stale between here and then).
     */
    public function eligibility(CheckFreeRequestEligibilityRequest $request)
    {
        $validated = $request->validated();

        $targetUser = SystemUser::findOrFail($validated['target_user_id']);

        $results = $this->freeRequestService->checkEligibility(
            $targetUser,
            $validated['documents'] ?? [],
            $validated['certificates'] ?? [],
        );

        // Phase 8 — no audit_logs row is written here (see this method's
        // own docblock: nothing has happened yet worth a compliance
        // record), but it's still useful ops signal — e.g. spotting a
        // spike in ineligible pre-checks for one document type.
        FreeRequestLogger::log(FreeRequestLogger::ACTION_ELIGIBILITY_CHECKED, $request, Auth::user(), [
            'target_user_id' => $targetUser->user_id,
            'results'        => array_map(fn ($r) => [
                'kind'     => $r->kind->value,
                'type_id'  => $r->typeId,
                'eligible' => $r->eligible,
            ], $results),
        ]);

        return response()->json([
            'target_user_id' => $targetUser->user_id,
            'results'         => array_map(fn ($r) => $r->toArray(), $results),
        ]);
    }

    /**
     * POST /free-requests
     *
     * Files a free document/certificate request on behalf of
     * target_user_id. See StoreFreeDocumentRequestRequest for the
     * validated shape and FreeRequestService::fileFreeRequest() for the
     * full eligibility/override/verification/locking logic this
     * delegates to.
     *
     * Every meaningful outcome is audit-logged, using the
     * FreeRequestFilingResult DTO returned by the service so nothing
     * has to be re-queried to log accurately:
     *   - ACTION_FREE_REQUEST_FILED — always, on success.
     *   - ACTION_FREE_REQUEST_GRADUATE_VERIFIED — only when this filing
     *     included a COG/TOR item (graduateVerificationPerformed).
     *   - ACTION_FREE_REQUEST_ELIGIBILITY_OVERRIDDEN — only when at
     *     least one item was ineligible and was filed anyway via
     *     override (wasOverridden).
     */
    public function store(StoreFreeDocumentRequestRequest $request)
    {
        $validated = $request->validated();

        $targetUser = SystemUser::findOrFail($validated['target_user_id']);

        /** @var SystemUser $actor */
        $actor = Auth::user();

        try {
            $result = $this->freeRequestService->fileFreeRequest(
                actor: $actor,
                targetUser: $targetUser,
                validated: [
                    'request_purpose_id' => $validated['request_purpose_id'],
                    'documents'          => $validated['documents'] ?? [],
                    'certificates'       => $validated['certificates'] ?? [],
                ],
                options: [
                    'override'        => (bool) ($validated['override'] ?? false),
                    'override_reason' => $validated['override_reason'] ?? null,
                    'verification'    => $validated['verification'] ?? [],
                ],
            );
        } catch (FreeRequestIneligibleException $e) {
            // Phase 8 — the one outcome this controller previously never
            // logged anywhere at all (not audit_logs, not this channel).
            // Worth capturing here specifically: a spike in rejections
            // for one document type, or from one actor, is exactly the
            // kind of thing ops/oversight would want to notice without
            // having to reconstruct it from what's absent in audit_logs.
            FreeRequestLogger::log(FreeRequestLogger::ACTION_REJECTED, $request, $actor, [
                'target_user_id' => $targetUser->user_id,
                'errors'         => array_map(fn ($r) => [
                    'kind'        => $r->kind->value,
                    'type_id'     => $r->typeId,
                    'reason_code' => $r->reasonCode,
                ], $e->results),
            ]);

            return response()->json([
                'message' => $e->getMessage(),
                'errors'  => array_map(fn ($r) => $r->toArray(), $e->results),
            ], 422);
        }

        $documentRequest = $result->documentRequest;

        $this->auditLogger->log($request, $actor, AuditLog::ACTION_FREE_REQUEST_FILED, [
            'target_user_id' => $targetUser->user_id,
            'request_id'     => $documentRequest->request_id,
            'items'          => array_map(fn ($r) => [
                'kind'     => $r->kind->value,
                'type_id'  => $r->typeId,
                'label'    => $r->typeLabel,
                'eligible' => $r->eligible,
            ], $result->eligibilitySnapshots),
        ]);

        // Phase 8 — same event, structured-log channel. See
        // FreeRequestLogger's docblock: written alongside, not instead
        // of, every AuditLogger call above/below.
        FreeRequestLogger::log(FreeRequestLogger::ACTION_FILED, $request, $actor, [
            'target_user_id' => $targetUser->user_id,
            'request_id'     => $documentRequest->request_id,
            'item_count'     => count($result->eligibilitySnapshots),
        ]);

        if ($result->graduateVerificationPerformed) {
            $this->auditLogger->log($request, $actor, AuditLog::ACTION_FREE_REQUEST_GRADUATE_VERIFIED, [
                'target_user_id'           => $targetUser->user_id,
                'request_id'               => $documentRequest->request_id,
                'graduate_verification_id' => $result->graduateVerification?->graduate_verification_id,
            ]);

            FreeRequestLogger::log(FreeRequestLogger::ACTION_GRADUATE_VERIFIED, $request, $actor, [
                'target_user_id' => $targetUser->user_id,
                'request_id'     => $documentRequest->request_id,
            ]);
        }

        if ($result->wasOverridden) {
            $this->auditLogger->log($request, $actor, AuditLog::ACTION_FREE_REQUEST_ELIGIBILITY_OVERRIDDEN, [
                'target_user_id'  => $targetUser->user_id,
                'request_id'      => $documentRequest->request_id,
                'overridden_items' => $result->overriddenTypeLabels,
                'reason'          => $result->overrideReason,
            ]);

            // Reason text is deliberately excluded here — same "no raw
            // free-text beyond IDs/labels" rule this class's docblock
            // states, and the full reason is already durably captured
            // in the audit_logs row immediately above.
            FreeRequestLogger::log(FreeRequestLogger::ACTION_OVERRIDDEN, $request, $actor, [
                'target_user_id'    => $targetUser->user_id,
                'request_id'        => $documentRequest->request_id,
                'overridden_items'  => $result->overriddenTypeLabels,
            ]);
        }

        return response()->json([
            'document_request' => $documentRequest->load([
                'user',
                'studentProfile',
                'alumniProfile',
                'status',
                'requestPurpose',
                'documents.documentType',
                'certificates.certificationType',
                'graduateVerification',
            ]),
            'was_overridden'                  => $result->wasOverridden,
            'graduate_verification_performed' => $result->graduateVerificationPerformed,
        ], 201);
    }
}