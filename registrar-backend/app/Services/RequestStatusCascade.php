<?php

namespace App\Services;

use App\Enums\RequestStatusEnum;
use App\Models\DocumentRequest;
use App\Models\RequestCertificate;
use App\Models\RequestDocument;
use App\Models\RequestHistory;
use App\Models\RequestReleaseGroup;

/**
 * Single place that pushes a REQUEST-level status change down onto the
 * request's own line items (request_document / request_certificate) and
 * release groups (request_release_group).
 *
 * WHY THIS EXISTS
 * document_request.status_id is the derived "earliest-stage-wins"
 * aggregate of its items (see RequestItemStatusService::
 * recomputeAggregateStatus()). Any code path that writes a terminal
 * status onto the request WITHOUT also moving its items leaves the two
 * out of sync, and the staff dashboard — which renders each row's pill
 * and its "Done" button from the ITEM statuses — keeps showing
 * "Ready to Claim" on a request that has already been claimed. That is
 * exactly the whole-request QR-claim bug this class fixes. The other two
 * terminal writers (RequestReleaseGroupService::claimReleaseGroup() and
 * the ShredExpiredRequests command) each carried their own private copy
 * of this loop; claimRequest() had none. They now all go through here so
 * they cannot drift apart again.
 *
 * CONTRACT
 *   - The CALLER must already hold a row lock on the parent
 *     DocumentRequest, inside an open DB transaction. This class never
 *     opens its own transaction and never re-locks the parent.
 *   - Lock order is parent -> documents -> certificates -> groups. That
 *     matches ShredExpiredRequests and RequestItemStatusService::
 *     bulkAdvanceItems(), so the paths that share these rows cannot
 *     deadlock against each other.
 *   - Only rows currently at $from are moved. Rows already at another
 *     status (for example items claimed earlier through a release-group
 *     ticket) are left exactly as they are.
 *   - Idempotent: running it twice moves nothing the second time and
 *     writes no second set of history rows.
 *   - It does NOT touch document_request itself, send notifications, or
 *     flush caches. The caller owns those, because it already knows
 *     whether a parent-level transition happened.
 *
 * HISTORY ROWS
 * One request_history row per moved item (request_document_id or
 * request_certificate_id set, never both). They record WHEN each item
 * changed state and who did it, but deliberately leave business_minutes
 * NULL: the request-level transition that triggered this cascade already
 * has its own timed row, and every timing report in AnalyticsService
 * filters on business_minutes IS NOT NULL. Timing each item row too
 * would count one and the same segment once per item and skew the
 * averages, especially for items that have no earlier item-level row and
 * would therefore be measured from requested_at.
 */
class RequestStatusCascade
{
    /**
     * Statuses that mean "this item is finished". Derived from the enum
     * so it always agrees with RequestStatusEnum::allowedTransitions().
     *
     * @return int[]
     */
    public static function terminalStatusIds(): array
    {
        return collect(RequestStatusEnum::cases())
            ->filter(fn (RequestStatusEnum $status) => $status->isTerminal())
            ->map(fn (RequestStatusEnum $status) => $status->value)
            ->values()
            ->all();
    }

    /**
     * Moves every item and release group of $documentRequest that is
     * currently at $from over to $to, and records one history row per
     * moved item.
     *
     * @param  int|null $actorId   users.user_id of the person acting, or
     *                             null for an automated actor.
     * @param  bool     $automated true for a system actor (cron): also
     *                             stamps processed_by_email = 'system',
     *                             matching ShredExpiredRequests' own
     *                             parent-level history row.
     * @return array{documents: int, certificates: int, groups: int}
     *         how many rows of each kind were moved.
     */
    public function cascade(
        DocumentRequest $documentRequest,
        RequestStatusEnum $from,
        RequestStatusEnum $to,
        ?int $actorId = null,
        bool $automated = false,
    ): array {
        $documents = RequestDocument::where('request_id', $documentRequest->request_id)
            ->where('status_id', $from->value)
            ->lockForUpdate()
            ->get();

        $certificates = RequestCertificate::where('request_id', $documentRequest->request_id)
            ->where('status_id', $from->value)
            ->lockForUpdate()
            ->get();

        $groups = RequestReleaseGroup::where('request_id', $documentRequest->request_id)
            ->where('status_id', $from->value)
            ->lockForUpdate()
            ->get();

        foreach ($documents as $item) {
            $item->update(['status_id' => $to->value]);
            $this->recordItemHistory(
                $documentRequest,
                $from,
                $to,
                $actorId,
                $automated,
                requestDocumentId: $item->request_document_id,
            );
        }

        foreach ($certificates as $item) {
            $item->update(['status_id' => $to->value]);
            $this->recordItemHistory(
                $documentRequest,
                $from,
                $to,
                $actorId,
                $automated,
                requestCertificateId: $item->request_certificate_id,
            );
        }

        foreach ($groups as $group) {
            $group->update(['status_id' => $to->value]);
        }

        return [
            'documents'    => $documents->count(),
            'certificates' => $certificates->count(),
            'groups'       => $groups->count(),
        ];
    }

    /**
     * How many of the request's items are still in a non-terminal status.
     *
     * After a claim cascade this should be zero. A non-zero value means
     * the data had drifted before the claim (a request marked Ready to
     * Claim while one of its items was not), and callers use it to log a
     * warning rather than silently hiding the inconsistency.
     */
    public function countNonTerminalItems(DocumentRequest $documentRequest): int
    {
        $terminal = self::terminalStatusIds();

        return RequestDocument::where('request_id', $documentRequest->request_id)
                ->whereNotNull('status_id')
                ->whereNotIn('status_id', $terminal)
                ->count()
            + RequestCertificate::where('request_id', $documentRequest->request_id)
                ->whereNotNull('status_id')
                ->whereNotIn('status_id', $terminal)
                ->count();
    }

    private function recordItemHistory(
        DocumentRequest $documentRequest,
        RequestStatusEnum $from,
        RequestStatusEnum $to,
        ?int $actorId,
        bool $automated,
        ?int $requestDocumentId = null,
        ?int $requestCertificateId = null,
    ): void {
        RequestHistory::create([
            'request_id'             => $documentRequest->request_id,
            'request_document_id'    => $requestDocumentId,
            'request_certificate_id' => $requestCertificateId,
            'old_status_id'          => $from->value,
            'new_status_id'          => $to->value,
            'changed_at'             => now(),
            'changed_by'             => $automated ? null : $actorId,
            'processed_by_email'     => $automated ? 'system' : null,
            'minutes_processed'      => (int) $documentRequest->requested_at->diffInMinutes(now()),
            // Intentionally NULL — see the class docblock.
            'business_minutes'       => null,
        ]);
    }
}
