<?php

namespace App\Services;

use App\Enums\DeficiencyItemEnum;
use App\Enums\RequestStatusEnum;
use App\Models\DocumentRequest;
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
     * @throws \Illuminate\Http\Exceptions\HttpResponseException on an
     *         archived or Withdrawn parent, or an already-open notice
     *         on the same request.
     */
    public function issue(DocumentRequest $documentRequest, array $data): RequestRemark
    {
        $remark = DB::transaction(function () use ($documentRequest, $data) {
            // Row-locking the parent is what actually makes the
            // "one open notice at a time" guard below race-free — see
            // this class's docblock and the create_request_remarks_
            // table migration's docblock for the full reasoning.
            $documentRequest = DocumentRequest::lockForUpdate()
                ->findOrFail($documentRequest->request_id);

            $this->guardArchived($documentRequest);
            $this->guardWithdrawn($documentRequest);
            $this->guardNoOpenNotice($documentRequest);

            $itemKey = DeficiencyItemEnum::from($data['item_key']);

            return RequestRemark::create([
                'request_id'  => $documentRequest->request_id,
                'remark_type' => 'deficiency',
                'item_key'    => $itemKey->value,
                'item_label'  => $itemKey->label(),
                'detail'      => $data['detail'] ?? null,
                'status'      => RequestRemark::STATUS_OPEN,
                'issued_by'   => Auth::id(),
                'issued_at'   => now(),
            ]);
        });

        $this->notifyOwner($remark, 'deficiency_notice_issued', [
            'item_label' => $this->resolveItemLabelForNotification($remark),
        ]);

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

    private function guardNoOpenNotice(DocumentRequest $documentRequest): void
    {
        $hasOpenNotice = RequestRemark::where('request_id', $documentRequest->request_id)
            ->where('status', RequestRemark::STATUS_OPEN)
            ->exists();

        if ($hasOpenNotice) {
            abort(422, 'An open Deficiency Notice already exists for this request. Clear or void it first.');
        }
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
