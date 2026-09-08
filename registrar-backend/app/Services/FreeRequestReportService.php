<?php

namespace App\Services;

use App\Enums\RequestStatusEnum;
use App\Models\DocumentRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * FESPEC-0008 — Phase 8 Observability.
 *
 * Free-issuance volume report: how many free COG/TOR/LOA copies were
 * actually CLAIMED, grouped by calendar month (display timezone) and
 * document/certificate type, for the registrar's monthly oversight
 * review (Phase 8 plan: "free-issuance volume per month, broken out by
 * document type — COG/TOR one-time vs. LOA recurring").
 *
 * ── Why this counts claims via request_history, not document_request itself ──
 * "Actually claimed" is the operative word — see the Phase 1 decision
 * that the free entitlement is consumed at CLAIM, not at filing. A
 * free request that was filed but Forfeited (never claimed, swept by
 * the automated shredder) correctly contributes NOTHING to this report:
 * an unclaimed copy was never actually issued.
 *
 * document_request itself cannot answer "when was this claimed":
 *   - `document_request.$timestamps` is false, and has no claimed_at/
 *     completed_at column of its own.
 *   - DocumentRequestService::claimRequest() only ever writes
 *     status_id via updateRequest() — it never writes a timestamp onto
 *     document_request directly.
 *
 * request_history IS this system's existing append-only record of
 * every status transition, complete with a `changed_at` timestamp,
 * written by DocumentRequestService::recordHistory() for every
 * transition — including the ReadyToClaim → Completed one a QR/
 * claim_code scan performs (DocumentRequestController::claim() →
 * DocumentRequestService::claimRequest() → updateRequest() →
 * recordHistory()). Reusing that existing record here means this
 * report can never drift out of sync with what actually happened at
 * the counter, and needs no new column/migration.
 */
class FreeRequestReportService
{
    /**
     * @param int|null $year Calendar year in the display timezone
     *        (config('app.display_timezone'), Asia/Manila). Defaults to
     *        the current year in that timezone.
     * @return Collection<int, array{month:string, type_label:string, count:int}>
     *         `month` is 'YYYY-MM' in the display timezone. Sorted by
     *         month ascending, then type_label alphabetically within a
     *         month, so the caller can render it directly without
     *         re-sorting.
     */
    public function monthlyVolume(?int $year = null): Collection
    {
        $timezone = config('app.display_timezone', 'Asia/Manila');
        $year ??= Carbon::now($timezone)->year;

        // Manila (display-timezone) calendar-year boundaries, converted
        // to the UTC instants request_history.changed_at is actually
        // stored in — the same "convert the display-timezone boundary
        // to UTC before querying" approach AuditLogController::
        // resolveDateBoundary() already uses for audit log date
        // filters, kept consistent here rather than inventing a second
        // convention for the same problem.
        $startUtc = Carbon::create($year, 1, 1, 0, 0, 0, $timezone)->utc();
        $endUtc   = Carbon::create($year + 1, 1, 1, 0, 0, 0, $timezone)->utc();

        $claimedRequests = DocumentRequest::query()
            ->adminFiledFree()
            ->whereHas('history', function ($q) use ($startUtc, $endUtc) {
                $q->where('new_status_id', RequestStatusEnum::Completed->value)
                    ->whereBetween('changed_at', [$startUtc, $endUtc]);
            })
            ->with([
                // Only the Completed-transition rows, oldest first —
                // see the loop below for why the first one is what
                // "claimed at" means here.
                'history' => fn ($q) => $q
                    ->where('new_status_id', RequestStatusEnum::Completed->value)
                    ->orderBy('changed_at'),
                'documents.documentType:document_type_id,document_name',
                'certificates.certificationType:certificate_type_id,certificate_name',
            ])
            ->get();

        // Grouped in PHP rather than a DB-side DATE_FORMAT/date_trunc:
        // production runs MySQL but the test suite runs SQLite (see
        // phpunit.xml), and this codebase already avoids portability
        // traps like that (see the channel-column migration's docblock
        // on partial unique indexes). This report is a low-volume,
        // periodic admin view, not a hot path — the portability is
        // worth far more than the marginal query-planner efficiency.
        $rows = collect();

        foreach ($claimedRequests as $documentRequest) {
            // allowedTransitions() never permits leaving Completed, and
            // Forfeited/Cancelled are the only other terminal states —
            // so in practice a request has exactly one transition INTO
            // Completed. Taking the first (oldest, per the eager-load
            // above) is a defensive guard against that invariant ever
            // changing, not an assumption that duplicates are expected.
            $claimedAt = $documentRequest->history->first()?->changed_at;
            if (!$claimedAt) {
                continue;
            }

            $month = $claimedAt->copy()->setTimezone($timezone)->format('Y-m');

            foreach ($documentRequest->documents as $document) {
                $rows->push([
                    'month'      => $month,
                    'type_label' => $document->documentType?->document_name ?? 'Unknown document type',
                ]);
            }

            foreach ($documentRequest->certificates as $certificate) {
                $rows->push([
                    'month'      => $month,
                    'type_label' => $certificate->certificationType?->certificate_name ?? 'Unknown certificate type',
                ]);
            }
        }

        return $rows
            ->groupBy(fn (array $r) => $r['month'].'|'.$r['type_label'])
            ->map(fn (Collection $group) => [
                'month'      => $group->first()['month'],
                'type_label' => $group->first()['type_label'],
                'count'      => $group->count(),
            ])
            ->sortBy([['month', 'asc'], ['type_label', 'asc']])
            ->values();
    }
}
