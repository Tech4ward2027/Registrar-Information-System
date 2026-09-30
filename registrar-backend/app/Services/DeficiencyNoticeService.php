<?php

namespace App\Services;

use App\Enums\DeficiencyItemEnum;
use App\Enums\RequestStatusEnum;
use App\Models\DocumentRequest;
use App\Models\RequestCertificate;
use App\Models\RequestDocument;
use App\Models\RequestRemark;
use App\Models\SystemUser;
use App\Contracts\NotificationServiceInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Deficiency Notice & Withdrawn Status — Phase 3.
 *
 * Business logic for the Deficiency Notice lifecycle: issue → clear |
 * void. See the create_request_remarks_table migration for the schema
 * this operates on, and RequestRemark's docblock for the model this
 * service writes.
 *
 * SCOPE — a Deficiency Notice is a named, cleared/voidable HOLD, not a
 * status change: document_request.status_id is never written by this
 * service, in either direction. This is the fundamental difference from
 * DocumentRequestService::withdraw() (Phase 1), which IS a terminal
 * status transition. Whether a request currently has an open notice is
 * surfaced to staff/students entirely through this table and its own
 * notifications — the dashboard/detail-view banner (Phase 4) reads
 * DocumentRequest::openDeficiencyNotice() rather than inferring
 * anything from status_id.
 *
 * CONCURRENCY — mirrors DocumentRequestService's exact pattern
 * (DB::transaction() + lockForUpdate()). "One open notice per request"
 * is enforced by row-locking the PARENT document_request before
 * checking for an existing open remark (see the create_request_remarks_
 * table migration's docblock for why this is a service-level check
 * rather than a partial unique index — MySQL in production does not
 * support one, and the SQLite test suite would not agree on the
 * workaround anyway). Any two concurrent issue() calls for the same
 * request therefore serialize on that same row lock, exactly like two
 * concurrent DocumentRequestService::updateRequest()/withdraw() calls
 * already do.
 *
 * NOTIFICATIONS — fired AFTER the transaction commits, not inside it,
 * same reasoning DocumentRequestService::withdraw() documents: a
 * notification reaching a student for an action that later rolled back
 * would be actively confusing.
 *
 * VOID DOES NOT AUTO-TRANSITION THE PARENT — per the implementation
 * plan's Phase 3 goal, voiding is the "never resolved" escalation
 * outcome (student unreachable, deceased, etc.) but stays a manual
 * decision by staff whether to also withdraw the parent request
 * (DocumentRequestService::withdraw(), Phase 1) once they've reviewed
 * the case. See that plan's Phase 5 ("Void → Withdraw handoff") for the
 * still-outstanding cross-feature UI prompt tying the two actions
 * together — not implemented here, deliberately: Phase 3 is
 * intentionally scoped to Deficiency Notice alone.
 */
class DeficiencyNoticeService
{
    public function __construct(
        private NotificationServiceInterface $notificationService,
    ) {}

    /**
     * Issue a new Deficiency Notice against $documentRequest.
     *
     * Phase 5 - a notice has a SCOPE:
     *   - request-level: neither request_document_id nor
     *     request_certificate_id in $data (behaviour unchanged);
     *   - item-level: exactly one of them, naming a document/certificate
     *     that belongs to $documentRequest.
     * At most one OPEN notice per scope: one per item plus one request-level.
     * A request-level notice and item-level notices may coexist.
     *
     * LOCK ORDER - item first, then request. That is the order the item
     * claim (ItemClaimService) and the item Withdraw/Close
     * (RequestItemTerminationService) already use, so an issue racing a claim
     * of the same item serializes instead of deadlocking, and re-reads the
     * item's committed status once it gets the lock.
     *
     * @throws \Illuminate\Http\Exceptions\HttpResponseException on an
     *         archived or finished parent, an item that is not on this
     *         request or already finished, or an already-open notice in the
     *         same scope.
     */
    public function issue(DocumentRequest $documentRequest, array $data): RequestRemark
    {
        $documentId    = isset($data['request_document_id']) ? (int) $data['request_document_id'] : null;
        $certificateId = isset($data['request_certificate_id']) ? (int) $data['request_certificate_id'] : null;

        if ($documentId !== null && $certificateId !== null) {
            abort(422, 'A Deficiency Notice can target one document or one certificate, not both.');
        }

        $itemName = null;

        $remark = DB::transaction(function () use ($documentRequest, $data, $documentId, $certificateId, &$itemName) {
            $item = $this->lockItem((int) $documentRequest->request_id, $documentId, $certificateId);

            // Row-locking the parent is what actually makes the
            // "one open notice per scope" guard below race-free - see
            // this class's docblock and the create_request_remarks_
            // table migration's docblock for the full reasoning.
            $documentRequest = DocumentRequest::lockForUpdate()
                ->findOrFail($documentRequest->request_id);

            $this->guardArchived($documentRequest);
            $this->guardWithdrawn($documentRequest);

            if ($item !== null) {
                $this->guardRequestNotFinished($documentRequest);
                $this->guardItemCanBeHeld($item);
                $this->guardNoOpenItemNotice($item);
                $itemName = $this->itemName($item);
            } else {
                $this->guardNoOpenNotice($documentRequest);
            }

            $itemKey = DeficiencyItemEnum::from($data['item_key']);

            return RequestRemark::create([
                'request_id'             => $documentRequest->request_id,
                'request_document_id'    => $documentId,
                'request_certificate_id' => $certificateId,
                'remark_type'            => 'deficiency',
                'item_key'               => $itemKey->value,
                'item_label'             => $itemKey->label(),
                'detail'                 => $data['detail'] ?? null,
                'status'                 => RequestRemark::STATUS_OPEN,
                'issued_by'              => Auth::id(),
                'issued_at'              => now(),
            ]);
        });

        $this->notifyOwner($remark, 'deficiency_notice_issued', array_filter([
            'item_label' => $this->resolveItemLabelForNotification($remark),
            // Extra placeholder for item-level notices; templates that do
            // not use it simply ignore it.
            'item_name'  => $itemName,
        ], fn ($v) => $v !== null));

        return $remark->refresh();
    }

    /**
     * Clear an open Deficiency Notice — staff confirming the flagged
     * item was received. Does not touch document_request.status_id;
     * processing simply resumes because the hold is gone.
     *
     * @throws \Illuminate\Http\Exceptions\HttpResponseException if the
     *         notice is not currently open, or its parent request is
     *         archived.
     */
    public function clear(RequestRemark $remark): RequestRemark
    {
        $remark = DB::transaction(function () use ($remark) {
            /** @var RequestRemark $remark */
            $remark = RequestRemark::lockForUpdate()->findOrFail($remark->remark_id);

            $this->guardOpen($remark);
            $this->guardParentArchived($remark);

            $remark->update([
                'status'     => RequestRemark::STATUS_CLEARED,
                'cleared_by' => Auth::id(),
                'cleared_at' => now(),
            ]);

            return $remark;
        });

        $this->notifyOwner($remark, 'deficiency_notice_cleared');

        return $remark->refresh();
    }

    /**
     * Void an open Deficiency Notice — the "never resolved" escalation
     * outcome. Requires void_reason. Does NOT auto-transition the
     * parent request's status — see this class's docblock.
     *
     * @throws \Illuminate\Http\Exceptions\HttpResponseException if the
     *         notice is not currently open, or its parent request is
     *         archived.
     */
    public function void(RequestRemark $remark, array $data): RequestRemark
    {
        $remark = DB::transaction(function () use ($remark, $data) {
            /** @var RequestRemark $remark */
            $remark = RequestRemark::lockForUpdate()->findOrFail($remark->remark_id);

            $this->guardOpen($remark);
            $this->guardParentArchived($remark);

            $remark->update([
                'status'      => RequestRemark::STATUS_VOIDED,
                'voided_by'   => Auth::id(),
                'voided_at'   => now(),
                'void_reason' => $data['void_reason'],
            ]);

            return $remark;
        });

        $this->notifyOwner($remark, 'deficiency_notice_voided', [
            'void_reason' => $remark->void_reason,
        ]);

        return $remark->refresh();
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private function guardArchived(DocumentRequest $documentRequest): void
    {
        // Same "archived records are read-only" rule
        // DocumentRequestService/RequestItemStatusService already
        // enforce everywhere else. Restoring must happen first via
        // DocumentRequestService::restoreRequest().
        if ($documentRequest->is_archived) {
            abort(422, 'This request is archived and is read-only. Restore it first.');
        }
    }

    /**
     * A Withdrawn request will never be fulfilled, so flagging a
     * missing item on it is meaningless — Withdrawn is a terminal
     * status (RequestStatusEnum::allowedTransitions()) and there is
     * nothing left to hold up. This is the reverse-direction guard to
     * the TODO left in DocumentRequestService::withdraw() (see that
     * method's docblock): that TODO covers withdrawing a request that
     * already HAS an open notice (deferred to this feature's Phase 5),
     * whereas this guard covers the opposite order — issuing a NEW
     * notice against a request that is already Withdrawn.
     */
    private function guardWithdrawn(DocumentRequest $documentRequest): void
    {
        if ((int) $documentRequest->status_id === RequestStatusEnum::Withdrawn->value) {
            abort(422, 'This request has been withdrawn and cannot receive a new Deficiency Notice.');
        }
    }

    /** Request-level scope only: item-level notices do not count here. */
    private function guardNoOpenNotice(DocumentRequest $documentRequest): void
    {
        $hasOpenNotice = RequestRemark::where('request_id', $documentRequest->request_id)
            ->open()
            ->requestLevel()
            ->exists();

        if ($hasOpenNotice) {
            abort(422, 'An open Deficiency Notice already exists for this request. Clear or void it first.');
        }
    }

    private function guardNoOpenItemNotice(RequestDocument|RequestCertificate $item): void
    {
        $column = $item instanceof RequestDocument ? 'request_document_id' : 'request_certificate_id';

        $hasOpenNotice = RequestRemark::where($column, $item->getKey())->open()->exists();

        if ($hasOpenNotice) {
            abort(422, 'An open Deficiency Notice already exists for this item. Clear or void it first.');
        }
    }

    /**
     * Item-level notices only make sense while the request can still move.
     * (A request-level notice keeps the older, Withdrawn-only rule above.)
     */
    private function guardRequestNotFinished(DocumentRequest $documentRequest): void
    {
        $status = RequestStatusEnum::tryFrom((int) $documentRequest->status_id);

        if ($status !== null && $status->isTerminal()) {
            abort(422, 'This request is already finished and cannot receive a new Deficiency Notice.');
        }
    }

    /**
     * A finished item (claimed, forfeited, withdrawn, closed) has nothing left
     * to hold. Ready to Claim IS holdable: that is the case where the counter
     * finds a problem just before release.
     */
    private function guardItemCanBeHeld(RequestDocument|RequestCertificate $item): void
    {
        $status = RequestStatusEnum::tryFrom((int) ($item->status_id ?? RequestStatusEnum::Processing->value));

        $holdable = [
            RequestStatusEnum::AwaitingSubmission,
            RequestStatusEnum::Processing,
            RequestStatusEnum::PendingSignature,
            RequestStatusEnum::ReadyToClaim,
        ];

        if ($status === null || !in_array($status, $holdable, true)) {
            abort(422, 'This item is already finished and cannot receive a new Deficiency Notice.');
        }
    }

    /**
     * Locks the targeted item (or returns null for a request-level notice)
     * and proves it belongs to the request named in the URL - the client
     * supplies the item id, so this is the authorization boundary that stops
     * a notice being attached to another request's item.
     */
    private function lockItem(int $requestId, ?int $documentId, ?int $certificateId): RequestDocument|RequestCertificate|null
    {
        if ($documentId === null && $certificateId === null) {
            return null;
        }

        $item = $documentId !== null
            ? RequestDocument::lockForUpdate()->find($documentId)
            : RequestCertificate::lockForUpdate()->find($certificateId);

        if (!$item || (int) $item->request_id !== $requestId) {
            abort(422, 'The selected document does not belong to this request.');
        }

        return $item;
    }

    private function itemName(RequestDocument|RequestCertificate $item): ?string
    {
        return $item instanceof RequestDocument
            ? $item->documentType?->document_name
            : $item->certificationType?->certificate_name;
    }

    private function guardOpen(RequestRemark $remark): void
    {
        if (!$remark->isOpen()) {
            abort(422, 'This Deficiency Notice has already been resolved.');
        }
    }

    /**
     * Same "archived records are read-only" rule as guardArchived()
     * above, applied via the remark's parent — a notice's parent
     * request can become archived AFTER the notice was issued (archiving
     * is not blocked by an open notice today), so clear()/void() must
     * re-check it independently rather than trusting issue()'s
     * point-in-time guard.
     */
    private function guardParentArchived(RequestRemark $remark): void
    {
        $documentRequest = DocumentRequest::withArchived()
            ->find($remark->request_id);

        if ($documentRequest && $documentRequest->is_archived) {
            abort(422, 'This request is archived and is read-only. Restore it first.');
        }
    }

    /**
     * For Other, substitutes the staff-entered detail free text instead
     * of the generic "Other" label — identical rule
     * DocumentRequestService::withdraw() applies for :withdrawal_reason
     * when WithdrawalReasonEnum::Other is chosen.
     */
    private function resolveItemLabelForNotification(RequestRemark $remark): string
    {
        if ($remark->item_key === DeficiencyItemEnum::Other->value) {
            return $remark->detail ?: DeficiencyItemEnum::Other->label();
        }

        return $remark->item_label;
    }

    private function notifyOwner(RequestRemark $remark, string $triggerEvent, array $data = []): void
    {
        $documentRequest = DocumentRequest::withArchived()->find($remark->request_id);
        if (!$documentRequest) {
            return;
        }

        $owner = SystemUser::find($documentRequest->user_id);
        if (!$owner) {
            return;
        }

        $this->notificationService->send(
            recipient:    $owner,
            triggerEvent: $triggerEvent,
            data:         array_merge(['request_id' => $documentRequest->request_id], $data),
            requestId:    $documentRequest->request_id,
        );
    }
}