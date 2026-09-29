<?php

namespace App\Services;

use App\Enums\RequestStatusEnum;
use App\Models\DocumentRequest;
use App\Models\RequestCertificate;
use App\Models\RequestDocument;
use App\Models\RequestHistory;
use App\Models\RequestReleaseGroup;
use App\Models\SystemUser;
use App\Contracts\NotificationServiceInterface;
use App\Services\Concerns\FlushesAnalyticsCache;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Item-level status transitions for request_document / request_certificate
 * — see migration 2026_08_29_000007_add_status_to_request_line_items for the
 * schema this operates on and the full "why" of the design.
 *
 * SCOPE: this service lets staff advance ONE line item (e.g. confirm a
 * single CTC item's source document was received) without forcing every
 * other item on the same request through the same transition at the same
 * time. It does NOT change what a single claim ticket means — claiming
 * still happens once per document_request (see DocumentRequestService::
 * claimRequest()) — it only changes how staff track and progress the work
 * that happens before that point.
 *
 * document_request.status_id remains authoritative for claiming and the
 * student-facing view. Every successful item transition here recomputes it
 * as a derived "earliest-stage-wins" aggregate (see
 * recomputeAggregateStatus()) — the request is only as far along as its
 * least-advanced item.
 *
 * Reuses the exact same primitives DocumentRequestService::updateRequest()
 * already relies on (RequestStatusEnum::allowedTransitions(), the
 * SystemUser::hasModuleAccess() dashboard-action gate, business-calendar-
 * aware segment timing, FlushesAnalyticsCache) rather than duplicating that
 * logic with a different shape — a request-level transition and an
 * item-level transition are the same kind of event at a different
 * granularity, and should stay behaviorally identical wherever the schema
 * doesn't force a difference.
 *
 * BULK OPERATIONS — bulkAdvanceItems() (used by DocumentRequestController::
 * bulkReadyItems()/bulkDoneItems()) applies this same per-item eligibility
 * logic across a whole batch of selected requests at once: every request's
 * document/certificate children are evaluated and advanced individually,
 * ineligible items are skipped without blocking eligible ones (on the same
 * request or on a different one in the batch), and each affected request's
 * (and, where applicable, release group's) aggregate status is recomputed
 * once at the end from the resulting child statuses — never per-item, to
 * avoid redundant history/notification writes for a single batch action.
 */
class RequestItemStatusService
{
    use FlushesAnalyticsCache;

    /**
     * Relative progress rank used to pick the aggregate status out of a
     * set of item statuses — lower rank = earlier stage. Terminal
     * statuses (Completed/Forfeited) intentionally share the highest
     * rank: see recomputeAggregateStatus() for how a mixed terminal set
     * is resolved (an edge case that cannot occur yet under today's
     * single-ticket claiming, since Forfeited is only ever written at
     * the request level by the automated shredder — flagged here rather
     * than silently guessed at).
     *
     * Cancelled is deliberately absent — it is unreachable going
     * forward (see RequestStatusEnum::Cancelled's @deprecated note) and
     * is never a value a live item status column should hold.
     */
    private const STAGE_RANK = [
        RequestStatusEnum::AwaitingSubmission->value => 0,
        RequestStatusEnum::Processing->value         => 1,
        RequestStatusEnum::PendingSignature->value   => 2,
        RequestStatusEnum::ReadyToClaim->value       => 3,
        RequestStatusEnum::Completed->value          => 4,
        RequestStatusEnum::Forfeited->value          => 4,
    ];

    public function __construct(
        private NotificationServiceInterface $notificationService,
        private BusinessCalendarService      $businessCalendarService,
    ) {}

    /**
     * Advance a single request_document row's status.
     *
     * @throws \Illuminate\Http\Exceptions\HttpResponseException on an
     *         illegal transition, an archived parent request, or a
     *         missing dashboard permission — same abort() shape as
     *         DocumentRequestService::updateRequest(), so the controller
     *         doesn't need a different error-handling path per endpoint.
     */
    public function advanceDocumentItem(RequestDocument $item, int $targetStatusId): RequestDocument
    {
        return DB::transaction(function () use ($item, $targetStatusId) {
            /** @var RequestDocument $item */
            $item = RequestDocument::lockForUpdate()->findOrFail($item->request_document_id);

            $documentRequest = DocumentRequest::lockForUpdate()
                ->findOrFail($item->request_id);

            $this->guardArchived($documentRequest);
            $this->guardNotTerminal($documentRequest);

            $targetStatus = $this->validateTransition($item->status_id, $targetStatusId);
            $this->authorizeItemStatusChange($targetStatus);

            $oldStatusId = $item->status_id;
            $item->update(['status_id' => $targetStatus->value]);

            $this->recordItemHistory($documentRequest, $oldStatusId, $targetStatus->value, requestDocumentId: $item->request_document_id);

            $this->recomputeAggregateStatus($documentRequest);

            if ($item->request_release_group_id !== null) {
                $this->recomputeReleaseGroupAggregate((int) $item->request_release_group_id);
            }

            $this->flushAnalyticsCache();

            return $item->refresh();
        });
    }

    /**
     * Advance a single request_certificate row's status. Mirrors
     * advanceDocumentItem() exactly — kept as a separate method (rather
     * than one generic method taking a class-string) because the two
     * models have different FK columns to lock/join against, and a
     * single overloaded method would need runtime branching for exactly
     * the same effect.
     */
    public function advanceCertificateItem(RequestCertificate $item, int $targetStatusId): RequestCertificate
    {
        return DB::transaction(function () use ($item, $targetStatusId) {
            /** @var RequestCertificate $item */
            $item = RequestCertificate::lockForUpdate()->findOrFail($item->request_certificate_id);

            $documentRequest = DocumentRequest::lockForUpdate()
                ->findOrFail($item->request_id);

            $this->guardArchived($documentRequest);
            $this->guardNotTerminal($documentRequest);

            $targetStatus = $this->validateTransition($item->status_id, $targetStatusId);
            $this->guardCertificateGenerated($item, $targetStatus);
            $this->authorizeItemStatusChange($targetStatus);

            $oldStatusId = $item->status_id;
            $item->update(['status_id' => $targetStatus->value]);

            $this->recordItemHistory($documentRequest, $oldStatusId, $targetStatus->value, requestCertificateId: $item->request_certificate_id);

            $this->recomputeAggregateStatus($documentRequest);

            if ($item->request_release_group_id !== null) {
                $this->recomputeReleaseGroupAggregate((int) $item->request_release_group_id);
            }

            $this->flushAnalyticsCache();

            return $item->refresh();
        });
    }

    // -------------------------------------------------------------------------
    // Bulk operations — Bulk Ready / Bulk Done
    // -------------------------------------------------------------------------

    /**
     * Advances every ELIGIBLE request_document/request_certificate row
     * belonging to the given set of document_request ids to $targetStatus,
     * skipping ineligible items/requests without letting them block
     * eligible ones, then rolls up each affected request's (and release
     * group's) aggregate status once processing is complete.
     *
     * Eligibility, per item:
     *   - The item's CURRENT status must legally transition to
     *     $targetStatus (RequestStatusEnum::allowedTransitions()) — this
     *     alone is what excludes items that are already at or past the
     *     target (e.g. already ReadyToClaim/Completed/Forfeited when
     *     bulk-marking Ready, or not-yet-Ready when bulk-marking Done),
     *     since none of those have $targetStatus in their allowed set.
     *   - Certificates additionally run certificateGeneratedIneligibility
     *     Reason() — see that method's docblock for why it currently
     *     always passes (a live, intentional business decision, not a
     *     bug), shared with the single-item and whole-request enforcement
     *     points so re-enabling it later is a one-line change that
     *     applies everywhere at once, including here.
     *
     * Eligibility, per request:
     *   - An archived request is entirely skipped (read-only, same rule
     *     as every other write path) — restoring must happen first via
     *     DocumentRequestService::restoreRequest().
     *   - A request that is already Completed, Forfeited, Withdrawn or
     *     Closed is skipped with reason 'request_terminal' — its outcome
     *     is final and its items must not be reopened or re-notified.
     *   - A request id that doesn't exist is reported back, not thrown,
     *     so one typo/stale id in a large batch doesn't fail the whole
     *     call — same "skip and report" contract as
     *     DocumentRequestService::archiveRequests()/restoreRequests().
     *
     * Runs as ONE database transaction covering the whole batch (matching
     * archiveRequests()/restoreRequests()' existing convention) —
     * BulkRequestIdsRequest caps a batch at 200 request ids, which keeps
     * the row-lock footprint and transaction lifetime bounded even in the
     * worst case (every request having several line items).
     *
     * @param  int[] $requestIds
     * @return array{
     *   target_status: string,
     *   items_updated: array<int, array{type: string, id: int, request_id: int, old_status_id: int, new_status_id: int}>,
     *   items_skipped: array<int, array{type: string, id: int, request_id: int, reason: string, current_status?: string}>,
     *   requests_processed: int[],
     *   requests_status_changed: int[],
     *   requests_skipped: array<int, array{request_id: int, reason: string}>,
     * }
     */
    public function bulkAdvanceItems(array $requestIds, RequestStatusEnum $targetStatus): array
    {
        // Coarse+fine gate up front, same shape as authorizeItemStatusChange()
        // is used for a single item — the target status is fixed for the
        // whole batch, so this only needs to run once rather than per item.
        $this->authorizeItemStatusChange($targetStatus);

        return DB::transaction(function () use ($requestIds, $targetStatus) {
            $result = [
                'target_status'           => $targetStatus->name,
                'items_updated'           => [],
                'items_skipped'           => [],
                'requests_processed'      => [],
                'requests_status_changed' => [],
                'requests_skipped'        => [],
            ];

            // withArchived() is required here: DocumentRequest's default
            // ExcludeArchivedScope would otherwise filter archived requests
            // out of this query entirely, making them indistinguishable
            // from a nonexistent id below (both would fall through to
            // 'not_found' instead of the correct 'archived' reason).
            /** @var \Illuminate\Support\Collection<int, DocumentRequest> $documentRequests */
            $documentRequests = DocumentRequest::withArchived()
                ->whereIn('request_id', $requestIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('request_id');

            foreach ($requestIds as $requestId) {
                if (!$documentRequests->has((int) $requestId)) {
                    $result['requests_skipped'][] = ['request_id' => (int) $requestId, 'reason' => 'not_found'];
                }
            }

            $processableRequestIds = [];
            foreach ($documentRequests as $documentRequest) {
                if ($documentRequest->is_archived) {
                    $result['requests_skipped'][] = [
                        'request_id' => (int) $documentRequest->request_id,
                        'reason'     => 'archived',
                    ];
                    continue;
                }
                // A request that already reached its final outcome
                // (Completed, Forfeited, Withdrawn, Closed) is frozen.
                // Advancing one of its items would either be a no-op or
                // — before this check — let recomputeAggregateStatus()
                // pull the request back out of that outcome.
                if ($this->isTerminalRequest($documentRequest)) {
                    $result['requests_skipped'][] = [
                        'request_id' => (int) $documentRequest->request_id,
                        'reason'     => 'request_terminal',
                    ];
                    continue;
                }
                $processableRequestIds[] = $documentRequest->request_id;
            }

            if (empty($processableRequestIds)) {
                return $result;
            }

            $documents = RequestDocument::whereIn('request_id', $processableRequestIds)
                ->lockForUpdate()
                ->get();

            $certificates = RequestCertificate::whereIn('request_id', $processableRequestIds)
                ->lockForUpdate()
                ->get();

            $touchedRequestIds      = [];
            $touchedReleaseGroupIds = [];

            foreach ($documents as $item) {
                $outcome = $this->attemptBulkItemTransition(
                    documentRequest: $documentRequests[$item->request_id],
                    item:            $item,
                    itemType:        'document',
                    itemId:          $item->request_document_id,
                    targetStatus:    $targetStatus,
                );

                if ($outcome['action'] === 'skipped') {
                    $result['items_skipped'][] = $outcome['entry'];
                    continue;
                }

                $result['items_updated'][] = $outcome['entry'];
                $touchedRequestIds[$item->request_id] = true;
                if ($item->request_release_group_id !== null) {
                    $touchedReleaseGroupIds[$item->request_release_group_id] = true;
                }
            }

            foreach ($certificates as $item) {
                $ineligibilityReason = $this->certificateGeneratedIneligibilityReason($item, $targetStatus);
                if ($ineligibilityReason !== null) {
                    $result['items_skipped'][] = [
                        'type'       => 'certificate',
                        'id'         => $item->request_certificate_id,
                        'request_id' => (int) $item->request_id,
                        'reason'     => $ineligibilityReason,
                    ];
                    continue;
                }

                $outcome = $this->attemptBulkItemTransition(
                    documentRequest: $documentRequests[$item->request_id],
                    item:            $item,
                    itemType:        'certificate',
                    itemId:          $item->request_certificate_id,
                    targetStatus:    $targetStatus,
                );

                if ($outcome['action'] === 'skipped') {
                    $result['items_skipped'][] = $outcome['entry'];
                    continue;
                }

                $result['items_updated'][] = $outcome['entry'];
                $touchedRequestIds[$item->request_id] = true;
                if ($item->request_release_group_id !== null) {
                    $touchedReleaseGroupIds[$item->request_release_group_id] = true;
                }
            }

            foreach (array_keys($touchedReleaseGroupIds) as $groupId) {
                $this->recomputeReleaseGroupAggregate((int) $groupId);
            }

            foreach ($processableRequestIds as $requestId) {
                if (!isset($touchedRequestIds[$requestId])) {
                    $result['requests_skipped'][] = [
                        'request_id' => (int) $requestId,
                        'reason'     => 'no_eligible_items',
                    ];
                    continue;
                }

                $documentRequest   = $documentRequests[$requestId];
                $oldParentStatusId = (int) $documentRequest->status_id;

                $this->recomputeAggregateStatus($documentRequest);

                $result['requests_processed'][] = (int) $requestId;

                if ((int) $documentRequest->status_id !== $oldParentStatusId) {
                    $result['requests_status_changed'][] = (int) $requestId;
                }
            }

            if (!empty($result['items_updated'])) {
                $this->flushAnalyticsCache();
            }

            return $result;
        });
    }

    /**
     * Evaluates and, if eligible, applies ONE line item's transition
     * inside bulkAdvanceItems(). This is the single place that decides
     * "invalid_transition" eligibility for the bulk path (the certificate-
     * generated check is a separate, earlier gate the caller applies
     * before reaching here — see certificateGeneratedIneligibilityReason()),
     * so there is exactly one implementation of "does this item's current
     * status allow the target" to keep in sync, rather than deciding it
     * once to apply the update and a second time to explain a skip.
     *
     * @param RequestDocument|RequestCertificate $item
     * @return array{
     *   action: 'updated'|'skipped',
     *   entry: array{type: string, id: int, request_id: int, old_status_id?: int, new_status_id?: int, reason?: string, current_status?: string},
     * }
     */
    private function attemptBulkItemTransition(
        DocumentRequest $documentRequest,
        RequestDocument|RequestCertificate $item,
        string $itemType,
        int $itemId,
        RequestStatusEnum $targetStatus,
    ): array {
        // Defensive default mirrors validateTransition()'s single-item
        // handling — a NULL item status "shouldn't be reachable in
        // practice" (see that method's comment), but a bulk pass over
        // many rows should degrade the same way a single-item update
        // would rather than throwing and aborting the whole batch.
        $currentStatus = RequestStatusEnum::from($item->status_id ?? RequestStatusEnum::Processing->value);

        if (!in_array($targetStatus, $currentStatus->allowedTransitions(), true)) {
            return [
                'action' => 'skipped',
                'entry'  => [
                    'type'           => $itemType,
                    'id'             => $itemId,
                    'request_id'     => (int) $item->request_id,
                    'reason'         => 'invalid_transition',
                    'current_status' => $currentStatus->name,
                ],
            ];
        }

        $oldStatusId = $item->status_id;
        $item->update(['status_id' => $targetStatus->value]);

        $this->recordItemHistory(
            $documentRequest,
            $oldStatusId,
            $targetStatus->value,
            requestDocumentId:    $itemType === 'document' ? $itemId : null,
            requestCertificateId: $itemType === 'certificate' ? $itemId : null,
        );

        return [
            'action' => 'updated',
            'entry'  => [
                'type'          => $itemType,
                'id'            => $itemId,
                'request_id'    => (int) $item->request_id,
                'old_status_id' => (int) $oldStatusId,
                'new_status_id' => $targetStatus->value,
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private function guardArchived(DocumentRequest $documentRequest): void
    {
        // Same rule as DocumentRequestService::updateRequest() — archived
        // requests are read-only everywhere, including one line item at
        // a time. Restoring must go through DocumentRequestService::
        // restoreRequest() first.
        if ($documentRequest->is_archived) {
            abort(422, 'This request is archived and is read-only. Restore it first.');
        }
    }

    /**
     * A request in a terminal status (Completed, Forfeited, Withdrawn,
     * Closed - Unable to Process) has reached its final outcome. Item
     * writes against it are refused, because recomputeAggregateStatus()
     * would otherwise recompute the parent from whichever item rows are
     * stale and move the request back out of that outcome, sending the
     * student a second, contradictory notification.
     */
    private function guardNotTerminal(DocumentRequest $documentRequest): void
    {
        if ($this->isTerminalRequest($documentRequest)) {
            $status = RequestStatusEnum::from((int) $documentRequest->status_id);
            $label  = trim(preg_replace('/(?<!^)[A-Z]/', ' $0', $status->name));

            abort(422, "This request is already {$label}, so its items can no longer be changed.");
        }
    }

    private function isTerminalRequest(DocumentRequest $documentRequest): bool
    {
        $status = RequestStatusEnum::tryFrom((int) $documentRequest->status_id);

        return $status !== null && $status->isTerminal();
    }

    /**
     * Throwing wrapper around certificateGeneratedIneligibilityReason()
     * for the single-item path (advanceCertificateItem()) — aborts the
     * request/response cycle on ineligibility, exactly as before this was
     * extracted into a shared, reason-returning check.
     */
    private function guardCertificateGenerated(RequestCertificate $item, RequestStatusEnum $targetStatus): void
    {
        if ($this->certificateGeneratedIneligibilityReason($item, $targetStatus) !== null) {
            abort(422, 'Certificate must be generated before marking as Ready to Claim.');
        }
    }

    /**
     * TEMPORARILY DISABLED (business decision, not a bug) — the
     * "certificate must be generated before ReadyToClaim" rule is
     * intentionally off across every enforcement point that now shares
     * this single method: DocumentRequestService::updateRequest()
     * (whole-request — was already dead code before this rule existed),
     * guardCertificateGenerated() above (single-item), bulkAdvanceItems()
     * (Bulk Ready — see that method's docblock), and
     * RequestReleaseGroupService::claimReleaseGroup() (per-group, which
     * keeps its own independent copy of this same disabled check — see
     * that method's docblock). The frontend disabled its own copy of this
     * check first (see "TEMPORARILY DISABLED" in StaffDashboard.jsx's
     * handleBulkReadyClick()); this keeps the backend from being stricter
     * than the UI it's serving.
     *
     * generated_at itself is untouched — DocumentRequestService::
     * markCertificatesGenerated() still records it — so re-enabling this
     * rule later is a ONE-LINE change: uncomment the body below (and
     * remove the early `return null;`). Because every enforcement point
     * in this file now calls THIS method rather than re-implementing the
     * check, that one change takes effect for the single-item endpoint
     * and every bulk batch simultaneously, with no risk of one call site
     * being re-enabled while another is missed. RequestReleaseGroupService
     * keeps a separate copy (documented there) since it has no dependency
     * on this class today.
     *
     * @return string|null a machine-readable ineligibility reason (for
     *         bulkAdvanceItems()' items_skipped reporting), or null if
     *         the item is eligible.
     */
    private function certificateGeneratedIneligibilityReason(RequestCertificate $item, RequestStatusEnum $targetStatus): ?string
    {
        return null;

        // if ($targetStatus !== RequestStatusEnum::ReadyToClaim) {
        //     return null;
        // }
        //
        // if ($item->generated_at === null) {
        //     return 'certificate_not_generated';
        // }
        //
        // return null;
    }

    private function validateTransition(?int $currentStatusId, int $targetStatusId): RequestStatusEnum
    {
        // A NULL item status shouldn't be reachable in practice (the
        // migration backfills every existing row, and creation always
        // sets an initial status — see DocumentRequestService::
        // createRequest()), but if it's ever hit, treat it the same way
        // Processing behaves as a safe default rather than throwing an
        // opaque enum error.
        $currentStatus = RequestStatusEnum::from($currentStatusId ?? RequestStatusEnum::Processing->value);
        $targetStatus  = RequestStatusEnum::from($targetStatusId);

        if (!in_array($targetStatus, $currentStatus->allowedTransitions(), true)) {
            abort(422, "Transition from {$currentStatus->name} to {$targetStatus->name} is not allowed.");
        }

        return $targetStatus;
    }

    /**
     * Same fine-grained dashboard-action gate DocumentRequestService::
     * authorizeStatusChange() applies at the request level, reused
     * as-is for a single item's target status. See that method's
     * docblock for the full reasoning (Work Item #1 — Granular
     * Per-Action Permissions) — duplicated here rather than extracted
     * into a shared trait/base class, since the two callers take
     * different inputs ($validated array vs. a bare target status) and
     * a premature shared abstraction over two call sites isn't worth
     * the indirection yet.
     */
    private function authorizeItemStatusChange(RequestStatusEnum $targetStatus): void
    {
        $actor = Auth::user();

        if (!$actor instanceof SystemUser) {
            return;
        }

        $requiredAction = match ($targetStatus) {
            RequestStatusEnum::Completed => 'Complete',
            default                       => 'Process',
        };

        if (!$actor->hasModuleAccess('dashboard', $requiredAction)) {
            abort(403, "Your account's assigned policy does not grant the '{$requiredAction}' action on the dashboard module.");
        }
    }

    /**
     * Writes the per-item history row (request_document_id or
     * request_certificate_id set, never both — see migration
     * 2026_08_29_000007's docblock). Uses the same minutes_processed /
     * business_minutes calculation as DocumentRequestService::
     * recordStatusHistory(), scoped to THIS item's own history rows
     * rather than the whole request's, so an item's SLA segment timing
     * reflects only its own prior transitions.
     */
    private function recordItemHistory(
        DocumentRequest $documentRequest,
        ?int $oldStatusId,
        int $newStatusId,
        ?int $requestDocumentId = null,
        ?int $requestCertificateId = null,
    ): void {
        $minutesProcessed = (int) $documentRequest->requested_at->diffInMinutes(now());

        $segmentStart = RequestHistory::where('request_id', $documentRequest->request_id)
            ->when($requestDocumentId, fn ($q) => $q->where('request_document_id', $requestDocumentId))
            ->when($requestCertificateId, fn ($q) => $q->where('request_certificate_id', $requestCertificateId))
            ->orderByDesc('changed_at')
            ->orderByDesc('request_history_id')
            ->value('changed_at');

        $segmentStart = $segmentStart
            ? \Illuminate\Support\Carbon::parse($segmentStart)
            : $documentRequest->requested_at;

        $businessMinutes = $this->businessCalendarService->minutesBetween($segmentStart, now());

        RequestHistory::create([
            'request_id'             => $documentRequest->request_id,
            'request_document_id'    => $requestDocumentId,
            'request_certificate_id' => $requestCertificateId,
            'old_status_id'          => $oldStatusId,
            'new_status_id'          => $newStatusId,
            'changed_at'             => now(),
            'changed_by'             => Auth::id(),
            'minutes_processed'      => $minutesProcessed,
            'business_minutes'       => $businessMinutes,
        ]);
    }

    /**
     * Recomputes document_request.status_id as the "earliest-stage-wins"
     * aggregate of all its line items' statuses, and — only if that
     * changes the request's own status — writes a whole-request history
     * row (request_document_id/request_certificate_id both null, same
     * shape as every existing request-level transition) and notifies the
     * owner, exactly as DocumentRequestService::updateRequest() already
     * does for a direct request-level change. This keeps the two paths
     * indistinguishable from the student's point of view: they see the
     * same status field change and get the same notification either way,
     * regardless of whether it was caused by one item finishing or by a
     * whole-request update.
     *
     * A request with zero items (should not occur — store() always
     * creates at least one) is left untouched rather than guessed at.
     */
    private function recomputeAggregateStatus(DocumentRequest $documentRequest): void
    {
        // Defense in depth behind guardNotTerminal() and the bulk skip:
        // a request in a final status is never recomputed from its items.
        // A stale item row must not be able to move it backward, and must
        // not trigger a second notification.
        if ($this->isTerminalRequest($documentRequest)) {
            return;
        }

        $itemStatusIds = DB::table('request_document')
            ->where('request_id', $documentRequest->request_id)
            ->whereNotNull('status_id')
            ->pluck('status_id')
            ->merge(
                DB::table('request_certificate')
                    ->where('request_id', $documentRequest->request_id)
                    ->whereNotNull('status_id')
                    ->pluck('status_id')
            );

        if ($itemStatusIds->isEmpty()) {
            return;
        }

        $leastAdvancedStatusId = $itemStatusIds
            ->sortBy(fn (int $statusId) => self::STAGE_RANK[$statusId] ?? PHP_INT_MAX)
            ->first();

        if ((int) $leastAdvancedStatusId === (int) $documentRequest->status_id) {
            return;
        }

        $oldStatusId = $documentRequest->status_id;

        $documentRequest->update(['status_id' => $leastAdvancedStatusId]);

        $this->recordItemHistory($documentRequest, $oldStatusId, $leastAdvancedStatusId);
        $this->notifyOwnerOfStatusChange($documentRequest);
    }

    /**
     * Recomputes ONE release group's status_id as the "earliest-stage-
     * wins" aggregate of its own member items — same computation as
     * recomputeAggregateStatus() above, scoped to request_release_group_id
     * instead of request_id. Mirrors RequestReleaseGroupService::
     * recomputeParentAggregate()'s per-request version, at group
     * granularity.
     *
     * This closes a gap that predates bulk operations: assignReleaseGroups()
     * sets a group's initial status_id once, at creation, and
     * RequestReleaseGroupService::claimReleaseGroup() sets it again on
     * claim — but nothing previously kept a group's status_id in sync
     * as its individual member items advanced one at a time (via
     * advanceDocumentItem()/advanceCertificateItem()) or in a batch (via
     * bulkAdvanceItems()) in between those two points. A stale group
     * status_id doesn't affect claiming (claimReleaseGroup() only reads it
     * to decide the group's own eligibility, and student-facing status is
     * read from document_request, not from this table) but IS surfaced
     * directly by groupCompletionState(), so it must track reality.
     *
     * A group with zero (still) member items is left untouched rather
     * than guessed at, matching recomputeAggregateStatus()'s same
     * defensive choice for an empty request.
     */
    private function recomputeReleaseGroupAggregate(int $releaseGroupId): void
    {
        // lockForUpdate() here (rather than a plain find()) guards against
        // a concurrent RequestReleaseGroupService::claimReleaseGroup() call
        // racing this rollup for the same group — both callers now take a
        // row lock before reading/writing this group's status_id.
        $group = RequestReleaseGroup::lockForUpdate()->find($releaseGroupId);

        if (!$group) {
            return;
        }

        $memberStatusIds = DB::table('request_document')
            ->where('request_release_group_id', $releaseGroupId)
            ->whereNotNull('status_id')
            ->pluck('status_id')
            ->merge(
                DB::table('request_certificate')
                    ->where('request_release_group_id', $releaseGroupId)
                    ->whereNotNull('status_id')
                    ->pluck('status_id')
            );

        if ($memberStatusIds->isEmpty()) {
            return;
        }

        $leastAdvancedStatusId = $memberStatusIds
            ->sortBy(fn (int $statusId) => self::STAGE_RANK[$statusId] ?? PHP_INT_MAX)
            ->first();

        if ((int) $leastAdvancedStatusId === (int) $group->status_id) {
            return;
        }

        $group->update(['status_id' => $leastAdvancedStatusId]);
    }

    /**
     * Identical to DocumentRequestService::notifyOwnerOfStatusChange() —
     * duplicated rather than shared for the same reason
     * authorizeItemStatusChange() is: no third caller yet to justify
     * extracting a shared trait, and the two services are intentionally
     * decoupled (this one does not depend on DocumentRequestService, to
     * avoid a circular/awkward dependency now that DocumentRequestService
     * doesn't need to know about item-level status at all).
     */
    private function notifyOwnerOfStatusChange(DocumentRequest $documentRequest): void
    {
        $owner = SystemUser::find($documentRequest->user_id);
        if (!$owner) return;

        $status  = RequestStatusEnum::from((int) $documentRequest->status_id);
        $trigger = $status->notificationTrigger();

        if ($trigger) {
            $data = ['request_id' => $documentRequest->request_id];

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