<?php

namespace App\Services;

use App\Enums\RequestStatusEnum;
use Illuminate\Support\Collection;

/**
 * The single rule that turns a set of ITEM statuses into the status of
 * their parent (a document_request, or a release group).
 *
 * It replaces two private copies of "earliest stage wins" that lived in
 * RequestItemStatusService and RequestReleaseGroupService, and adds the
 * Withdrawn / Closed handling those copies lacked (both used to rank as
 * the MOST advanced status, so withdrawing one item would have pushed the
 * parent forward).
 *
 * RULES
 *   1. Items that were Withdrawn or Closed - Unable to Process have LEFT
 *      the request. They are ignored while any other item remains.
 *   2. Among the remaining items the least advanced one wins (the same
 *      "earliest stage wins" rule as before). Completed and Forfeited
 *      share the top rank.
 *   3. If every item has left the request:
 *        - any Closed item        -> Closed - Unable to Process
 *        - otherwise              -> Withdrawn
 *
 * Examples
 *   Completed + Withdrawn           -> Completed
 *   Ready     + Withdrawn           -> Ready to Claim
 *   Processing + Closed             -> Processing
 *   Withdrawn + Withdrawn           -> Withdrawn
 *   Withdrawn + Closed              -> Closed - Unable to Process
 *
 * Pure and stateless: no database, no side effects. Callers own locking,
 * history rows and notifications.
 */
final class RequestAggregateStatus
{
    /** Relative progress, lower = earlier. Cancelled is deliberately absent. */
    private const STAGE_RANK = [
        RequestStatusEnum::AwaitingSubmission->value => 0,
        RequestStatusEnum::Processing->value         => 1,
        RequestStatusEnum::PendingSignature->value   => 2,
        RequestStatusEnum::ReadyToClaim->value       => 3,
        RequestStatusEnum::Completed->value          => 4,
        RequestStatusEnum::Forfeited->value          => 4,
    ];

    /** True for the two statuses that mean "this item left the request". */
    public static function hasLeftRequest(int $statusId): bool
    {
        return $statusId === RequestStatusEnum::Withdrawn->value
            || $statusId === RequestStatusEnum::ClosedUnableToProcess->value;
    }

    /**
     * @param  iterable<int|string|null> $statusIds
     * @return int|null the parent's status id, or null when there are no
     *                  item statuses to decide from (callers leave the
     *                  parent untouched in that case).
     */
    public static function resolve(iterable $statusIds): ?int
    {
        /** @var Collection<int, int> $ids */
        $ids = collect($statusIds)
            ->filter(fn ($id) => $id !== null && $id !== '')
            ->map(fn ($id) => (int) $id)
            ->values();

        if ($ids->isEmpty()) {
            return null;
        }

        $remaining = $ids->reject(fn (int $id) => self::hasLeftRequest($id))->values();

        if ($remaining->isNotEmpty()) {
            return $remaining
                ->sortBy(fn (int $id) => self::STAGE_RANK[$id] ?? PHP_INT_MAX)
                ->first();
        }

        return $ids->contains(RequestStatusEnum::ClosedUnableToProcess->value)
            ? RequestStatusEnum::ClosedUnableToProcess->value
            : RequestStatusEnum::Withdrawn->value;
    }
}
