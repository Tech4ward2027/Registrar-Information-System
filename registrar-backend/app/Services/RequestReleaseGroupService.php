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
 * Phase 3 — claiming grouped by fulfillment_track. See migration
 * 2026_08_29_000008_add_fulfillment_tracks_and_release_groups for the
 * schema this operates on.
 *
 * Two responsibilities:
 *   1. assignReleaseGroups() — called once, right after a request's line
 *      items are created, to split them into per-track groups IF (and
 *      only if) they actually span more than one track. Single-track
 *      requests (the common case) get no groups at all and keep using
 *      the request-level uuid/claim_code exactly as before.
 *   2. claimReleaseGroup() — the per-group counterpart to
 *      DocumentRequestService::claimRequest(): scans a group's own
 *      uuid/claim_code, completes only that group's items, and leaves
 *      every other group/item on the request untouched.
 */
class RequestReleaseGroupService
{
    use FlushesAnalyticsCache;

    /** Mirrors RequestItemStatusService::STAGE_RANK — see that class's
     * docblock for the full reasoning. Duplicated rather than shared for
     * the same "no third caller yet" reason given there. */
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

    // -------------------------------------------------------------------------
    // Assignment (called once, right after item creation)
    // -------------------------------------------------------------------------

    /**
     * Groups a freshly-created request's items by fulfillment_track_id.
     * A NULL track counts as its own bucket (the "standard" track) —
     * so a request mixing one explicit-track item with one standard
     * item still gets two groups, not one.
     *
     * Does nothing (creates zero rows) when every item shares the same
     * bucket — including the common case where every item has a NULL
     * track. This keeps the request-level uuid/claim_code the ONLY
     * ticket for the overwhelming majority of requests.
     *
     * Must be called AFTER each request_document/request_certificate row
     * already has its own status_id set (see DocumentRequestService::
     * createRequest()) — a group's initial status is the earliest-
     * stage-wins aggregate of its members' statuses at the moment of
     * creation, same computation RequestItemStatusService uses later.
     */
    public function assignReleaseGroups(DocumentRequest $documentRequest): void
    {
        $documentRequest->loadMissing(['documents.documentType', 'certificates.certificationType']);

        $buckets = []; // track_id (or 'standard') => ['documents' => [...], 'certificates' => [...]]

        foreach ($documentRequest->documents as $item) {
            $trackId = $item->documentType?->fulfillment_track_id;
            $key = $trackId ?? 'standard';
            $buckets[$key]['track_id'] ??= $trackId;
            $buckets[$key]['documents'][] = $item;
        }

        foreach ($documentRequest->certificates as $item) {
            $trackId = $item->certificationType?->fulfillment_track_id;
            $key = $trackId ?? 'standard';
            $buckets[$key]['track_id'] ??= $trackId;
            $buckets[$key]['certificates'][] = $item;
        }

        if (count($buckets) < 2) {
            return; // single track (or no items at all) — no groups needed
        }

        foreach ($buckets as $bucket) {
            $memberDocuments   = $bucket['documents'] ?? [];
            $memberCertificates = $bucket['certificates'] ?? [];

            $memberStatusIds = collect($memberDocuments)->pluck('status_id')
                ->merge(collect($memberCertificates)->pluck('status_id'))
                ->filter();

            $groupStatusId = $memberStatusIds
                ->sortBy(fn (int $statusId) => self::STAGE_RANK[$statusId] ?? PHP_INT_MAX)
                ->first() ?? RequestStatusEnum::Processing->value;

            $group = RequestReleaseGroup::create([
                'request_id'            => $documentRequest->request_id,
                'fulfillment_track_id'  => $bucket['track_id'],
                'status_id'             => $groupStatusId,
            ]);

            foreach ($memberDocuments as $item) {
                $item->update(['request_release_group_id' => $group->request_release_group_id]);
            }
            foreach ($memberCertificates as $item) {
                $item->update(['request_release_group_id' => $group->request_release_group_id]);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Claiming
    // -------------------------------------------------------------------------

    /**
     * Plain lookup, no lock — mirrors DocumentRequestService::
     * claimRequest()'s own lookup step. Used by DocumentRequestService to
     * decide whether an incoming credential is a group ticket before
     * falling back to the request-level ticket.
     */
    public function findByCredential(array $credential): ?RequestReleaseGroup
    {
        return RequestReleaseGroup::query()
            ->when(isset($credential['uuid']), fn ($q) => $q->where('uuid', $credential['uuid']))
            ->when(isset($credential['claim_code']), fn ($q) => $q->where('claim_code', $credential['claim_code']))
            ->first();
    }

    /**
     * Completes every item in one release group, leaving every other
     * group/item on the parent request untouched. Returns the PARENT
     * DocumentRequest (refreshed) — same return contract as
     * DocumentRequestService::claimRequest(), so the controller doesn't
     * need a different response shape depending on which ticket was
     * scanned.
     */
    public function claimReleaseGroup(RequestReleaseGroup $group): DocumentRequest
    {
        return DB::transaction(function () use ($group) {
            $group = RequestReleaseGroup::lockForUpdate()
                ->findOrFail($group->request_release_group_id);

            $documentRequest = DocumentRequest::lockForUpdate()
                ->findOrFail($group->request_id);

            if ($documentRequest->is_archived) {
                abort(422, 'This request is archived and is read-only. Restore it first.');
            }

            // A request that already reached its final outcome cannot
            // have a ticket claimed against it. Without this, a group
            // row left stale under a Completed/Forfeited/Withdrawn parent
            // could be "claimed" here and drag the parent back through
            // recomputeParentAggregate(), re-notifying the student.
            $parentStatus = RequestStatusEnum::tryFrom((int) $documentRequest->status_id);
            if ($parentStatus !== null && $parentStatus->isTerminal()) {
                $label = trim(preg_replace('/(?<!^)[A-Z]/', ' $0', $parentStatus->name));
                abort(422, "This request is already {$label}, so this ticket can no longer be claimed.");
            }

            $currentStatus = RequestStatusEnum::from((int) $group->status_id);
            $targetStatus  = RequestStatusEnum::Completed;

            if (!in_array($targetStatus, $currentStatus->allowedTransitions(), true)) {
                abort(422, "This ticket's items are not ready to claim (currently {$currentStatus->name}).");
            }

            // TEMPORARILY DISABLED (business decision, not a bug) — see
            // RequestItemStatusService::guardCertificateGenerated()'s
            // docblock for the full reasoning: this guard is off across
            // all three enforcement points (whole-request, per-item,
            // per-group) to match the frontend, which disabled its own
            // copy first. generated_at is still recorded normally by
            // DocumentRequestService::markCertificatesGenerated() —
            // re-enabling later just means restoring the abort() below.
            $groupCertificates = RequestCertificate::where('request_release_group_id', $group->request_release_group_id)->get();
            // if ($groupCertificates->isNotEmpty() && $groupCertificates->whereNotNull('generated_at')->isEmpty()) {
            //     abort(422, 'Certificate must be generated before marking as Ready to Claim.');
            // }

            $groupDocuments = RequestDocument::where('request_release_group_id', $group->request_release_group_id)->get();

            foreach ($groupDocuments as $item) {
                $oldStatusId = $item->status_id;
                $item->update(['status_id' => $targetStatus->value]);
                $this->recordHistory($documentRequest, $oldStatusId, $targetStatus->value, requestDocumentId: $item->request_document_id);
            }

            foreach ($groupCertificates as $item) {
                $oldStatusId = $item->status_id;
                $item->update(['status_id' => $targetStatus->value]);
                $this->recordHistory($documentRequest, $oldStatusId, $targetStatus->value, requestCertificateId: $item->request_certificate_id);
            }

            $group->update(['status_id' => $targetStatus->value]);

            $this->recomputeParentAggregate($documentRequest);

            $this->flushAnalyticsCache();

            return $documentRequest->refresh();
        });
    }

    // -------------------------------------------------------------------------
    // Internal helpers — mirror RequestItemStatusService's private methods.
    // Duplicated rather than shared: see that class's docblock for why a
    // premature shared abstraction across two call sites isn't worth the
    // indirection yet.
    // -------------------------------------------------------------------------

    private function recordHistory(
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
     * Recomputes document_request.status_id across ALL of its items
     * (every group, plus any ungrouped items) after one group finishes
     * — the parent only reaches Completed once every group has. Mirrors
     * RequestItemStatusService::recomputeAggregateStatus() exactly.
     */
    private function recomputeParentAggregate(DocumentRequest $documentRequest): void
    {
        // Same rule as RequestItemStatusService::recomputeAggregateStatus():
        // a request in a final status is never recomputed from its items.
        $parentStatus = RequestStatusEnum::tryFrom((int) $documentRequest->status_id);
        if ($parentStatus !== null && $parentStatus->isTerminal()) {
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

        $leastAdvancedStatusId = RequestAggregateStatus::resolve($itemStatusIds);

        if ($leastAdvancedStatusId === null || $leastAdvancedStatusId === (int) $documentRequest->status_id) {
            return;
        }

        $oldStatusId = $documentRequest->status_id;
        $documentRequest->update(['status_id' => $leastAdvancedStatusId]);

        $this->recordHistory($documentRequest, $oldStatusId, $leastAdvancedStatusId);

        // See RequestItemStatusService::recomputeAggregateStatus(): a
        // Withdrawn / Closed parent is announced with its reason elsewhere.
        if (!RequestAggregateStatus::hasLeftRequest($leastAdvancedStatusId)) {
            $this->notifyOwnerOfStatusChange($documentRequest);
        }
    }

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

    /**
     * Whether this request has any release groups at all, and whether
     * every one of them has already been individually claimed
     * (Completed). Used by DocumentRequestService::claimRequest() to
     * decide whether the request-level ticket is still usable.
     *
     * @return array{hasGroups: bool, allCompleted: bool}
     */
    public function groupCompletionState(DocumentRequest $documentRequest): array
    {
        $statuses = RequestReleaseGroup::where('request_id', $documentRequest->request_id)
            ->pluck('status_id');

        if ($statuses->isEmpty()) {
            return ['hasGroups' => false, 'allCompleted' => true];
        }

        $allCompleted = $statuses->every(fn ($statusId) => (int) $statusId === RequestStatusEnum::Completed->value);

        return ['hasGroups' => true, 'allCompleted' => $allCompleted];
    }
}