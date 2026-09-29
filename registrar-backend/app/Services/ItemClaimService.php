<?php

namespace App\Services;

use App\Enums\RequestStatusEnum;
use App\Http\Resources\DocumentRequestListResource;
use App\Models\DocumentRequest;
use App\Models\RequestCertificate;
use App\Models\RequestDocument;
use App\Models\RequestHistory;
use App\Models\RequestReleaseGroup;
use App\Models\SystemUser;
use App\Services\Concerns\FlushesAnalyticsCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Per-item claiming (Phase 3).
 *
 * Two steps, so staff see what they are handing over before anything changes:
 *
 *   lookup()   read-only. Resolves a scanned/typed credential and returns the
 *              request summary plus each item with its status and whether it
 *              can be claimed right now. Changes nothing.
 *   confirm()  the write. Takes the credential and the uuids of the items staff
 *              saw on screen and completes exactly those that are still Ready
 *              to Claim, in one transaction. Anything else is reported as
 *              skipped, never silently released.
 *
 * A credential can be:
 *   - an ITEM's own uuid/claim_code  -> that one item
 *   - a REQUEST's uuid/claim_code    -> every item of the request
 *   - a legacy RELEASE GROUP ticket  -> the items in that group (kept only so
 *                                       tickets already handed out still work
 *                                       until release groups are retired)
 *
 * Rules enforced here (not left to callers):
 *   - archived request                     -> 422 (read-only everywhere)
 *   - request already Completed/Forfeited/Withdrawn/Closed -> 422
 *   - lock order is item rows FIRST, then the request row — the same order as
 *     RequestItemStatusService::advance*Item(), so a scan and a manual "Done"
 *     click on the same item cannot deadlock each other.
 *   - idempotent: a second confirm for the same item completes nothing and is
 *     refused with 422 instead of writing a second history row.
 *   - the parent is never written directly. It is recomputed from its items
 *     (earliest stage wins), so it stays Processing/Ready until the LAST item
 *     is done, and the student is notified once, when the parent itself
 *     changes status.
 */
class ItemClaimService
{
    use FlushesAnalyticsCache;

    /** Hard cap on items in one confirm; a request never has anywhere near this many. */
    public const MAX_ITEMS_PER_CONFIRM = 50;

    private const UNDERGRAD_SUMMARY_COLUMNS = 'undergradRequestorProfile:'
        . 'undergrad_requestor_profile_id,user_id,first_name,middle_name,last_name,suffix,student_number,program';

    public function __construct(
        private RequestItemStatusService $itemStatusService,
        private BusinessCalendarService  $businessCalendarService,
    ) {}

    // ─────────────────────────────────────────────────────────────────────
    // Step 1 — lookup (read-only)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @param  array{uuid?: string, claim_code?: string}  $credential
     * @return array{matched: string, request: array, items: array<int, array>}
     */
    public function lookup(array $credential): array
    {
        $resolved = $this->resolve($credential);

        $request = $this->loadForSummary($resolved['request']);

        $items = match ($resolved['matched']) {
            'item'          => collect([$resolved['item']]),
            'release_group' => $this->itemsOf($request)->filter(
                fn (Model $i) => (int) $i->request_release_group_id === (int) $resolved['group']->request_release_group_id
            ),
            default         => $this->itemsOf($request),
        };

        return [
            'matched' => $resolved['matched'],
            'request' => (new DocumentRequestListResource($request))->summary(),
            'items'   => $items->map(fn (Model $item) => $this->presentItem($item, $request))->values()->all(),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Step 2 — confirm (write)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @param  array{uuid?: string, claim_code?: string}  $credential
     * @param  string[]|null  $itemUuids  required unless the credential is an item's own
     * @return array{request: array, completed: array, skipped: array}
     */
    public function confirm(array $credential, ?array $itemUuids): array
    {
        $this->authorizeComplete();

        $resolved = $this->resolve($credential);

        if ($resolved['matched'] === 'item') {
            $ownUuid = $resolved['item']->uuid;

            if ($itemUuids !== null && array_diff($itemUuids, [$ownUuid]) !== []) {
                abort(422, 'That code belongs to one specific document. Scan the request ticket to claim several at once.');
            }

            $itemUuids = [$ownUuid];
        }

        $itemUuids = array_values(array_unique($itemUuids ?? []));

        if ($itemUuids === []) {
            abort(422, 'Select at least one document to claim.');
        }

        if (count($itemUuids) > self::MAX_ITEMS_PER_CONFIRM) {
            abort(422, 'Too many documents selected.');
        }

        $requestId = (int) $resolved['request']->request_id;
        $groupId   = $resolved['group']?->request_release_group_id;

        $result = DB::transaction(function () use ($requestId, $itemUuids, $groupId) {
            // Items first, in a stable order, then the request (see class docblock).
            $documents = RequestDocument::where('request_id', $requestId)
                ->whereIn('uuid', $itemUuids)
                ->orderBy('request_document_id')
                ->lockForUpdate()
                ->get();

            $certificates = RequestCertificate::where('request_id', $requestId)
                ->whereIn('uuid', $itemUuids)
                ->orderBy('request_certificate_id')
                ->lockForUpdate()
                ->get();

            $items = $documents->concat($certificates);

            // Every uuid must resolve to an item of THIS request (and, for a
            // legacy group ticket, of that group). A foreign or unknown uuid
            // is a client bug or a tampering attempt, not something to skip.
            if ($items->count() !== count($itemUuids)) {
                abort(422, 'One or more selected documents do not belong to this request.');
            }

            if ($groupId !== null && $items->contains(fn (Model $i) => (int) $i->request_release_group_id !== (int) $groupId)) {
                abort(422, 'One or more selected documents are not part of this ticket.');
            }

            $request = DocumentRequest::withArchived()->lockForUpdate()->findOrFail($requestId);

            if ($request->is_archived) {
                abort(422, 'This request is archived and is read-only. Restore it first.');
            }

            $this->guardNotTerminal($request);

            $completed = [];
            $skipped   = [];

            foreach ($items as $item) {
                $current = RequestStatusEnum::tryFrom((int) $item->status_id);

                if ($current !== RequestStatusEnum::ReadyToClaim) {
                    $skipped[] = ['item' => $item, 'reason' => $this->notClaimableReason($current)];
                    continue;
                }

                $item->update(['status_id' => RequestStatusEnum::Completed->value]);

                $this->recordItemHistory(
                    $request,
                    $current->value,
                    RequestStatusEnum::Completed->value,
                    requestDocumentId: $item instanceof RequestDocument ? $item->request_document_id : null,
                    requestCertificateId: $item instanceof RequestCertificate ? $item->request_certificate_id : null,
                );

                $completed[] = $item;
            }

            if ($completed === []) {
                $first = $skipped[0]['reason'] ?? 'not ready to claim';
                abort(422, "Nothing was claimed: {$first}.");
            }

            // Roll up ONCE for the whole confirm. This is what moves the
            // parent (and notifies the student) — only when the parent's own
            // status actually changes, i.e. when the last item is released.
            foreach (collect($completed)->pluck('request_release_group_id')->filter()->unique() as $gid) {
                $this->itemStatusService->recomputeReleaseGroupAggregate((int) $gid);
            }
            $this->itemStatusService->recomputeAggregateStatus($request);

            $this->flushAnalyticsCache();

            return ['request' => $request->refresh(), 'completed' => $completed, 'skipped' => $skipped];
        });

        $request = $this->loadForSummary($result['request']);

        return [
            'request'   => (new DocumentRequestListResource($request))->summary(),
            'completed' => array_map(fn (Model $i) => $this->presentItem($i->refresh(), $request), $result['completed']),
            'skipped'   => array_map(
                fn (array $s) => $this->presentItem($s['item'], $request) + ['skipped_reason' => $s['reason']],
                $result['skipped']
            ),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Internals
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Find what a credential points at. No locks (lookup is read-only, and
     * confirm re-validates everything under lock).
     *
     * Items are checked first, then legacy release groups, then the request.
     * ClaimCredential::code() keeps codes unique across all four tables, so a
     * code never matches two of them; the order only matters for the
     * astronomically unlikely uuid/code that pre-dates that guarantee.
     * An archived request never matches (ExcludeArchivedScope), same as the
     * whole-request claim.
     *
     * @return array{matched: string, request: DocumentRequest, item: ?Model, group: ?RequestReleaseGroup}
     */
    private function resolve(array $credential): array
    {
        $column = isset($credential['uuid']) ? 'uuid' : 'claim_code';
        $value  = (string) $credential[$column];

        if ($column === 'claim_code') {
            $value = strtoupper($value);
        }

        // Deliberately generic — never reveals which part of a payload was wrong.
        $notFound = fn () => abort(404, 'No matching request found for that code.');

        foreach ([RequestDocument::class, RequestCertificate::class] as $model) {
            $item = $model::where($column, $value)->first();

            if ($item) {
                $request = DocumentRequest::find($item->request_id) ?? $notFound();

                return ['matched' => 'item', 'request' => $request, 'item' => $item, 'group' => null];
            }
        }

        $group = RequestReleaseGroup::where($column, $value)->first();
        if ($group) {
            $request = DocumentRequest::find($group->request_id) ?? $notFound();

            return ['matched' => 'release_group', 'request' => $request, 'item' => null, 'group' => $group];
        }

        $request = DocumentRequest::where($column, $value)->first() ?? $notFound();

        return ['matched' => 'request', 'request' => $request, 'item' => null, 'group' => null];
    }

    private function loadForSummary(DocumentRequest $request): DocumentRequest
    {
        return $request->load([
            'status',
            'studentProfile',
            'academicRecord',
            'alumniProfile',
            'alumniAcademicRecord',
            self::UNDERGRAD_SUMMARY_COLUMNS,
            'documents.documentType',
            'documents.status',
            'certificates.certificationType',
            'certificates.status',
        ]);
    }

    /** @return \Illuminate\Support\Collection<int, Model> */
    private function itemsOf(DocumentRequest $request)
    {
        return $request->documents->concat($request->certificates);
    }

    private function presentItem(Model $item, DocumentRequest $request): array
    {
        $isDocument = $item instanceof RequestDocument;

        $item->loadMissing($isDocument ? ['documentType', 'status'] : ['certificationType', 'status']);

        $status  = RequestStatusEnum::tryFrom((int) $item->status_id);
        $blocked = $this->blockedReason($request, $status);

        return [
            'type'             => $isDocument ? 'document' : 'certificate',
            'id'               => $isDocument ? $item->request_document_id : $item->request_certificate_id,
            'uuid'             => $item->uuid,
            'name'             => $isDocument ? $item->documentType?->document_name : $item->certificationType?->certificate_name,
            'number_of_copies' => $item->number_of_copies,
            'status_id'        => $item->status_id,
            'status'           => $item->status?->status_name,
            'completed_at'     => $item->completed_at,
            'claimable'        => $blocked === null,
            'reason'           => $blocked,
        ];
    }

    /** Null when claimable. */
    private function blockedReason(DocumentRequest $request, ?RequestStatusEnum $itemStatus): ?string
    {
        $requestStatus = RequestStatusEnum::tryFrom((int) $request->status_id);

        if ($requestStatus !== null && $requestStatus->isTerminal()) {
            return 'This request is already ' . $this->label($requestStatus) . '.';
        }

        return $itemStatus === RequestStatusEnum::ReadyToClaim ? null : ucfirst($this->notClaimableReason($itemStatus)) . '.';
    }

    private function notClaimableReason(?RequestStatusEnum $status): string
    {
        return match ($status) {
            RequestStatusEnum::Completed => 'already claimed',
            RequestStatusEnum::Forfeited => 'forfeited',
            RequestStatusEnum::Withdrawn => 'withdrawn',
            RequestStatusEnum::ClosedUnableToProcess => 'closed (unable to process)',
            null                          => 'not ready to claim',
            default                       => 'not ready yet (' . $this->label($status) . ')',
        };
    }

    private function label(RequestStatusEnum $status): string
    {
        return trim(preg_replace('/(?<!^)[A-Z]/', ' $0', $status->name));
    }

    private function guardNotTerminal(DocumentRequest $request): void
    {
        $status = RequestStatusEnum::tryFrom((int) $request->status_id);

        if ($status !== null && $status->isTerminal()) {
            abort(422, 'This request is already ' . $this->label($status) . ', so its documents can no longer be claimed.');
        }
    }

    private function authorizeComplete(): void
    {
        $actor = Auth::user();

        if ($actor instanceof SystemUser && !$actor->hasModuleAccess('dashboard', 'Complete')) {
            abort(403, "Your account's assigned policy does not grant the 'Complete' action on the dashboard module.");
        }
    }

    /**
     * Per-item history row — same shape and SLA timing as
     * RequestItemStatusService::recordItemHistory(): minutes since filing,
     * and business minutes since THIS item's previous transition.
     */
    private function recordItemHistory(
        DocumentRequest $request,
        int $oldStatusId,
        int $newStatusId,
        ?int $requestDocumentId = null,
        ?int $requestCertificateId = null,
    ): void {
        $segmentStart = RequestHistory::where('request_id', $request->request_id)
            ->when($requestDocumentId, fn ($q) => $q->where('request_document_id', $requestDocumentId))
            ->when($requestCertificateId, fn ($q) => $q->where('request_certificate_id', $requestCertificateId))
            ->orderByDesc('changed_at')
            ->orderByDesc('request_history_id')
            ->value('changed_at');

        $segmentStart = $segmentStart ? Carbon::parse($segmentStart) : $request->requested_at;

        RequestHistory::create([
            'request_id'             => $request->request_id,
            'request_document_id'    => $requestDocumentId,
            'request_certificate_id' => $requestCertificateId,
            'old_status_id'          => $oldStatusId,
            'new_status_id'          => $newStatusId,
            'changed_at'             => now(),
            'changed_by'             => Auth::id(),
            'minutes_processed'      => (int) $request->requested_at->diffInMinutes(now()),
            'business_minutes'       => $this->businessCalendarService->minutesBetween($segmentStart, now()),
        ]);
    }
}
