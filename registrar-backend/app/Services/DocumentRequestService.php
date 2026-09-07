<?php

namespace App\Services;

use App\Enums\ClosureReasonEnum;
use App\Enums\RequestChannelEnum;
use App\Enums\RequestStatusEnum;
use App\Enums\WithdrawalReasonEnum;
use App\Models\CertificationType;
use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Models\RequestHistory;
use App\Models\RequestRemark;
use App\Models\SystemUser;
use App\Contracts\DocumentRequestServiceInterface;
use App\Contracts\NotificationServiceInterface;
use App\Services\Concerns\FlushesAnalyticsCache;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Encapsulates all business logic for document requests.
 *
 * Controllers become thin HTTP adapters — they validate input,
 * call this service, and return JSON.
 */
class DocumentRequestService implements DocumentRequestServiceInterface
{
    use FlushesAnalyticsCache;

    public function __construct(
        private NotificationServiceInterface $notificationService,
        private BusinessCalendarService $businessCalendarService,
        private RequestReleaseGroupService $releaseGroupService,
    ) {}

    // -------------------------------------------------------------------------
    // Create
    // -------------------------------------------------------------------------

    /**
     * Create a new document request for a student or alumni user.
     *
     * @throws \Illuminate\Http\Exceptions\HttpResponseException
     */
    public function createRequest(SystemUser $user, array $validated, RequestChannelEnum $channel = RequestChannelEnum::SelfService): DocumentRequest
    {
        $initialStatus = $this->requestRequiresSourceSubmission($validated)
            ? RequestStatusEnum::AwaitingSubmission
            : RequestStatusEnum::Processing;

        $requestData = $this->buildRequestData($user, $validated, $initialStatus, $channel);

        // BUG FIX (createRequest was never transactional): the parent
        // document_request row, every request_document/request_certificate
        // child row, and Phase 3's release-group assignment used to be
        // separate, unwrapped writes. If item creation failed partway
        // through (bad type id slipping past validation, a DB hiccup,
        // a deadlock on a concurrent request), the result was a
        // document_request row with SOME or NONE of its line items —
        // silently orphaned, no error surfaced to the student, and
        // nothing to roll it back. Wrapping the whole write path in one
        // transaction makes request creation all-or-nothing: either the
        // request and every one of its items (and its release groups)
        // exist together, or none of them do and the caller gets a
        // clean exception instead of a half-created request.
        //
        // Notifications deliberately stay OUTSIDE this transaction (see
        // below) — they're an external side effect, not a DB write, and
        // should only fire once the request is durably committed.
        $documentRequest = DB::transaction(function () use ($user, $validated, $requestData) {
            $documentRequest = DocumentRequest::create($requestData);

            // BUG FIX (per-item status was never set at creation): every
            // request_document/request_certificate row created below used to
            // be left with status_id = NULL, invisible to
            // RequestItemStatusService::recomputeAggregateStatus()'s
            // whereNotNull('status_id') filter — meaning a brand-new
            // request's items didn't participate in their own aggregate
            // until a staff member happened to touch one manually.
            //
            // Fixed by resolving each item's OWN initial status here — same
            // AwaitingSubmission-vs-Processing decision
            // requestRequiresSourceSubmission() already makes for the whole
            // request, just applied per item instead of once. A single
            // whereIn() per type table keeps this to two extra queries
            // regardless of how many line items are submitted.
            $documentTypeIds    = array_column($validated['documents'] ?? [], 'document_type_id');
            $certificateTypeIds = array_column($validated['certificates'] ?? [], 'certificate_type_id');

            $documentTypeMeta = $documentTypeIds
                ? DocumentType::whereIn('document_type_id', $documentTypeIds)->get()->keyBy('document_type_id')
                : collect();

            $certificateTypeMeta = $certificateTypeIds
                ? CertificationType::whereIn('certificate_type_id', $certificateTypeIds)->get()->keyBy('certificate_type_id')
                : collect();

            foreach ($validated['documents'] ?? [] as $doc) {
                $requiresSubmission = (bool) ($documentTypeMeta[$doc['document_type_id']]->requires_source_submission ?? false);

                $documentRequest->documents()->create([
                    'document_type_id' => $doc['document_type_id'],
                    'number_of_copies' => $doc['number_of_copies'],
                    'status_id'        => $requiresSubmission
                        ? RequestStatusEnum::AwaitingSubmission->value
                        : RequestStatusEnum::Processing->value,
                ]);
            }

            foreach ($validated['certificates'] ?? [] as $cert) {
                $requiresSubmission = (bool) ($certificateTypeMeta[$cert['certificate_type_id']]->requires_source_submission ?? false);

                $documentRequest->certificates()->create([
                    'certificate_type_id' => $cert['certificate_type_id'],
                    'number_of_copies'    => $cert['number_of_copies'] ?? 1,
                    'status_id'           => $requiresSubmission
                        ? RequestStatusEnum::AwaitingSubmission->value
                        : RequestStatusEnum::Processing->value,
                ]);
            }

            // Phase 3 — group into per-track claim tickets IF the items
            // actually span more than one fulfillment_track. Must run after
            // every item above has its status_id set (see that method's
            // docblock) and before the notification block below, since
            // nothing downstream reads release-group data yet.
            $this->releaseGroupService->assignReleaseGroups($documentRequest);

            return $documentRequest;
        });

        // ── Build per-item requirements list ─────────────────────────────────
        // Load relations fresh so we always have the latest document/cert data
        // even if the relations were already partially loaded above.
        // Each entry exposes: item name, requirements text, copy count, and the
        // office's stated processing period — everything the student needs to
        // prepare, visible directly inside their inbox notification.
        $documentRequest->load([
            'documents.documentType',
            'certificates.certificationType',
        ]);

        $requirements = [];

        foreach ($documentRequest->documents as $rd) {
            $type = $rd->documentType;
            if ($type) {
                $requirements[] = [
                    'item'         => $type->document_name,
                    'requirements' => $type->document_requirements,
                    'copies'       => $rd->number_of_copies,
                    'process_days' => $type->document_process_period,
                ];
            }
        }

        foreach ($documentRequest->certificates as $rc) {
            $type = $rc->certificationType;
            if ($type) {
                $requirements[] = [
                    'item'         => $type->certificate_name,
                    'requirements' => $type->certificate_requirements,
                    'copies'       => $rc->number_of_copies,
                    'process_days' => $type->certificate_process_period,
                ];
            }
        }

        // Notify the requester and all admins (never superadmins)
        //
        // uuid/claim_code go out here too, not just on the ReadyToClaim
        // transition below — the claim ticket is meant to be available to
        // the student from the moment they submit (same as the pop-up
        // shown immediately after RequestForm.jsx/AlumniRequest.jsx submit),
        // per QR Code Claiming Policy v1.0 §3.2's "pop-up, dashboard, inbox"
        // access points. Staff can only ever *act* on it once the request
        // is actually ReadyToClaim (§3.4, enforced server-side in the claim
        // endpoint) — that's a scan-time restriction, not a visibility one,
        // so there's no reason to withhold the ticket from the inbox until
        // later.
        $this->notificationService->send(
            recipient:    $user,
            triggerEvent: 'request_submitted',
            data:         [
                'request_id'   => $documentRequest->request_id,
                'requirements' => $requirements,   // checklist shown in inbox
                'uuid'         => $documentRequest->uuid,
                'claim_code'   => $documentRequest->claim_code,
            ],
            requestId:    $documentRequest->request_id,
        );

        $this->notificationService->sendToAdmins(
            triggerEvent: 'admin_new_request',
            data:         ['request_id' => $documentRequest->request_id],
            requestId:    $documentRequest->request_id,
        );

        // A request that started in AwaitingSubmission doesn't go through
        // updateRequest()/notifyOwnerOfStatusChange() to reach that status
        // — it's assigned at creation, above — so it needs its own
        // notification here. The requirements checklist already sent
        // above tells the student WHAT to bring (each CTC item's
        // document_requirements text already says "source document
        // required"); this one specifically flags that nothing will be
        // processed until they do, since request_submitted alone reads as
        // "your request is being worked on."
        if ($initialStatus === RequestStatusEnum::AwaitingSubmission) {
            $this->notificationService->send(
                recipient:    $user,
                triggerEvent: $initialStatus->notificationTrigger(),
                data:         [
                    'request_id'   => $documentRequest->request_id,
                    'requirements' => $requirements,
                ],
                requestId:    $documentRequest->request_id,
            );
        }

        return $documentRequest;
    }

    // -------------------------------------------------------------------------
    // Update
    // -------------------------------------------------------------------------

    /**
     * Update a document request (status, OR number, receipt date).
     * Writes history on status change and notifies the owner.
     * Notifies admins on OR number change (payment verification).
     *
     * Runs inside a DB transaction with a row-level lock so that
     * concurrent admin updates cannot race on the same request.
     */
    public function updateRequest(DocumentRequest $documentRequest, array $validated): DocumentRequest
    {
        // BUG FIX (RIS-PROCESS-BUGS #9 — "Omission of Completed Documents
        // in Staff Performance Analytics"): every analytics endpoint
        // (AnalyticsController) is cached for 10 minutes under the
        // "analytics" cache tag, so a document that just got marked
        // Completed (or moved through any other status) doesn't show up
        // in the Staff Performance / overview / volume charts until that
        // cache entry naturally expires — which reads as "the dashboard
        // omitted it" if you check within that window. $statusChanged is
        // set inside the transaction below and read after it commits, so
        // the flush below only fires once per actual status transition,
        // and only after that transition is durably committed (never for
        // a transaction that later rolls back).
        $statusChanged = false;

        $documentRequest = DB::transaction(function () use ($documentRequest, $validated, &$statusChanged) {
            // Re-fetch with a row-level lock so concurrent admin updates
            // cannot race: the second request will block here until the
            // first transaction commits, then re-read the committed state.
            $documentRequest = DocumentRequest::lockForUpdate()
                ->findOrFail($documentRequest->request_id);

            // Archive Rules: "Archived records cannot be processed, edited,
            // or have their status updated while archived." Restoring must
            // go through restoreRequest() instead — never through this
            // general-purpose update path.
            if ($documentRequest->is_archived) {
                abort(422, 'This request is archived and is read-only. Restore it first.');
            }

            $oldStatusId = $documentRequest->status_id;
            $oldOrNumber = $documentRequest->or_number;

            // Guard: transitioning to ReadyToClaim on a *certificate* request
        // requires that at least one certificate row already exists in
        // request_certificate (i.e. it has been generated/printed).
        //
        // Pure document requests (transcripts, enrollment certs, etc.) have
        // zero rows in request_certificate by design — they must NOT be blocked.
        // Only requests that were submitted WITH certificate items are checked.
        //
            // Flow:
            //   1. Does this request have any certificate items? (hasCertificateItems)
            //   2. If yes, has at least one been generated?     (certCount > 0)
            //   3. If both fail → 422.  Otherwise → allow.
            //
            // Checked on ANY transition INTO ReadyToClaim, not just directly
            // from Processing: since PendingSignature (Step 3) can also
            // reach ReadyToClaim, a certificate request routed through
            // signature-waiting must still have been generated before it's
            // considered claimable.
            if (
                isset($validated['status_id']) &&
                (int) $validated['status_id'] === RequestStatusEnum::ReadyToClaim->value
            ) {
                // Count rows in request_certificate that were submitted as part of
                // this request (created during store(), referencing certificate_type).
                // A non-zero count means the request includes certificate items.
                $submittedCertCount = $documentRequest->certificates()->count();

                // TEMPORARILY DISABLED (business decision, not a bug) —
                // this print-first guard is off across all three
                // enforcement points (whole-request here, per-item in
                // RequestItemStatusService::guardCertificateGenerated(),
                // per-group in RequestReleaseGroupService::
                // claimReleaseGroup()), matching the frontend, which
                // disabled its own copy first (see "TEMPORARILY DISABLED"
                // in StaffDashboard.jsx's handleBulkReadyClick()).
                // generated_at is still recorded normally by
                // markCertificatesGenerated() below — re-enabling later
                // just means restoring the abort() in all three places.
                if ($submittedCertCount > 0) {
                    // $generatedCount = $documentRequest->certificates()
                    //     ->whereNotNull('generated_at')
                    //     ->count();

                    // if ($generatedCount === 0) {
                    //     abort(422, 'Certificate must be generated before marking as Ready to Claim.');
                    // }
                }
            }

            // Enforce allowed status transitions (see RequestStatusEnum::allowedTransitions).
            $targetStatus = null;
            if (isset($validated['status_id'])) {
                $currentStatus = RequestStatusEnum::from((int) $oldStatusId);
                $targetStatus  = RequestStatusEnum::from((int) $validated['status_id']);
                if (!in_array($targetStatus, $currentStatus->allowedTransitions(), true)) {
                    abort(422, "Transition from {$currentStatus->name} to {$targetStatus->name} is not allowed.");
                }
            }

            // Work Item #1 — Granular Per-Action Permissions: the FINE
            // gate. PUT /document-requests/{id} is one endpoint that
            // handles every status transition, so the coarse route/
            // policy gate above (DocumentRequestPolicy::update()) can
            // only confirm the actor has SOME dashboard write action —
            // it cannot tell "Process" from "Complete" apart, because
            // that depends on the target status_id, which is only known
            // here, after the transition itself has been validated as
            // legal. This is the check that actually stops a Student
            // Staff account (dashboard: [View, Complete]) from setting
            // ReadyToClaim/PendingSignature/Forfeited or editing
            // or_number/receipt_date, even via a direct, manually
            // crafted API call that never touches the UI.
            $this->authorizeStatusChange($validated, $targetStatus);

            $documentRequest->update($validated);

            if (isset($validated['status_id']) && (int) $validated['status_id'] !== (int) $oldStatusId) {
                $this->recordStatusHistory($documentRequest, $oldStatusId);
                $this->notifyOwnerOfStatusChange($documentRequest);
                $statusChanged = true;
            }

            if (
                isset($validated['or_number']) &&
                $documentRequest->or_number !== $oldOrNumber &&
                !empty($documentRequest->or_number)
            ) {
                $this->notificationService->sendToAdmins(
                    triggerEvent: 'admin_payment_verification',
                    data:         ['request_id' => $documentRequest->request_id],
                    requestId:    $documentRequest->request_id,
                );
            }

            return $documentRequest;
        }); // end DB::transaction

        // See BUG FIX comment above updateRequest()'s signature. Runs
        // AFTER the transaction has committed — never inside it, so a
        // Redis hiccup can't roll back an otherwise-successful status
        // change, and so a transaction that ends up rolling back never
        // triggers a needless flush.
        if ($statusChanged) {
            $this->flushAnalyticsCache();
        }

        return $documentRequest;
    }

    // flushAnalyticsCache() now lives in Concerns\FlushesAnalyticsCache —
    // shared with AccessRequestService and ShredExpiredRequests, which
    // had the same "writes a status the dashboard counts, never
    // invalidates the cache" gap (QA bugs #4/#9/#14). See that trait's
    // docblock for the full reasoning.

    // -------------------------------------------------------------------------
    // Mark certificates generated (real print/generation signal)
    // -------------------------------------------------------------------------

    /**
     * Records that this request's certificate(s) have actually been
     * generated/printed — the real signal the "must be generated before
     * ReadyToClaim" guards in updateRequest() and
     * RequestItemStatusService::guardCertificateGenerated() now check,
     * replacing the old certificate_type_id no-op and the client-only
     * printedCertificateIds localStorage flag that never reached the
     * server (see migration
     * 2026_08_29_000010_add_generated_at_to_request_certificate).
     *
     * Called from GenerateCertificate.jsx's print action. GenerateCertificate
     * now identifies which specific request_certificate row it's printing
     * for whenever it can resolve one (see CertificateModal.jsx's
     * name -> request_certificate_id map), so $requestCertificateId marks
     * just that row. Callers that can't resolve a specific item (or older
     * callers not yet updated) can omit it, which falls back to the
     * original behaviour of marking every not-yet-generated certificate
     * row on the request — the same granularity the whole-request guard
     * already checks at ("at least one certificate item generated"), kept
     * for backward compatibility rather than as the preferred path.
     *
     * Deliberately idempotent and side-effect-free beyond the column
     * write: re-printing an already-generated certificate just leaves
     * its original generated_at timestamp alone (whereNull scopes the
     * update), so this is safe to call on every print click without
     * needing the caller to track whether it already fired.
     */
    public function markCertificatesGenerated(DocumentRequest $documentRequest, ?int $requestCertificateId = null): void
    {
        DB::transaction(function () use ($documentRequest, $requestCertificateId) {
            $documentRequest = DocumentRequest::lockForUpdate()
                ->findOrFail($documentRequest->request_id);

            if ($documentRequest->is_archived) {
                abort(422, 'This request is archived and is read-only. Restore it first.');
            }

            if ($requestCertificateId !== null) {
                // Scoped to one line item — 404 rather than a silent no-op
                // if the id doesn't actually belong to this request, so a
                // frontend bug (wrong id resolved) surfaces immediately
                // instead of quietly marking nothing. (Already-generated
                // items are found here too, just left untouched below by
                // the whereNull on the update — re-printing stays a no-op,
                // not an error.)
                $documentRequest->certificates()
                    ->where('request_certificate_id', $requestCertificateId)
                    ->firstOrFail();

                $documentRequest->certificates()
                    ->whereNull('generated_at')
                    ->where('request_certificate_id', $requestCertificateId)
                    ->update(['generated_at' => now()]);

                return;
            }

            $documentRequest->certificates()
                ->whereNull('generated_at')
                ->update(['generated_at' => now()]);
        });
    }

    // -------------------------------------------------------------------------
    // Claim (QR scan / manual claim_code)
    // -------------------------------------------------------------------------

    /**
     * @param array{uuid?: string, claim_code?: string} $credential exactly
     *        one key set — enforced by ClaimDocumentRequestRequest before
     *        this is ever called.
     */
    public function claimRequest(array $credential): DocumentRequest
    {
        // Phase 3 — a scanned uuid/claim_code might belong to a
        // request_release_group ticket rather than the request itself.
        // Checked first: group tickets and request tickets are drawn
        // from the same alphabet/uuid format, so there's no way to tell
        // them apart except by which table actually has the row.
        $releaseGroup = $this->releaseGroupService->findByCredential($credential);
        if ($releaseGroup) {
            return $this->releaseGroupService->claimReleaseGroup($releaseGroup);
        }

        // Plain lookup, no lock here on purpose: updateRequest() below
        // re-fetches by request_id under lockForUpdate() and re-validates
        // everything (archived, transition-allowed) against the committed
        // state at that point. Locking twice on two different query shapes
        // (by uuid/claim_code here, by request_id there) would only add
        // deadlock surface for no extra safety.
        //
        // The default ExcludeArchivedScope applies here same as any other
        // query, so an archived request's code/uuid simply won't match —
        // consistent with archived requests being read-only everywhere else.
        $documentRequest = DocumentRequest::query()
            ->when(isset($credential['uuid']), fn ($q) => $q->where('uuid', $credential['uuid']))
            ->when(isset($credential['claim_code']), fn ($q) => $q->where('claim_code', $credential['claim_code']))
            ->first();

        if (!$documentRequest) {
            // Deliberately generic — doesn't reveal whether a uuid or
            // claim_code was the invalid part of the payload.
            abort(404, 'No matching request found for that code.');
        }

        // Phase 3 guard: once a request has been split into release
        // groups, the request-level ticket stops being a meaningful
        // "claim everything" action UNTIL every group has already been
        // individually claimed (at which point document_request.
        // status_id is already Completed via the aggregate, and the
        // transition guard below rejects it naturally with "already
        // completed" — no special case needed for that path). Blocking
        // it explicitly here, rather than letting it silently complete
        // only whichever groups happen to be ready, avoids an ambiguous
        // partial claim on a single ticket.
        $groupState = $this->releaseGroupService->groupCompletionState($documentRequest);
        if ($groupState['hasGroups'] && !$groupState['allCompleted']) {
            abort(422, 'This request has separate release tickets for its items — scan the specific ticket for what is being claimed.');
        }

        // Reuses the exact same guarded path a manual admin status change
        // goes through: row lock, "archived is read-only", and
        // allowedTransitions() — which only permits ReadyToClaim →
        // Completed. That means a request that's still Processing, or
        // one that was already Completed by an earlier scan, is rejected
        // here with the same clear 422 updateRequest() already produces,
        // with no extra logic needed to distinguish those cases.
        return $this->updateRequest($documentRequest, [
            'status_id' => RequestStatusEnum::Completed->value,
        ]);
    }

    // -------------------------------------------------------------------------
    // Archive / Restore
    // -------------------------------------------------------------------------
    //
    // Deliberately separate from updateRequest(): archiving never touches
    // status_id, so a restored record comes back with its original status
    // untouched (Archive Rules policy). Any authorized admin may archive a
    // request regardless of its current status_id — there is no eligibility
    // check here, unlike document/certificate type archiving.

    public function archiveRequest(DocumentRequest $documentRequest, SystemUser $actor): DocumentRequest
    {
        return DB::transaction(function () use ($documentRequest, $actor) {
            $documentRequest = DocumentRequest::withArchived()
                ->lockForUpdate()
                ->findOrFail($documentRequest->request_id);

            if (!$documentRequest->is_archived) {
                $documentRequest->update([
                    'is_archived' => true,
                    'archived_on' => now(),
                    'archived_by' => $actor->user_id,
                ]);
            }

            return $documentRequest;
        });
    }

    public function restoreRequest(DocumentRequest $documentRequest, SystemUser $actor): DocumentRequest
    {
        return DB::transaction(function () use ($documentRequest, $actor) {
            $documentRequest = DocumentRequest::withArchived()
                ->lockForUpdate()
                ->findOrFail($documentRequest->request_id);

            // status_id is intentionally left untouched — restoring returns
            // the record to Active Requests with its original status.
            if ($documentRequest->is_archived) {
                $documentRequest->update([
                    'is_archived' => false,
                    'archived_on' => null,
                    'archived_by' => null,
                    'restored_on' => now(),
                    'restored_by' => $actor->user_id,
                ]);
            }

            return $documentRequest;
        });
    }

    public function archiveRequests(array $requestIds, SystemUser $actor): array
    {
        return DB::transaction(function () use ($requestIds, $actor) {
            $eligible = DocumentRequest::withArchived()
                ->whereIn('request_id', $requestIds)
                ->where('is_archived', false)
                ->lockForUpdate()
                ->pluck('request_id')
                ->all();

            if (!empty($eligible)) {
                DocumentRequest::withArchived()
                    ->whereIn('request_id', $eligible)
                    ->update([
                        'is_archived' => true,
                        'archived_on' => now(),
                        'archived_by' => $actor->user_id,
                    ]);
            }

            return [
                'archived' => $eligible,
                'skipped'  => array_values(array_diff($requestIds, $eligible)),
            ];
        });
    }

    public function restoreRequests(array $requestIds, SystemUser $actor): array
    {
        return DB::transaction(function () use ($requestIds, $actor) {
            $eligible = DocumentRequest::withArchived()
                ->whereIn('request_id', $requestIds)
                ->where('is_archived', true)
                ->lockForUpdate()
                ->pluck('request_id')
                ->all();

            if (!empty($eligible)) {
                DocumentRequest::withArchived()
                    ->whereIn('request_id', $eligible)
                    ->update([
                        'is_archived' => false,
                        'archived_on' => null,
                        'archived_by' => null,
                        // Row-level attribution for the restore itself —
                        // mirrors archived_on/archived_by above. The bulk
                        // action is also written to audit_logs by the
                        // controller, but that requires a join to answer
                        // "who restored this request"; these columns let
                        // that be queried directly off the row.
                        'restored_on' => now(),
                        'restored_by' => $actor->user_id,
                    ]);
            }

            return [
                'restored' => $eligible,
                'skipped'  => array_values(array_diff($requestIds, $eligible)),
            ];
        });
    }

    // -------------------------------------------------------------------------
    // Withdraw
    // -------------------------------------------------------------------------
    //
    // Deficiency Notice & Withdrawn Status — Phase 1. Deliberately a
    // separate method from updateRequest() (same reasoning as
    // archiveRequest()/restoreRequest() being separate): withdrawal
    // carries its own required reason field and its own dedicated route/
    // permission tier, rather than being one more thing PUT
    // /document-requests/{id} has to branch on.

    /**
     * @param array{
     *     withdrawal_reason: string,
     *     withdrawal_detail?: string|null,
     *     superseded_by_request_id?: int|null,
     * } $data
     */
    public function withdraw(DocumentRequest $documentRequest, array $data): DocumentRequest
    {
        // Same BUG FIX rationale as updateRequest() above (RIS-PROCESS-BUGS
        // #9): analytics are cached for 10 minutes under the "analytics"
        // tag, so a request that just left the active queue via Withdrawn
        // needs that cache invalidated once this durably commits.
        $statusChanged = false;

        // Deficiency Notice & Withdrawn Status — Phase 5 (Cross-Feature
        // Hardening & Edge Cases: "Withdraw while a Deficiency Notice is
        // open"). Set inside the transaction below when an open notice
        // gets auto-voided as part of this withdrawal, so the caller
        // (DocumentRequestController::withdraw()) can write its own
        // audit_logs entry for the voided notice without a second query.
        $autoVoidedRemarkId = null;

        $documentRequest = DB::transaction(function () use ($documentRequest, $data, &$statusChanged, &$autoVoidedRemarkId) {
            // Re-fetch with a row-level lock — same concurrency guarantee
            // as updateRequest(): a second concurrent write blocks here
            // until the first transaction commits, then re-reads the
            // committed state rather than racing against it.
            $documentRequest = DocumentRequest::lockForUpdate()
                ->findOrFail($documentRequest->request_id);

            // Archive Rules: same "archived records are read-only" guard
            // updateRequest() and markCertificatesGenerated() already
            // enforce. Restoring must happen first via restoreRequest().
            if ($documentRequest->is_archived) {
                abort(422, 'This request is archived and is read-only. Restore it first.');
            }

            $oldStatusId   = $documentRequest->status_id;
            $currentStatus = RequestStatusEnum::from((int) $oldStatusId);

            // Enforce the same allowedTransitions() map updateRequest()
            // uses — Withdrawn is only reachable from AwaitingSubmission,
            // Processing, or PendingSignature (see RequestStatusEnum's
            // docblock). This also naturally rejects withdrawing an
            // already-Withdrawn/Completed/Forfeited request, since none
            // of those terminal statuses list Withdrawn among their
            // allowed transitions.
            if (!in_array(RequestStatusEnum::Withdrawn, $currentStatus->allowedTransitions(), true)) {
                abort(422, "Transition from {$currentStatus->name} to Withdrawn is not allowed.");
            }

            // Deficiency Notice & Withdrawn Status — Phase 5 ("Withdraw
            // while a Deficiency Notice is open"). Decision taken per the
            // implementation plan's recommendation: AUTO-VOID rather than
            // block, so staff never need two trips (clear/void the notice,
            // THEN withdraw) to close out a case that's already decided.
            // The notice's void_reason cascades from this same
            // withdrawal_reason/withdrawal_detail, so anyone reviewing the
            // notice later sees exactly why it was closed — one clean
            // audit trail instead of two independently-maintained ones.
            //
            // Row-locked in the same parent-then-child order
            // DeficiencyNoticeService::issue() already establishes
            // (document_request locked above, then the remark) — no new
            // deadlock risk from doing this here instead of via
            // DeficiencyNoticeService::void().
            //
            // Deliberately NOT delegating to DeficiencyNoticeService::
            // void(): that method sends its notification immediately
            // after its OWN DB::transaction() closure returns. Called
            // from inside this method's transaction, that inner
            // "transaction" is really just a savepoint — the notification
            // would fire before this OUTER withdrawal transaction actually
            // commits, exactly the "notification for a change that might
            // still roll back" problem notifyOwnerOfWithdrawal()'s
            // docblock below already exists to avoid. The void itself is
            // therefore inlined here (a three-column update, not complex
            // enough to justify the cross-cutting risk), while any
            // notification about it is deferred to the post-commit block
            // below, alongside notifyOwnerOfWithdrawal().
            $openRemark = RequestRemark::where('request_id', $documentRequest->request_id)
                ->where('status', RequestRemark::STATUS_OPEN)
                ->lockForUpdate()
                ->first();

            if ($openRemark) {
                $openRemark->update([
                    'status'      => RequestRemark::STATUS_VOIDED,
                    'voided_by'   => Auth::id(),
                    'voided_at'   => now(),
                    'void_reason' => 'Automatically voided — parent request was withdrawn: '
                        . $this->resolveWithdrawalReasonText(
                            $data['withdrawal_reason'],
                            $data['withdrawal_detail'] ?? null,
                        ),
                ]);

                $autoVoidedRemarkId = $openRemark->remark_id;
            }

            if (!empty($data['superseded_by_request_id'])) {
                // withArchived() so an archived-but-real request can still
                // be referenced — the point of this pointer is historical
                // record-keeping, not "is that other request currently
                // active."
                $supersedingExists = DocumentRequest::withArchived()
                    ->where('request_id', $data['superseded_by_request_id'])
                    ->exists();

                if (!$supersedingExists) {
                    abort(422, 'superseded_by_request_id does not reference an existing request.');
                }

                if ((int) $data['superseded_by_request_id'] === (int) $documentRequest->request_id) {
                    abort(422, 'A request cannot supersede itself.');
                }
            }

            $documentRequest->update([
                'status_id'                => RequestStatusEnum::Withdrawn->value,
                'withdrawal_reason'        => $data['withdrawal_reason'],
                'withdrawal_detail'        => $data['withdrawal_detail'] ?? null,
                'superseded_by_request_id' => $data['superseded_by_request_id'] ?? null,
            ]);

            $this->recordStatusHistory($documentRequest, $oldStatusId);
            $statusChanged = true;

            return $documentRequest;
        }); // end DB::transaction

        // Deficiency Notice & Withdrawn Status — Phase 5. Transient —
        // never persisted (not in $fillable, never passed to update()) —
        // so DocumentRequestController::withdraw() can write its own
        // audit_logs entry for the voided notice without a second query,
        // and so API consumers can tell an auto-void happened without a
        // separate round trip to fetch the notice. Harmless to include in
        // the JSON response: null on every withdrawal that didn't touch
        // an open notice, which is the overwhelming majority.
        $documentRequest->setAttribute('auto_voided_deficiency_notice_id', $autoVoidedRemarkId);

        // Fired AFTER commit, not inside the transaction closure above —
        // unlike updateRequest()'s notifyOwnerOfStatusChange() call (which
        // runs inside its transaction), a withdrawal notification
        // reaching a student for a withdrawal that later turned out to
        // roll back would be actively confusing ("your request was
        // withdrawn" followed by no actual change). Matches the
        // reasoning createRequest() already documents for keeping
        // notifications outside its own transaction.
        $this->notifyOwnerOfWithdrawal($documentRequest);

        // Deficiency Notice & Withdrawn Status — Phase 5. Deliberately
        // NOT sending a second, separate deficiency_notice_voided
        // notification to the student here. The withdrawal notification
        // just sent above is already the complete, definitive explanation
        // from the student's point of view ("your request has been
        // withdrawn: <reason>") — a second "your request will not
        // proceed: <cascaded reason>" notification moments later tells
        // them nothing new and reads as a confusing duplicate for what
        // is, from where they sit, one event. The notice is still fully
        // and correctly voided in request_remarks (its own void_reason/
        // voided_by/voided_at) and audited via
        // AuditLog::ACTION_DEFICIENCY_NOTICE_VOIDED (see
        // DocumentRequestController::withdraw()) — this only skips the
        // redundant customer-facing message, not the audit trail. Revisit
        // if registrar/student feedback wants the explicit second notice;
        // wire it from $autoVoidedRemarkId using the same
        // 'deficiency_notice_voided' trigger DeficiencyNoticeService::
        // void() uses.

        if ($statusChanged) {
            $this->flushAnalyticsCache();
        }

        return $documentRequest;
    }

    /**
     * Builds and sends the request_withdrawn notification. For every
     * reason except Other, the message substitutes
     * WithdrawalReasonEnum::label() (e.g. "Wrong item was paid for"). For
     * Other, it substitutes the staff-entered withdrawal_detail free text
     * instead — a generic "Other" would tell the student nothing they
     * don't already know, whereas the detail they typed is the whole
     * point of choosing Other in the first place.
     */
    private function notifyOwnerOfWithdrawal(DocumentRequest $documentRequest): void
    {
        $owner = SystemUser::find($documentRequest->user_id);
        if (!$owner) return;

        $reasonText = $this->resolveWithdrawalReasonText(
            $documentRequest->withdrawal_reason,
            $documentRequest->withdrawal_detail,
        );

        $this->notificationService->send(
            recipient:    $owner,
            triggerEvent: RequestStatusEnum::Withdrawn->notificationTrigger(),
            data:         [
                'request_id'        => $documentRequest->request_id,
                'withdrawal_reason' => $reasonText,
            ],
            requestId:    $documentRequest->request_id,
        );
    }

    /**
     * Deficiency Notice & Withdrawn Status — Phase 1/5.
     *
     * Resolves the human-readable text for a withdrawal_reason value, for
     * substitution into a notification message or a cascaded
     * request_remarks.void_reason. For every reason except Other, this is
     * simply WithdrawalReasonEnum::label() (e.g. "Wrong item was paid
     * for"); for Other, the staff-entered free-text detail is used
     * instead — a generic "Other" would tell the reader nothing they
     * don't already know, whereas the detail is the whole point of
     * having chosen Other. Shared by notifyOwnerOfWithdrawal() (the
     * student-facing request_withdrawn notification) and withdraw()'s
     * Phase 5 auto-void cascade.
     *
     * Takes raw strings rather than a DocumentRequest so it can be called
     * from inside withdraw()'s transaction closure against $data, before
     * $documentRequest->withdrawal_reason has actually been persisted.
     */
    private function resolveWithdrawalReasonText(string $withdrawalReason, ?string $withdrawalDetail): string
    {
        $reason = WithdrawalReasonEnum::from($withdrawalReason);

        return $reason === WithdrawalReasonEnum::Other
            ? ($withdrawalDetail ?: $reason->label())
            : $reason->label();
    }

    /**
     * Data Retention & Disposal Policy — Section 3.4 ("Requests That
     * Can Never Be Resolved (e.g., Death of Requestor)").
     *
     * Closes a request as ClosedUnableToProcess: the policy's
     * "worst-case scenario" outcome when an open Deficiency Notice can
     * never be complied with because the requestor is deceased or
     * otherwise permanently unable to respond. Structurally mirrors
     * withdraw() above (same lock ordering, same transaction shape,
     * same auto-void-the-open-notice cascade) but is a semantically
     * DISTINCT action, not a variant of withdraw() — see
     * RequestStatusEnum::ClosedUnableToProcess and ClosureReasonEnum's
     * docblocks for why these are kept as two separate code paths
     * rather than one parameterized method.
     *
     * GUARD — requires an OPEN Deficiency Notice to exist on this
     * request. This status only makes sense as the resolution of an
     * unresolvable notice (the policy's own framing: "If a Deficiency
     * Notice cannot be complied with...") — there is no legitimate
     * reason to reach ClosedUnableToProcess on a request that was never
     * placed on hold in the first place. If staff need to close a
     * request for a reason unrelated to a missing-item hold, that is
     * an ordinary Withdrawn (see withdraw() above and
     * WithdrawalReasonEnum), not this.
     *
     * WHAT THIS METHOD DOES NOT DO (see the add_closed_unable_to_process
     * _status migration's docblock for the full list): it does not
     * delete, anonymize, or move any data to a disposal/retention
     * pipeline. Official Receipt records, academic records, and
     * notification/inbox history all remain exactly where they are,
     * governed by their own retention periods per Data Retention &
     * Disposal Policy §3.2 — none of which are enforced by this action.
     * It also does not release or dispose of any physical document
     * already prepared for the request (policy §3.4 item 3) — this
     * codebase has no subsystem tracking "has a physical document
     * already been printed for this request" independent of
     * request_certificate.generated_at, and conflating that signal
     * with this closure action risks silently mis-triggering a
     * shredding instruction on a request where nothing was ever
     * printed. That step remains a manual Registrar's Office procedure
     * today; flagged here as a clear integration point if/when a
     * document-preparation-tracking feature exists to hook into.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException via abort() calls below
     */
    public function closeUnableToProcess(DocumentRequest $documentRequest, array $data): DocumentRequest
    {
        $statusChanged = false;
        $voidedRemarkId = null;

        $documentRequest = DB::transaction(function () use ($documentRequest, $data, &$statusChanged, &$voidedRemarkId) {
            $documentRequest = DocumentRequest::lockForUpdate()
                ->findOrFail($documentRequest->request_id);

            if ($documentRequest->is_archived) {
                abort(422, 'This request is archived and is read-only. Restore it first.');
            }

            $oldStatusId   = $documentRequest->status_id;
            $currentStatus = RequestStatusEnum::from((int) $oldStatusId);

            if (!in_array(RequestStatusEnum::ClosedUnableToProcess, $currentStatus->allowedTransitions(), true)) {
                abort(422, "Transition from {$currentStatus->name} to Closed - Unable to Process is not allowed.");
            }

            // The core guard this action exists to enforce — see this
            // method's docblock. Row-locked in the same parent-then-
            // child order DeficiencyNoticeService::issue()/withdraw()
            // already establish.
            $openRemark = RequestRemark::where('request_id', $documentRequest->request_id)
                ->where('status', RequestRemark::STATUS_OPEN)
                ->lockForUpdate()
                ->first();

            if (!$openRemark) {
                abort(422, 'This request has no open Deficiency Notice. Closed - Unable to Process only applies to a request whose open notice cannot be resolved.');
            }

            $closureReasonText = $this->resolveClosureReasonText(
                $data['closure_reason'],
                $data['closure_detail'] ?? null,
            );

            // Void the notice as part of the same closure action — the
            // request itself is now terminally closed, so the notice
            // that triggered this closure can no longer sensibly remain
            // "open". Deliberately inlined rather than delegated to
            // DeficiencyNoticeService::void(), for the identical
            // "notification fired inside a savepoint, not a real
            // commit" reason documented at length in withdraw() above.
            $openRemark->update([
                'status'      => RequestRemark::STATUS_VOIDED,
                'voided_by'   => Auth::id(),
                'voided_at'   => now(),
                'void_reason' => 'Request closed — Unable to Process: ' . $closureReasonText,
            ]);

            $voidedRemarkId = $openRemark->remark_id;

            $documentRequest->update([
                'status_id'               => RequestStatusEnum::ClosedUnableToProcess->value,
                'closure_reason'          => $data['closure_reason'],
                'closure_detail'          => $data['closure_detail'] ?? null,
                'closure_proof_reference' => $data['closure_proof_reference'],
                'closed_by'               => Auth::id(),
                'closed_at'               => now(),
            ]);

            $this->recordStatusHistory($documentRequest, $oldStatusId);
            $statusChanged = true;

            return $documentRequest;
        }); // end DB::transaction

        // Transient, never persisted — same pattern withdraw() uses for
        // auto_voided_deficiency_notice_id, so the controller can write
        // its own audit_logs entry for the voided notice without a
        // second query.
        $documentRequest->setAttribute('closed_deficiency_notice_id', $voidedRemarkId);

        // Fired after commit, not inside the transaction closure —
        // identical reasoning to withdraw()'s notifyOwnerOfWithdrawal()
        // call: a notification reaching an account for a closure that
        // later rolled back would be actively wrong, and per the Data
        // Retention & Disposal Policy §3.2 this notification/inbox/
        // email record is itself retained as an audit artifact — it
        // must correspond to something that actually happened.
        $this->notifyOwnerOfClosure($documentRequest);

        if ($statusChanged) {
            $this->flushAnalyticsCache();
        }

        return $documentRequest;
    }

    /**
     * Builds and sends the request_closed_unable_to_process
     * notification. Sent as a best-effort record to the account on
     * file, which — per the scenario this status exists for — may
     * belong to a deceased requestor; see the notification-type
     * migration's docblock for why this is still sent and retained
     * regardless (audit trail, per Data Retention & Disposal Policy
     * §3.2's 3-year retention on notifications/inbox/email).
     */
    private function notifyOwnerOfClosure(DocumentRequest $documentRequest): void
    {
        $owner = SystemUser::find($documentRequest->user_id);
        if (!$owner) return;

        $reasonText = $this->resolveClosureReasonText(
            $documentRequest->closure_reason,
            $documentRequest->closure_detail,
        );

        $this->notificationService->send(
            recipient:    $owner,
            triggerEvent: RequestStatusEnum::ClosedUnableToProcess->notificationTrigger(),
            data:         [
                'request_id'      => $documentRequest->request_id,
                'closure_reason'  => $reasonText,
            ],
            requestId:    $documentRequest->request_id,
        );
    }

    /**
     * Data Retention & Disposal Policy — Section 3.4.
     *
     * Resolves the human-readable text for a closure_reason value, for
     * substitution into a notification message or a cascaded
     * request_remarks.void_reason. Mirrors
     * resolveWithdrawalReasonText()'s exact shape: for every reason
     * except Other, this is simply ClosureReasonEnum::label(); for
     * Other, the staff-entered free-text detail is used instead. Takes
     * raw strings (not a DocumentRequest) so it can be called from
     * inside closeUnableToProcess()'s transaction closure against
     * $data, before $documentRequest->closure_reason has actually been
     * persisted.
     */
    private function resolveClosureReasonText(string $closureReason, ?string $closureDetail): string
    {
        $reason = ClosureReasonEnum::from($closureReason);

        return $reason === ClosureReasonEnum::Other
            ? ($closureDetail ?: $reason->label())
            : $reason->label();
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Build the DB row array based on the user's role.
     *
     * @throws \RuntimeException
     */
    /**
     * Whether ANY document/certificate type in this request's submitted
     * items has requires_source_submission = true — e.g. any CTC /
     * Authentication Fee document_type row (see the 2026_08_29 logbook_
     * category/CTC reconciliation migrations). If so, the whole request
     * starts life in AwaitingSubmission rather than Processing.
     *
     * Deliberately request-level, not per-item: request_document/
     * request_certificate have no status column of their own today (see
     * the Phase 2 discussion this gating is a slice of — item-level
     * status is a larger structural change than this flag alone
     * justifies). A request that mixes a plain document with a CTC item
     * gates the WHOLE request until the CTC item's source is submitted;
     * splitting fast/slow items to progress independently is future
     * work, not something this check attempts.
     *
     * Two whereIn() lookups instead of one query per item — this runs
     * once per request creation, not once per line item, keeping it cheap
     * regardless of how many documents/certificates are submitted.
     */
    private function requestRequiresSourceSubmission(array $validated): bool
    {
        $documentTypeIds = array_column($validated['documents'] ?? [], 'document_type_id');
        if (!empty($documentTypeIds)) {
            $needsSubmission = DocumentType::whereIn('document_type_id', $documentTypeIds)
                ->where('requires_source_submission', true)
                ->exists();

            if ($needsSubmission) {
                return true;
            }
        }

        $certificateTypeIds = array_column($validated['certificates'] ?? [], 'certificate_type_id');
        if (!empty($certificateTypeIds)) {
            return CertificationType::whereIn('certificate_type_id', $certificateTypeIds)
                ->where('requires_source_submission', true)
                ->exists();
        }

        return false;
    }

    private function buildRequestData(SystemUser $user, array $validated, RequestStatusEnum $initialStatus, RequestChannelEnum $channel = RequestChannelEnum::SelfService): array
    {
        $base = [
            'user_id'            => $user->user_id,
            'status_id'          => $initialStatus->value,
            'request_purpose_id' => $validated['request_purpose_id'],
            'or_number'          => $validated['or_number'] ?? null,
            'receipt_date'       => $validated['receipt_date'] ?? null,
            'channel'            => $channel->value,
        ];

        if ($user->isStudent()) {
            $studentProfile = $user->studentProfile;
            $academicRecord = $user->academicRecord;

            if (!$studentProfile || !$academicRecord) {
                abort(400, 'Student profile or academic record not found.');
            }

            return array_merge($base, [
                'student_profile_id'  => $studentProfile->student_profile_id,
                'student_academic_id' => $academicRecord->student_academic_id,
                'alumni_profile_id'   => null,
                'alumni_academic_id'  => null,
            ]);
        }

        if ($user->isAlumni()) {
            $alumniProfile  = $user->alumniProfile;
            $academicRecord = $alumniProfile?->academicRecord;

            if (!$alumniProfile || !$academicRecord) {
                abort(400, 'Alumni profile or academic record not found.');
            }

            return array_merge($base, [
                'student_profile_id'  => null,
                'student_academic_id' => null,
                'alumni_profile_id'   => $alumniProfile->alumni_profile_id,
                'alumni_academic_id'  => $academicRecord->alumni_academic_id,
            ]);
        }

        abort(403, 'Unauthorized role.');
    }

    /**
     * Work Item #1 — Granular Per-Action Permissions: the fine gate for
     * PUT /document-requests/{id}, called from updateRequest() after
     * the transition itself has already been validated as legal.
     *
     * Resolves which dashboard action(s) this specific call actually
     * requires (see requiredDashboardActions()) and aborts with 403 if
     * the acting admin's policy doesn't grant every one of them.
     *
     * Reads the actor off Auth::user() rather than taking a parameter —
     * matches recordStatusHistory()'s existing Auth::id() usage in this
     * same class, and keeps this method callable from claimRequest()'s
     * call into updateRequest() without changing either method's
     * public signature.
     *
     * Deliberately permissive for non-admin/non-SystemUser actors
     * (console commands, the automated shredder's direct DB writes,
     * etc.) — those either bypass this service entirely or are already
     * gated by isStaff()/role middleware upstream. SystemUser::
     * hasModuleAccess() itself already returns true unconditionally for
     * super admins and for any non-admin role, so in practice this only
     * ever restricts an ADMIN whose policy is missing the specific
     * action a given call needs.
     */
    private function authorizeStatusChange(array $validated, ?RequestStatusEnum $targetStatus): void
    {
        $actor = Auth::user();

        if (!$actor instanceof SystemUser) {
            return;
        }

        foreach ($this->requiredDashboardActions($validated, $targetStatus) as $action) {
            if (!$actor->hasModuleAccess('dashboard', $action)) {
                abort(403, "Your account's assigned policy does not grant the '{$action}' action on the dashboard module.");
            }
        }
    }

    /**
     * Which dashboard action(s) this update() call requires, based on
     * what's actually in $validated — per the Work Item #1 spec:
     *
     *   target = Completed                                  -> Complete
     *   target = ReadyToClaim | PendingSignature | Forfeited -> Process
     *   or_number / receipt_date changing (any status)       -> Process
     *
     * Both checks apply independently and accumulate: a single call
     * that both completes the request AND edits or_number requires
     * BOTH 'Complete' and 'Process' — a Student Staff account (which
     * only ever has 'Complete') is correctly blocked from smuggling a
     * metadata edit in alongside a status change it's otherwise allowed
     * to make. A call with no status_id and no or_number/receipt_date
     * change (i.e. nothing this policy gates) requires nothing here.
     *
     * @return array<string> distinct action tokens, possibly empty
     */
    private function requiredDashboardActions(array $validated, ?RequestStatusEnum $targetStatus): array
    {
        $required = [];

        if ($targetStatus !== null) {
            $required[] = match ($targetStatus) {
                RequestStatusEnum::Completed => 'Complete',
                // ReadyToClaim, PendingSignature, and Forfeited all
                // require Process — as does Processing itself, which
                // (unlike before AwaitingSubmission existed) IS now a
                // reachable target via AwaitingSubmission -> Processing
                // (staff confirming a CTC/Authentication Fee source
                // document has been received). Cancelled is unreachable
                // (allowedTransitions() already aborted on an illegal
                // transition before this runs). Process is the safe
                // default across all of these since it's the more
                // restrictive of the two dashboard write actions.
                default => 'Process',
            };
        }

        if (array_key_exists('or_number', $validated) || array_key_exists('receipt_date', $validated)) {
            $required[] = 'Process';
        }

        return array_values(array_unique($required));
    }

    private function recordStatusHistory(DocumentRequest $documentRequest, int $oldStatusId): void
    {
        // minutes_processed: kept exactly as before (cumulative raw
        // wall-clock minutes since requested_at) for backward compatibility
        // with existing reports/dashboards that already read this column
        // with that meaning. Do not repurpose it — see the migration
        // 2026_08_15_000000_add_pending_signature_status doc block for why
        // business_minutes exists as a separate column instead.
        $minutesProcessed = (int) $documentRequest->requested_at->diffInMinutes(now());

        // business_minutes: the calendar-aware duration of THIS segment
        // only — i.e. time elapsed since the previous status change (or
        // since requested_at, if this is the request's first transition),
        // counting only minutes inside office hours and skipping
        // weekends/holidays. This is what makes it possible to fairly
        // attribute elapsed time to whoever actually controlled it:
        //   - a Processing → PendingSignature segment measures the
        //     registrar's own turnaround.
        //   - a PendingSignature → ReadyToClaim segment measures the
        //     external signing office's turnaround.
        //   - a Processing → ReadyToClaim segment (no signature needed)
        //     measures the registrar's turnaround, same as before.
        //
        // Currently uses the default business calendar for every segment
        // (BusinessCalendarService falls back to it when no calendar id is
        // given). Per-office calendars are already supported by the
        // service/schema (Step 2) — once individual signing offices are
        // confirmed to keep different hours, look up that office's
        // calendar_id here for PendingSignature → ReadyToClaim segments
        // specifically, rather than changing this method's shape again.
        $segmentStart = RequestHistory::where('request_id', $documentRequest->request_id)
            ->orderByDesc('changed_at')
            ->orderByDesc('request_history_id')
            ->value('changed_at');

        $segmentStart = $segmentStart
            ? \Illuminate\Support\Carbon::parse($segmentStart)
            : $documentRequest->requested_at;

        $businessMinutes = $this->businessCalendarService->minutesBetween($segmentStart, now());

        RequestHistory::create([
            'request_id'        => $documentRequest->request_id,
            'old_status_id'     => $oldStatusId,
            'new_status_id'     => $documentRequest->status_id,
            'changed_at'        => now(),
            'changed_by'        => Auth::id(),
            'minutes_processed' => $minutesProcessed,
            'business_minutes'  => $businessMinutes,
        ]);
    }

    private function notifyOwnerOfStatusChange(DocumentRequest $documentRequest): void
    {
        $owner = SystemUser::find($documentRequest->user_id);
        if (!$owner) return;

        $status = RequestStatusEnum::from((int) $documentRequest->status_id);
        $trigger = $status->notificationTrigger();

        if ($trigger) {
            $data = ['request_id' => $documentRequest->request_id];

            // The ticket already went out on the request_submitted
            // notification above (submitRequest()), so this isn't the only
            // place it's available — but ReadyToClaim is the moment a
            // student is actually being told "go claim this now," so it's
            // worth repeating the ticket on this notification too rather
            // than making them scroll back to find their original one.
            // uuid/claim_code aren't sensitive by themselves (see
            // ClaimTicket.jsx docblock: the QR alone can't complete a claim
            // — staff still verify identity + requirements in person before
            // scanning), so there's no downside to including them again.
            if ($status === RequestStatusEnum::ReadyToClaim) {
                $data['uuid']       = $documentRequest->uuid;
                $data['claim_code'] = $documentRequest->claim_code;
            }

            $this->notificationService->send(
                recipient:    $owner,
                triggerEvent: $trigger,
                data:         $data,
                requestId:    $documentRequest->request_id,
            );
        }
    }
}