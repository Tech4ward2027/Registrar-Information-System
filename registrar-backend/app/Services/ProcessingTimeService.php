<?php

namespace App\Services;

use App\Enums\RequestStatusEnum;
use App\Models\DocumentRequest;
use App\Models\RequestHistory;
use App\Support\ProcessingTimeResult;
use App\Support\SigningWaitResult;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The single source of truth for "No. of Minutes Processed" — the registrar's
 * own office-hours working time on a request or on one document/certificate.
 * The logbook, its export and the OPCR performance report all read it from
 * here so they can never disagree.
 *
 * COMPUTATION RULE (STP-PUPTRIS-LOG-001):
 *
 *   Request filed / Processing ........ clock RUNS
 *   Awaiting Submission ................ clock PAUSED (waiting on the client)
 *   Processing again (receipt confirmed) clock RESUMES
 *   Pending Signature .................. clock STOPS and is final
 *   (a request needing no signature stops when it becomes Ready to Claim)
 *
 * Ready to Claim, claiming time, and anything after the stop are never
 * counted. Only minutes inside official operating hours are counted —
 * after-hours, Monday WFH, weekends, holidays and declared suspensions are
 * skipped by BusinessCalendarService::minutesBetween().
 *
 * HOW IT IS COMPUTED
 *
 * The figure is derived at read time from request_history's
 * (old_status_id, new_status_id, changed_at) rows, walking the timeline once:
 * the span between two consecutive rows belongs to the status being LEFT
 * (old_status_id), and only spans spent in Processing are counted. The stored
 * request_history.business_minutes column is deliberately NOT used: it is a
 * per-segment value written by several services, includes spans that must not
 * count (Awaiting Submission, the signing office's wait), and — for
 * request-level rows written by an aggregate recompute — could start from an
 * item row recorded a moment earlier. Deriving from timestamps is correct no
 * matter which service wrote a row, needs no backfill, and is immutable once
 * the stop row exists (later rows are never read), which is what keeps the
 * value "finalized".
 *
 * TIMELINES
 *
 *  - Request scope: the whole-request rows (no item id).
 *  - Item scope: the whole-request rows that precede the item's first own
 *    row (the item moved with the request until then), followed by the item's
 *    own rows. If the item has no own rows it moved with the request, so the
 *    request timeline applies. Items in a bulk request are therefore measured
 *    independently: a faster document is unaffected by a slower one.
 *
 * Returns a null figure — never a wall-clock fallback — when the stop row is
 * missing or the calendar cannot be evaluated; callers flag such records.
 */
class ProcessingTimeService
{
    public function __construct(private BusinessCalendarService $businessCalendar) {}

    /**
     * Whole-request processing time. $sortedHistory may be passed (from
     * sortedHistory()) to avoid re-sorting when measuring many scopes of the
     * same request.
     */
    public function forRequest(DocumentRequest $request, ?Collection $sortedHistory = null): ProcessingTimeResult
    {
        $history = $sortedHistory ?? $this->sortedHistory($request);

        return $this->measure($request, $this->requestTimeline($history));
    }

    public function forDocument(DocumentRequest $request, int $requestDocumentId, ?Collection $sortedHistory = null): ProcessingTimeResult
    {
        $history = $sortedHistory ?? $this->sortedHistory($request);

        return $this->measure($request, $this->itemTimeline($history, 'request_document_id', $requestDocumentId));
    }

    public function forCertificate(DocumentRequest $request, int $requestCertificateId, ?Collection $sortedHistory = null): ProcessingTimeResult
    {
        $history = $sortedHistory ?? $this->sortedHistory($request);

        return $this->measure($request, $this->itemTimeline($history, 'request_certificate_id', $requestCertificateId));
    }

    /**
     * Signing wait of one document line: the office-hours time it spent in
     * Pending Signature. Same timelines, calendar and null-over-guess policy
     * as the processing figure; see walkSigning() for the exact rule.
     */
    public function signingWaitForDocument(DocumentRequest $request, int $requestDocumentId, ?Collection $sortedHistory = null): SigningWaitResult
    {
        $history = $sortedHistory ?? $this->sortedHistory($request);

        return $this->measureSigning($request, $this->itemTimeline($history, 'request_document_id', $requestDocumentId));
    }

    public function signingWaitForCertificate(DocumentRequest $request, int $requestCertificateId, ?Collection $sortedHistory = null): SigningWaitResult
    {
        $history = $sortedHistory ?? $this->sortedHistory($request);

        return $this->measureSigning($request, $this->itemTimeline($history, 'request_certificate_id', $requestCertificateId));
    }

    /**
     * The request's history rows in chronological order (changed_at, then id).
     * Rows with no timestamp cannot be placed on a timeline and are ignored.
     *
     * @return Collection<int, RequestHistory>
     */
    public function sortedHistory(DocumentRequest $request): Collection
    {
        return $request->history
            ->filter(fn (RequestHistory $row) => $row->changed_at !== null)
            ->sort(fn (RequestHistory $a, RequestHistory $b) => [$a->changed_at->getTimestamp(), (int) $a->request_history_id]
                <=> [$b->changed_at->getTimestamp(), (int) $b->request_history_id])
            ->values();
    }

    /**
     * Logbook payload for one request: its own figure plus one figure per
     * document/certificate line (needed when the logbook is filtered to one
     * category). Expects `history`, `documents` and `certificates` to be
     * eager-loaded; absent relations simply yield no item entries.
     *
     * @return array{processing: array<string, mixed>, items_processing: list<array<string, mixed>>}
     */
    public function summaryFor(DocumentRequest $request): array
    {
        $history = $this->sortedHistory($request);

        $items = [];

        if ($request->relationLoaded('documents')) {
            foreach ($request->documents as $document) {
                $items[] = ['type' => 'document', 'id' => (int) $document->request_document_id]
                    + $this->forDocument($request, (int) $document->request_document_id, $history)->toArray();
            }
        }

        if ($request->relationLoaded('certificates')) {
            foreach ($request->certificates as $certificate) {
                $items[] = ['type' => 'certificate', 'id' => (int) $certificate->request_certificate_id]
                    + $this->forCertificate($request, (int) $certificate->request_certificate_id, $history)->toArray();
            }
        }

        return [
            'processing'       => $this->forRequest($request, $history)->toArray(),
            'items_processing' => $items,
        ];
    }

    // -------------------------------------------------------------------------
    // Timelines
    // -------------------------------------------------------------------------

    /** @return Collection<int, RequestHistory> */
    private function requestTimeline(Collection $sortedHistory): Collection
    {
        return $sortedHistory
            ->filter(fn (RequestHistory $row) => $row->request_document_id === null && $row->request_certificate_id === null)
            ->values();
    }

    /** @return Collection<int, RequestHistory> */
    private function itemTimeline(Collection $sortedHistory, string $itemColumn, int $itemId): Collection
    {
        $itemRows = $sortedHistory
            ->filter(fn (RequestHistory $row) => $row->{$itemColumn} !== null && (int) $row->{$itemColumn} === $itemId)
            ->values();

        $requestRows = $this->requestTimeline($sortedHistory);

        if ($itemRows->isEmpty()) {
            return $requestRows;
        }

        $firstItemRowAt = $itemRows->first()->changed_at;

        return $requestRows
            ->filter(fn (RequestHistory $row) => $row->changed_at->lessThan($firstItemRowAt))
            ->concat($itemRows)
            ->values();
    }

    // -------------------------------------------------------------------------
    // Measurement
    // -------------------------------------------------------------------------

    private function measure(DocumentRequest $request, Collection $timeline): ProcessingTimeResult
    {
        $claimedAt = $this->claimedAt($timeline);

        if ($request->requested_at === null) {
            return new ProcessingTimeResult(null, null, null, $claimedAt);
        }

        try {
            [$minutes, $endedAt, $endStatusId, $endedBy] = $this->walk($request->requested_at, $timeline);
        } catch (RuntimeException $e) {
            // A missing default calendar or an absurd timestamp (see
            // BusinessCalendarService::MAX_DAYS) must not take the whole
            // logbook down; the record is reported as unmeasured instead.
            Log::warning('ProcessingTimeService could not measure a request.', [
                'request_id' => $request->request_id,
                'error'      => $e->getMessage(),
            ]);

            return new ProcessingTimeResult(null, null, null, $claimedAt);
        }

        return new ProcessingTimeResult($minutes, $endedAt, $endStatusId, $claimedAt, $endedBy);
    }

    /**
     * @return array{0: ?int, 1: ?CarbonInterface, 2: ?int, 3: ?int}  [minutes, stopped at, stopping status, user who stopped it]
     */
    private function walk(CarbonInterface $requestedAt, Collection $timeline): array
    {
        $processing = RequestStatusEnum::Processing->value;

        $cursor       = $requestedAt;
        $counted      = 0;
        $previousNew  = null;

        /** @var RequestHistory $row */
        foreach ($timeline as $row) {
            $at  = $row->changed_at;
            $new = (int) $row->new_status_id;

            // Status the span ending at this row was spent in. A missing
            // old_status_id (legacy item rows) inherits the previous row's
            // new status, and a request's very first span is Processing.
            $old = $row->old_status_id !== null
                ? (int) $row->old_status_id
                : ($previousNew ?? $processing);

            if ($old === $processing && $at->greaterThan($cursor)) {
                $counted += $this->businessCalendar->minutesBetween($cursor, $at);
            }

            if ($this->stopsClock($new)) {
                return [$counted, $at, $new, $row->changed_by !== null ? (int) $row->changed_by : null];
            }

            if ($at->greaterThan($cursor)) {
                $cursor = $at;
            }

            $previousNew = $new;
        }

        return [null, null, null, null];
    }

    private function measureSigning(DocumentRequest $request, Collection $timeline): SigningWaitResult
    {
        try {
            [$minutes, $finishedAt, $nextStatusId] = $this->walkSigning($timeline);
        } catch (RuntimeException $e) {
            Log::warning('ProcessingTimeService could not measure a signing wait.', [
                'request_id' => $request->request_id,
                'error'      => $e->getMessage(),
            ]);

            return new SigningWaitResult(null, null, null);
        }

        return new SigningWaitResult($minutes, $finishedAt, $nextStatusId);
    }

    /**
     * SIGNING WAIT RULE. Sums the office-hours minutes between each entry into
     * Pending Signature and the matching exit. A document sent back for a
     * re-signature contributes every span it spent there; the wait is
     * "finished" at the last exit. While the document is still waiting for a
     * signature the wait is not final, so nothing is reported, and a document
     * that never needed a signature has nothing to report either.
     *
     * Like walk(), spans are derived from the row timestamps only (the stored
     * business_minutes is never read), and a missing old_status_id inherits the
     * previous row's new status.
     *
     * @return array{0: ?int, 1: ?CarbonInterface, 2: ?int}  [minutes, wait ended at, status moved to]
     */
    private function walkSigning(Collection $timeline): array
    {
        $signing = RequestStatusEnum::PendingSignature->value;

        $enteredAt   = null;
        $counted     = 0;
        $finishedAt  = null;
        $nextStatus  = null;
        $previousNew = null;

        /** @var RequestHistory $row */
        foreach ($timeline as $row) {
            $at  = $row->changed_at;
            $new = (int) $row->new_status_id;
            $old = $row->old_status_id !== null
                ? (int) $row->old_status_id
                : ($previousNew ?? RequestStatusEnum::Processing->value);

            if ($new === $signing && $old !== $signing && $enteredAt === null) {
                $enteredAt = $at;
            } elseif ($old === $signing && $new !== $signing && $enteredAt !== null) {
                if ($at->greaterThan($enteredAt)) {
                    $counted += $this->businessCalendar->minutesBetween($enteredAt, $at);
                }

                $finishedAt = $at;
                $nextStatus = $new;
                $enteredAt  = null;
            }

            $previousNew = $new;
        }

        if ($enteredAt !== null || $finishedAt === null) {
            return [null, null, null];
        }

        return [$counted, $finishedAt, $nextStatus];
    }

    /**
     * Pending Signature ends the registrar's work; Ready to Claim ends it for
     * requests that need no signature. Completed is only a last-resort stop
     * for legacy rows that jumped straight to it without either status.
     */
    private function stopsClock(int $statusId): bool
    {
        return $statusId === RequestStatusEnum::PendingSignature->value
            || $statusId === RequestStatusEnum::ReadyToClaim->value
            || $statusId === RequestStatusEnum::Completed->value;
    }

    private function claimedAt(Collection $timeline): ?CarbonInterface
    {
        $row = $timeline->first(
            fn (RequestHistory $row) => (int) $row->new_status_id === RequestStatusEnum::Completed->value
        );

        return $row?->changed_at;
    }
}