<?php

namespace App\Services;

use App\Contracts\NotificationServiceInterface;
use App\Enums\ClosureReasonEnum;
use App\Enums\RequestStatusEnum;
use App\Enums\WithdrawalReasonEnum;
use App\Models\DocumentRequest;
use App\Models\RequestCertificate;
use App\Models\RequestDocument;
use App\Models\RequestHistory;
use App\Models\RequestItemTermination;
use App\Models\RequestRemark;
use App\Models\SystemUser;
use App\Services\Concerns\FlushesAnalyticsCache;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Withdraw or Close ONE document/certificate without touching the other
 * items on the request, and the whole-request cascade that keeps items in
 * step when a request is withdrawn or closed as a whole.
 *
 * RULES
 *  - An item can only leave the request from the same statuses a request
 *    can (RequestStatusEnum::allowedTransitions()): AwaitingSubmission,
 *    Processing, PendingSignature. Ready to Claim, Completed and Forfeited
 *    items are final for this purpose, exactly as at request level.
 *  - Archived or already-final requests are refused (422).
 *  - Closing an item follows the request-level rule: the request must have
 *    an OPEN Deficiency Notice. (Phase 5 narrows this to a notice on that
 *    item; the check lives in assertCloseAllowed() so that is one edit.)
 *  - The parent status is never written here directly for a live request.
 *    It is recomputed through RequestAggregateStatus. Only when EVERY item
 *    has left does the parent become Withdrawn / Closed, and then the
 *    request-level reason columns are filled from the latest item reason
 *    so existing request-level reports and notifications keep working.
 *  - Nothing here reads or writes the Official Receipt number or fees.
 *
 * TRANSACTION AND LOCKS
 *  Item first, then the request (the same order as the item Done button and
 *  ItemClaimService). Notifications go out after the commit so a rolled
 *  back change is never announced.
 */
class RequestItemTerminationService
{
    use FlushesAnalyticsCache;

    /** Item statuses an item may leave the request from (and a whole-request cascade may move). */
    private const OPEN_STATUS_IDS = [
        RequestStatusEnum::AwaitingSubmission->value,
        RequestStatusEnum::Processing->value,
        RequestStatusEnum::PendingSignature->value,
    ];

    public function __construct(
        private NotificationServiceInterface $notifications,
        private RequestItemStatusService     $itemStatus,
    ) {}

    // -------------------------------------------------------------------------
    // Single item
    // -------------------------------------------------------------------------

    /**
     * @param array{withdrawal_reason: string, withdrawal_detail?: string|null} $data
     * @return array{item: RequestDocument|RequestCertificate, request: DocumentRequest, request_left: bool, auto_voided_deficiency_notice_id: int|null}
     */
    public function withdrawItem(RequestDocument|RequestCertificate $item, array $data): array
    {
        return $this->terminateOne($item, RequestStatusEnum::Withdrawn, [
            'reason' => $data['withdrawal_reason'],
            'detail' => $data['withdrawal_detail'] ?? null,
            'proof'  => null,
        ]);
    }

    /**
     * @param array{closure_reason: string, closure_detail?: string|null, closure_proof_reference: string} $data
     * @return array{item: RequestDocument|RequestCertificate, request: DocumentRequest, request_left: bool, auto_voided_deficiency_notice_id: int|null}
     */
    public function closeItem(RequestDocument|RequestCertificate $item, array $data): array
    {
        return $this->terminateOne($item, RequestStatusEnum::ClosedUnableToProcess, [
            'reason' => $data['closure_reason'],
            'detail' => $data['closure_detail'] ?? null,
            'proof'  => $data['closure_proof_reference'],
        ]);
    }

    /**
     * @param array{reason: string, detail: string|null, proof: string|null} $payload
     */
    private function terminateOne(RequestDocument|RequestCertificate $item, RequestStatusEnum $target, array $payload): array
    {
        $this->authorizeProcess();

        $isDocument = $item instanceof RequestDocument;
        $kind       = $target === RequestStatusEnum::Withdrawn
            ? RequestItemTermination::KIND_WITHDRAWN
            : RequestItemTermination::KIND_CLOSED;

        $outcome = DB::transaction(function () use ($item, $target, $payload, $isDocument, $kind) {
            $locked = $item->newQuery()->lockForUpdate()->findOrFail($item->getKey());

            // withArchived(): the default scope would hide an archived
            // request and turn the archive rule into a confusing 404.
            /** @var DocumentRequest $request */
            $request = DocumentRequest::withArchived()->lockForUpdate()->findOrFail($locked->request_id);

            $this->guardRequestOpen($request);

            $current = RequestStatusEnum::from((int) ($locked->status_id ?? RequestStatusEnum::Processing->value));

            if (!in_array($target, $current->allowedTransitions(), true)) {
                abort(422, sprintf(
                    'This item is %s and cannot be %s.',
                    self::label($current),
                    $target === RequestStatusEnum::Withdrawn ? 'withdrawn' : 'closed'
                ));
            }

            if ($target === RequestStatusEnum::ClosedUnableToProcess) {
                $this->assertCloseAllowed($request, $locked);
            }

            $oldStatusId = $current->value;
            $locked->update(['status_id' => $target->value]);

            RequestItemTermination::create([
                'request_id'             => $request->request_id,
                'request_document_id'    => $isDocument ? $locked->getKey() : null,
                'request_certificate_id' => $isDocument ? null : $locked->getKey(),
                'kind'                   => $kind,
                'reason'                 => $payload['reason'],
                'detail'                 => $payload['detail'],
                'proof_reference'        => $payload['proof'],
                'cascaded'               => false,
                'acted_by'               => Auth::id(),
                'acted_at'               => now(),
            ]);

            $this->itemStatus->recordItemHistory(
                $request,
                $oldStatusId,
                $target->value,
                requestDocumentId:    $isDocument ? (int) $locked->getKey() : null,
                requestCertificateId: $isDocument ? null : (int) $locked->getKey(),
            );

            $oldParentId = (int) $request->status_id;

            $this->itemStatus->recomputeAggregateStatus($request);

            if ($locked->request_release_group_id !== null) {
                $this->itemStatus->recomputeReleaseGroupAggregate((int) $locked->request_release_group_id);
            }

            $newParent   = RequestStatusEnum::from((int) $request->status_id);
            $requestLeft = $newParent->value !== $oldParentId
                && in_array($newParent, [RequestStatusEnum::Withdrawn, RequestStatusEnum::ClosedUnableToProcess], true);

            $voidedNoticeId = null;

            if ($requestLeft) {
                $this->syncParentTermination($request, $newParent);
                $voidedNoticeId = $this->voidOpenNotice($request, $newParent);
            }

            return [
                'item'         => $locked,
                'request'      => $request,
                'request_left' => $requestLeft,
                'voided'       => $voidedNoticeId,
            ];
        });

        $this->notifyAfterCommit($outcome['request'], $outcome['item'], $target, $outcome['request_left']);
        $this->flushAnalyticsCache();

        return [
            'item'                              => $outcome['item']->refresh(),
            'request'                           => $outcome['request']->refresh(),
            'request_left'                      => $outcome['request_left'],
            'auto_voided_deficiency_notice_id'  => $outcome['voided'],
        ];
    }

    // -------------------------------------------------------------------------
    // Whole-request cascade (called from DocumentRequestService)
    // -------------------------------------------------------------------------

    /**
     * Moves every still-open item of a request to Withdrawn / Closed after
     * a whole-request withdraw or close, and records why on each item.
     *
     * CONTRACT: the caller has already locked the parent request and is
     * inside its transaction. Refuses (422, rolling the caller back) when
     * an item is already Ready to Claim, Completed or Forfeited: those
     * cannot leave the request, and silently marking the parent Withdrawn
     * over them would contradict the items. Staff withdraw or close the
     * remaining items one by one instead.
     *
     * Items that already left the request individually are left alone.
     * Per-item history rows carry business_minutes = NULL, like every
     * other cascade: the request-level row written by the caller holds
     * the timing, and timing each item too would count one segment
     * several times.
     *
     * @param array{reason: string, detail: string|null, proof: string|null} $payload
     * @return array{items: int}
     */
    public function cascadeFromRequest(
        DocumentRequest $request,
        RequestStatusEnum $target,
        array $payload,
        ?int $actorId,
    ): array {
        $documents    = RequestDocument::where('request_id', $request->request_id)->lockForUpdate()->get();
        $certificates = RequestCertificate::where('request_id', $request->request_id)->lockForUpdate()->get();
        $items        = $documents->concat($certificates);

        $blocking = $items->filter(fn ($item) => in_array(
            (int) $item->status_id,
            [
                RequestStatusEnum::ReadyToClaim->value,
                RequestStatusEnum::Completed->value,
                RequestStatusEnum::Forfeited->value,
            ],
            true
        ));

        if ($blocking->isNotEmpty()) {
            abort(422, sprintf(
                '%d item(s) on this request are already Ready to Claim or released and cannot be %s with the request. '
                . 'Withdraw or close the remaining items individually instead.',
                $blocking->count(),
                $target === RequestStatusEnum::Withdrawn ? 'withdrawn' : 'closed'
            ));
        }

        $kind   = $target === RequestStatusEnum::Withdrawn
            ? RequestItemTermination::KIND_WITHDRAWN
            : RequestItemTermination::KIND_CLOSED;
        $moved  = 0;
        $groups = [];

        foreach ($items as $item) {
            $statusId = (int) ($item->status_id ?? RequestStatusEnum::Processing->value);

            if (!in_array($statusId, self::OPEN_STATUS_IDS, true)) {
                continue; // already Withdrawn / Closed on its own
            }

            $isDocument = $item instanceof RequestDocument;

            $item->update(['status_id' => $target->value]);

            RequestItemTermination::create([
                'request_id'             => $request->request_id,
                'request_document_id'    => $isDocument ? $item->getKey() : null,
                'request_certificate_id' => $isDocument ? null : $item->getKey(),
                'kind'                   => $kind,
                'reason'                 => $payload['reason'],
                'detail'                 => $payload['detail'],
                'proof_reference'        => $payload['proof'],
                'cascaded'               => true,
                'acted_by'               => $actorId ?? Auth::id(),
                'acted_at'               => now(),
            ]);

            RequestHistory::create([
                'request_id'             => $request->request_id,
                'request_document_id'    => $isDocument ? $item->getKey() : null,
                'request_certificate_id' => $isDocument ? null : $item->getKey(),
                'old_status_id'          => $statusId,
                'new_status_id'          => $target->value,
                'changed_at'             => now(),
                'changed_by'             => $actorId ?? Auth::id(),
                'minutes_processed'      => (int) $request->requested_at->diffInMinutes(now()),
                'business_minutes'       => null,
            ]);

            if ($item->request_release_group_id !== null) {
                $groups[(int) $item->request_release_group_id] = true;
            }

            $moved++;
        }

        foreach (array_keys($groups) as $groupId) {
            $this->itemStatus->recomputeReleaseGroupAggregate($groupId);
        }

        return ['items' => $moved];
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    private function authorizeProcess(): void
    {
        $actor = Auth::user();

        if ($actor instanceof SystemUser && !$actor->hasModuleAccess('dashboard', 'Process')) {
            abort(403, "Your account's assigned policy does not grant the 'Process' action on the dashboard module.");
        }
    }

    private function guardRequestOpen(DocumentRequest $request): void
    {
        if ($request->is_archived) {
            abort(422, 'This request is archived and is read-only. Restore it first.');
        }

        $status = RequestStatusEnum::tryFrom((int) $request->status_id);

        if ($status !== null && $status->isTerminal()) {
            abort(422, 'This request is already ' . self::label($status) . ', so its items can no longer be changed.');
        }
    }

    /**
     * Closing one item follows the request-level rule (an OPEN Deficiency
     * Notice must exist). Phase 5 replaces this body with a check for an
     * open notice on THIS item, when notices become per item.
     */
    private function assertCloseAllowed(DocumentRequest $request, RequestDocument|RequestCertificate $item): void
    {
        $hasOpenNotice = RequestRemark::where('request_id', $request->request_id)
            ->where('status', RequestRemark::STATUS_OPEN)
            ->lockForUpdate()
            ->exists();

        if (!$hasOpenNotice) {
            abort(422, 'This request has no open Deficiency Notice. Closed - Unable to Process only applies to a request whose open notice cannot be resolved.');
        }
    }

    /**
     * When the LAST item leaves, copy the latest matching item reason onto
     * the request's own reason columns. Reports and the request-level
     * notifications read those columns, so they keep working unchanged.
     */
    private function syncParentTermination(DocumentRequest $request, RequestStatusEnum $parent): void
    {
        $kind = $parent === RequestStatusEnum::Withdrawn
            ? RequestItemTermination::KIND_WITHDRAWN
            : RequestItemTermination::KIND_CLOSED;

        $latest = RequestItemTermination::where('request_id', $request->request_id)
            ->where('kind', $kind)
            ->orderByDesc('termination_id')
            ->first();

        if (!$latest) {
            return;
        }

        if ($parent === RequestStatusEnum::Withdrawn) {
            $request->update([
                'withdrawal_reason' => $latest->reason,
                'withdrawal_detail' => $latest->detail,
            ]);

            return;
        }

        $request->update([
            'closure_reason'          => $latest->reason,
            'closure_detail'          => $latest->detail,
            'closure_proof_reference' => $latest->proof_reference,
            'closed_by'               => $latest->acted_by,
            'closed_at'               => $latest->acted_at,
        ]);
    }

    /**
     * Same cascade the whole-request withdraw/close performs: once the
     * request itself is finished, its open notice can no longer stay open.
     * Inlined, and notification-free, for the reason documented in
     * DocumentRequestService::withdraw() (no message from inside a
     * transaction that could still roll back).
     */
    private function voidOpenNotice(DocumentRequest $request, RequestStatusEnum $parent): ?int
    {
        $remark = RequestRemark::where('request_id', $request->request_id)
            ->where('status', RequestRemark::STATUS_OPEN)
            ->lockForUpdate()
            ->first();

        if (!$remark) {
            return null;
        }

        $remark->update([
            'status'      => RequestRemark::STATUS_VOIDED,
            'voided_by'   => Auth::id(),
            'voided_at'   => now(),
            'void_reason' => $parent === RequestStatusEnum::Withdrawn
                ? 'Automatically voided - every item on the request was withdrawn.'
                : 'Request closed - Unable to Process: every item on the request left the request.',
        ]);

        return (int) $remark->remark_id;
    }

    /**
     * One notification per action. If the request itself just ended, the
     * existing request-level notification is sent; otherwise a per-item one.
     */
    private function notifyAfterCommit(
        DocumentRequest $request,
        RequestDocument|RequestCertificate $item,
        RequestStatusEnum $target,
        bool $requestLeft,
    ): void {
        $owner = SystemUser::find($request->user_id);

        if (!$owner) {
            return;
        }

        if ($requestLeft) {
            if ((int) $request->status_id === RequestStatusEnum::Withdrawn->value) {
                $this->notifications->send(
                    recipient:    $owner,
                    triggerEvent: RequestStatusEnum::Withdrawn->notificationTrigger(),
                    data:         [
                        'request_id'        => $request->request_id,
                        'withdrawal_reason' => $this->withdrawalText($request->withdrawal_reason, $request->withdrawal_detail),
                    ],
                    requestId:    $request->request_id,
                );

                return;
            }

            $this->notifications->send(
                recipient:    $owner,
                triggerEvent: RequestStatusEnum::ClosedUnableToProcess->notificationTrigger(),
                data:         [
                    'request_id'     => $request->request_id,
                    'closure_reason' => $this->closureText($request->closure_reason, $request->closure_detail),
                ],
                requestId:    $request->request_id,
            );

            return;
        }

        $termination = RequestItemTermination::query()
            ->when(
                $item instanceof RequestDocument,
                fn ($q) => $q->where('request_document_id', $item->getKey()),
                fn ($q) => $q->where('request_certificate_id', $item->getKey()),
            )
            ->first();

        $reasonText = $target === RequestStatusEnum::Withdrawn
            ? $this->withdrawalText($termination?->reason, $termination?->detail)
            : $this->closureText($termination?->reason, $termination?->detail);

        $this->notifications->send(
            recipient:    $owner,
            triggerEvent: $target === RequestStatusEnum::Withdrawn
                ? 'request_item_withdrawn'
                : 'request_item_closed_unable_to_process',
            data:         [
                'request_id'  => $request->request_id,
                'item_name'   => $this->itemName($item),
                'item_reason' => $reasonText,
            ],
            requestId:    $request->request_id,
        );
    }

    private function itemName(RequestDocument|RequestCertificate $item): string
    {
        if ($item instanceof RequestDocument) {
            return $item->documentType?->document_name ?? 'Document';
        }

        return $item->certificationType?->certificate_name ?? 'Certificate';
    }

    private function withdrawalText(?string $reason, ?string $detail): string
    {
        $case = $reason !== null ? WithdrawalReasonEnum::tryFrom($reason) : null;

        if ($case === null) {
            return $detail ?: 'No reason recorded';
        }

        return $case === WithdrawalReasonEnum::Other ? ($detail ?: $case->label()) : $case->label();
    }

    private function closureText(?string $reason, ?string $detail): string
    {
        $case = $reason !== null ? ClosureReasonEnum::tryFrom($reason) : null;

        if ($case === null) {
            return $detail ?: 'No reason recorded';
        }

        return $case === ClosureReasonEnum::Other ? ($detail ?: $case->label()) : $case->label();
    }

    private static function label(RequestStatusEnum $status): string
    {
        return trim(preg_replace('/(?<!^)[A-Z]/', ' $0', $status->name));
    }
}
