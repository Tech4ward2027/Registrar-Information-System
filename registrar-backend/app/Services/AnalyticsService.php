<?php

namespace App\Services;

use App\Enums\RequestStatusEnum;
use App\Models\DocumentRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Encapsulates all analytics queries.
 *
 * AnalyticsController becomes a thin HTTP adapter that validates
 * the date-range parameter and delegates here.
 */
class AnalyticsService
{
    // -------------------------------------------------------------------------
    // Archive / soft-delete exclusion
    // -------------------------------------------------------------------------

    /**
     * Apply the "active requests only" predicate to a raw query builder
     * touching document_request (directly or via join).
     *
     * DocumentRequest registers two automatic exclusion mechanisms —
     * ExcludeArchivedScope (is_archived = false) and Laravel's SoftDeletes
     * (deleted_at IS NULL) — but both are Eloquent global scopes, so they
     * only apply to queries built through the DocumentRequest model.
     * Every query in this service that goes through DB::table(...) instead
     * (for JOIN/aggregate shapes Eloquent can't express as cleanly) bypasses
     * both scopes entirely, silently letting archived/deleted requests back
     * into aggregate reports.
     *
     * Centralised here — once, near the data — rather than repeating
     * ->where('is_archived', false)->whereNull('deleted_at') at every call
     * site, which is the exact copy-paste-dependent pattern that let this
     * gap exist in the first place. is_archived is indexed (dr_is_archived_idx,
     * see migration 2026_07_13_000000_add_archiving_to_document_request), so
     * this is a cheap, indexed filter, not a new table scan.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  string  $alias  Table/alias document_request was joined as.
     */
    private function excludeArchived(\Illuminate\Database\Query\Builder $query, string $alias = 'document_request'): \Illuminate\Database\Query\Builder
    {
        return $query
            ->where("{$alias}.is_archived", false)
            ->whereNull("{$alias}.deleted_at");
    }

    /**
     * Deficiency Notice & Withdrawn Status — Phase 5 ("Analytics/report
     * exclusion"). Excludes every request_history row belonging to a
     * WITHDRAWN request from average-processing-time metrics, on top of
     * the archive/soft-delete exclusion above.
     *
     * Applies only to the business_minutes-based "how long does
     * processing take" queries (overview()'s avg_processing_minutes,
     * byDocumentType()'s processingTimeByGroup subquery,
     * processingTime()'s per-document-type and per-admin averages, and
     * signatureTurnaroundTime()'s two SLA-clock breakdowns) — NOT plain
     * request-count queries like overview()'s total/pending/completed
     * buckets or byStatus(), where a Withdrawn request legitimately
     * belongs in the count (it was a real submission that consumed
     * intake) even though it never belongs in a "how fast do we complete
     * work" average. A Withdrawn request never completed, so blending
     * its interim segments — some reflecting a mistake caught quickly,
     * others a stalled request finally being closed out — into a
     * completion-time average would skew the number for no analytical
     * benefit, in either direction depending on which cases happen to
     * dominate a given date range.
     *
     * NOTE — Forfeited requests are deliberately NOT excluded here: this
     * method's job is the Withdrawn-specific exit criterion this
     * feature's Phase 5 calls for, not a general audit of every terminal
     * status's inclusion in these averages. On inspection, Forfeited
     * requests are not currently excluded from any of these same
     * averages either (a Forfeited request's ReadyToClaim segment — time
     * spent sitting unclaimed before expiring — still contributes to
     * processingTime()'s and overview()'s numbers today). That is a
     * pre-existing gap, not something introduced or fixed by this
     * change; flagged here for visibility rather than silently expanding
     * this method's scope to also cover it without an explicit decision
     * to do so.
     *
     * ClosedUnableToProcess (Data Retention & Disposal Policy §3.4) is
     * excluded for the identical reason as Withdrawn above: it is a
     * terminal status a request reaches WITHOUT ever completing, so
     * blending its interim segments into a completion-time average is
     * the same category of distortion Withdrawn's exclusion already
     * addresses. Grouped into this same method (rather than a second,
     * near-duplicate exclusion method) since both statuses share the
     * exact same "terminal, never completed, still counts as real
     * volume" characteristics that justify excluding them from this
     * specific class of query and no other.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  string  $alias  Alias of a joined document_request table.
     */
    private function excludeFromProcessingTimeMetrics(\Illuminate\Database\Query\Builder $query, string $alias = 'document_request'): \Illuminate\Database\Query\Builder
    {
        return $this->excludeArchived($query, $alias)
            ->where("{$alias}.status_id", '!=', RequestStatusEnum::Withdrawn->value)
            ->where("{$alias}.status_id", '!=', RequestStatusEnum::ClosedUnableToProcess->value);
    }

    // -------------------------------------------------------------------------
    // Logbook-category reporting rollup
    // -------------------------------------------------------------------------

    /**
     * SQL expression producing a stable grouping key for "logbook reporting
     * identity" — the value multiple document types collapse into when they
     * share a logbook_category, and a value unique to a single document type
     * when it has none.
     *
     * Per the spec (§6, §10): "Reports aggregate by logbook category rather
     * than raw document names" via "request item → document type → logbook
     * category." A naive `GROUP BY dt.logbook_category_id` cannot express
     * this correctly on its own: standard SQL treats every NULL as
     * belonging to the same group, so it would silently collapse ALL
     * uncategorized document types (the majority — see the logbook_category
     * migration's docblock) into one meaningless bucket instead of
     * reporting each individually.
     *
     * This expression fixes that: when logbook_category_id IS NOT NULL, the
     * key IS that category id, so every document type sharing a category
     * (e.g. all 9 CTC/Authentication variants) rolls into one row. When it's
     * NULL, the key falls back to the document type's own id, negated so it
     * can never collide with a real (always-positive, auto-increment)
     * logbook_category_id — each uncategorized document type then reports
     * as its own distinct group, exactly matching
     * DocumentType::logbookLabel()'s "falls back to the type's own name"
     * behavior on the model side.
     *
     * @param  string  $alias  Alias of a joined document_type (or
     *                         equivalent) table exposing logbook_category_id
     *                         and $idColumn.
     * @param  string  $idColumn  The type table's own primary key column.
     */
    private static function logbookGroupKeyExpression(string $alias, string $idColumn = 'document_type_id'): string
    {
        return "COALESCE({$alias}.logbook_category_id, -{$alias}.{$idColumn})";
    }

    // -------------------------------------------------------------------------
    // Overview KPIs
    // -------------------------------------------------------------------------

    /**
     * @param array $range [Carbon $from, Carbon $to] — see
     *                      AnalyticsController::dateRange().
     * @param string $rangeKey The raw ?range= value ('today'|'week'|
     *                         'month'|'year'|'all'|'custom'), default
     *                         'month' to preserve prior call sites/tests
     *                         that don't pass it. Only used to decide
     *                         whether a previous-period comparison is
     *                         even meaningful — see the block below.
     */
    public function overview(array $range, string $rangeKey = 'month'): array
    {
        [$from, $to] = $range;

        // Single query — conditional aggregates replace separate COUNT calls.
        // Cancelled requests are intentionally excluded from these buckets.
        $counts = DocumentRequest::whereBetween('requested_at', [$from, $to])
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN status_id = ? THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status_id = ? THEN 1 ELSE 0 END) as ready_to_claim,
                SUM(CASE WHEN status_id = ? THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status_id = ? THEN 1 ELSE 0 END) as forfeited
            ', [
                RequestStatusEnum::Processing->value,
                RequestStatusEnum::ReadyToClaim->value,
                RequestStatusEnum::Completed->value,
                RequestStatusEnum::Forfeited->value,
            ])
            ->first();

        // Use the unconditional COUNT(*) as the total, not a hand-summed total
        // of the buckets above — those buckets deliberately omit cancelled
        // requests, so summing them would undercount total volume.
        $total = (int) $counts->total;
        $completed = (int) $counts->completed;
        $forfeited = (int) $counts->forfeited;

        // Raw DB::table() query (not RequestHistory::), since averaging
        // needs a join back to document_request to reach is_archived /
        // deleted_at at all — request_history carries neither column
        // itself. Uses business_minutes (calendar-aware, per-segment
        // duration), not minutes_processed (cumulative wall-clock time
        // since requested_at, re-counted on every status change) — see
        // processingTime()'s doc block for the full explanation of why
        // these two columns aren't interchangeable.
        $avgProcessing = $this->excludeFromProcessingTimeMetrics(
            DB::table('request_history as rh')
                ->join('document_request as dr', 'rh.request_id', '=', 'dr.request_id')
                ->whereBetween('rh.changed_at', [$from, $to])
                ->whereNotNull('rh.business_minutes'),
            'dr'
        )->avg('rh.business_minutes');

        // BUG FIX (QA #13 — "'No Prior Data' Despite History"): 'all'
        // sets $from to now()->subYears(100) (see AnalyticsController::
        // dateRange()), so $periodLength below would be ~100 years and
        // $prevFrom/$prevTo would land 200-100 years ago — a window
        // that can never contain a real row, in any dataset, ever. That
        // guaranteed-empty query was previously run anyway and its
        // result (always 0) rendered as "No prior data" on the
        // dashboard — technically not wrong (there IS no previous
        // period for an open-ended range), but indistinguishable from
        // the genuine "we checked and found nothing" case, and a
        // wasted query every time an admin opens "All Time". Skip it
        // outright for 'all' rather than let a nonsensical date range
        // produce a nonsensical (if accidentally-plausible-looking)
        // result.
        if ($rangeKey === 'all') {
            $prevTotal = 0;
        } else {
            // Previous period comparison: the same-length window
            // immediately preceding $from. Note this is a rolling
            // window, not a calendar-aligned "last week"/"last month" —
            // e.g. for ?range=week checked on a Wednesday, this compares
            // against the ~2.5 days immediately before this week
            // started, not the equivalent Mon-Wed of last week. That
            // is intentional (matches "vs previous period of equal
            // length" everywhere else this pattern is used) and is not
            // part of what QA #13 was about — see the dateRange() fix
            // above for the actual bug (UTC vs local boundaries), which
            // is what was making even well-populated previous periods
            // read as empty.
            $periodLength = $from->diffInSeconds($to);
            $prevFrom     = $from->copy()->subSeconds($periodLength);
            $prevTo       = $from->copy();

            $prev = DocumentRequest::whereBetween('requested_at', [$prevFrom, $prevTo])
                ->selectRaw('
                    COUNT(*) as total,
                    SUM(CASE WHEN status_id = ? THEN 1 ELSE 0 END) as completed
                ', [RequestStatusEnum::Completed->value])
                ->first();

            $prevTotal = (int) $prev->total;
        }

        return [
            'total'                  => $total,
            'pending'                => (int) $counts->pending,
            'ready_to_claim'         => (int) $counts->ready_to_claim,
            'completed'              => $completed,
            'forfeited'              => $forfeited,
            'avg_processing_minutes' => $avgProcessing ? round($avgProcessing, 1) : null,
            'completion_rate'        => $total > 0 ? round(($completed / $total) * 100, 1) : 0,
            'forfeit_rate'           => $total > 0 ? round(($forfeited / $total) * 100, 1) : 0,
            'volume_change_pct'      => $prevTotal > 0
                ? round((($total - $prevTotal) / $prevTotal) * 100, 1)
                : null,
            'prev_total'             => $prevTotal,
        ];
    }

    // -------------------------------------------------------------------------
    // Volume trend (monthly buckets)
    // -------------------------------------------------------------------------

    public function volumeTrend(array $range): array
    {
        [$from, $to] = $range;

        $rows = DocumentRequest::select(
                DB::raw(self::monthExpression('requested_at') . ' as month'),
                DB::raw('COUNT(*) as total')
            )
            ->whereBetween('requested_at', [$from, $to])
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        $filled  = [];
        $cursor  = $from->copy()->startOfMonth();

        while ($cursor->lte($to)) {
            $key      = $cursor->format('Y-m');
            $match    = $rows->firstWhere('month', $key);
            $filled[] = [
                'month' => $key,
                'label' => $cursor->format('M Y'),
                'total' => $match ? (int) $match->total : 0,
            ];
            $cursor->addMonth();
        }

        return $filled;
    }

    // -------------------------------------------------------------------------
    // Requests by document type
    // -------------------------------------------------------------------------

    public function byDocumentType(array $range): array
    {
        [$from, $to] = $range;

        $groupKey = self::logbookGroupKeyExpression('dt2');

        // Processing-time-per-logbook-group subquery, built with the query
        // builder (rather than a hand-written DB::raw string) so the date
        // range and archive exclusion can be bound/applied the same way as
        // everywhere else instead of living outside the query builder's
        // reach. Grouped by the same logbook rollup key as the outer query
        // (see logbookGroupKeyExpression()) and aggregated directly off the
        // raw history rows — a true weighted average across every request
        // in the group, not an "average of the per-document averages" that
        // would silently over-weight low-volume document types once several
        // of them start sharing a logbook_category. Two bugs fixed here vs.
        // the original version:
        //   1. business_minutes, not minutes_processed — see processingTime()
        //      for the full explanation of why these differ.
        //   2. This subquery previously had no whereBetween('changed_at', ...)
        //      at all, so avg_processing_min silently ignored the selected
        //      date range and always computed an all-time average regardless
        //      of whether "Today," "This Month," or "This Year" was picked.
        $processingTimeByGroup = $this->excludeFromProcessingTimeMetrics(
            DB::table('request_history as rh')
                ->join('request_document as rd2', 'rh.request_id', '=', 'rd2.request_id')
                ->join('document_type as dt2', 'rd2.document_type_id', '=', 'dt2.document_type_id')
                ->join('document_request as dr2', 'rh.request_id', '=', 'dr2.request_id')
                ->whereBetween('rh.changed_at', [$from, $to])
                ->whereNotNull('rh.business_minutes'),
            'dr2'
        )
            ->select(
                DB::raw("{$groupKey} as group_key"),
                DB::raw('ROUND(AVG(rh.business_minutes), 1) as avg_minutes')
            )
            ->groupBy(DB::raw($groupKey));

        $outerGroupKey = self::logbookGroupKeyExpression('dt');

        // Reporting aggregation (spec §6, §10): "request item → document
        // type → logbook category." Every document type sharing a
        // logbook_category_id rolls into one row here (e.g. the 9 CTC/
        // Authentication variants report as a single "Certified True Copy
        // of Authentication" line), while document types with no category
        // assigned continue reporting individually under their own name —
        // see logbookGroupKeyExpression()'s docblock for why a bare
        // `GROUP BY logbook_category_id` can't do this on its own.
        $rows = $this->excludeArchived(
            DB::table('request_document as rd')
                ->join('document_type as dt', 'rd.document_type_id', '=', 'dt.document_type_id')
                ->leftJoin('logbook_category as lc', 'dt.logbook_category_id', '=', 'lc.logbook_category_id')
                ->join('document_request as dr', 'rd.request_id', '=', 'dr.request_id')
                ->leftJoinSub($processingTimeByGroup, 'pt', function ($join) use ($outerGroupKey) {
                    $join->on(DB::raw($outerGroupKey), '=', 'pt.group_key');
                })
                ->whereBetween('dr.requested_at', [$from, $to]),
            'dr'
        )
            ->select(
                // Representative id — the document type's own id when it
                // isn't part of a category, or an arbitrary-but-stable
                // member id when several document types are rolled into one
                // logbook_category row. Consumers that need every
                // underlying document type should use document_type_ids
                // (below) instead of relying on this single id.
                DB::raw('MIN(dt.document_type_id) as document_type_id'),
                'dt.logbook_category_id',
                // The logbook-reporting label: the shared category name
                // when one is assigned, falling back to the document
                // type's own name when logbook_category_id is NULL —
                // exactly DocumentType::logbookLabel()'s rule, applied at
                // the aggregate level. MAX() (not a bare column) is
                // required to satisfy ONLY_FULL_GROUP_BY — safe here
                // because every row folded into one group shares the same
                // category (and therefore the same lc.name), or is the
                // sole row for its own document (so MAX(dt.document_name)
                // is just that one value).
                DB::raw('COALESCE(MAX(lc.name), MAX(dt.document_name)) as document_name'),
                // How many distinct document types were rolled into this
                // row — 1 for an uncategorized document type, >1 for a
                // genuine logbook_category rollup. Lets API consumers tell
                // "single document" and "umbrella category" rows apart
                // without a second lookup.
                DB::raw('COUNT(DISTINCT dt.document_type_id) as document_type_count'),
                // Renamed from total_requests: this counts request_document
                // line items, not distinct requests — a request with 2
                // document types is counted once per type here. That's
                // legitimate (each type was genuinely requested), but the
                // old name invited "why doesn't this match Total Requests"
                // confusion against overview()'s distinct-request count.
                DB::raw('COUNT(rd.request_document_id) as total_documents'),
                DB::raw('SUM(rd.number_of_copies) as total_copies'),
                DB::raw('MAX(pt.avg_minutes) as avg_processing_min')
            )
            ->groupBy(DB::raw($outerGroupKey), 'dt.logbook_category_id')
            ->orderByDesc('total_documents')
            ->get();

        return $rows->map(fn ($row) => [
            'document_type_id'    => $row->document_type_id,
            'logbook_category_id' => $row->logbook_category_id,
            'document_name'       => $row->document_name,
            'document_type_count' => (int) $row->document_type_count,
            'total_documents'     => (int) $row->total_documents,
            'total_copies'        => (int) $row->total_copies,
            'avg_processing_min'  => $row->avg_processing_min,
        ])->all();
    }

    // -------------------------------------------------------------------------
    // Requests by status
    // -------------------------------------------------------------------------

    public function byStatus(array $range): array
    {
        [$from, $to] = $range;

        $query = DB::table('document_request as dr')
            ->join('request_status as rs', 'dr.status_id', '=', 'rs.status_id')
            ->whereBetween('dr.requested_at', [$from, $to]);

        return $this->excludeArchived($query, 'dr')
            ->select(
                'rs.status_id',
                'rs.status_name',
                DB::raw('COUNT(*) as total')
            )
            ->groupBy('rs.status_id', 'rs.status_name')
            ->orderBy('rs.status_id')
            ->get()
            ->map(fn ($r) => [
                'status_id'   => $r->status_id,
                'status_name' => $r->status_name,
                'total'       => (int) $r->total,
            ])
            ->all();
    }

    // -------------------------------------------------------------------------
    // Processing time (by doc type and by admin)
    // -------------------------------------------------------------------------

    public function processingTime(array $range): array
    {
        [$from, $to] = $range;

        // Uses business_minutes, not minutes_processed — same reasoning as
        // by_admin below: minutes_processed is cumulative wall-clock time
        // since requested_at (re-counted on every transition), while
        // business_minutes is the calendar-aware, per-segment duration.
        // Also joins document_request so excludeArchived() has a table to
        // filter on — this query previously had no path to is_archived /
        // deleted_at at all, so archived/deleted requests' processing times
        // silently skewed the "Processing Time" chart.
        // Grouped by logbook_category (falling back to the document type's
        // own name when unset) — see logbookGroupKeyExpression()'s docblock
        // and byDocumentType() for the full rationale. min/avg/max/
        // sample_count are computed directly off the raw history rows
        // within each group, so a rolled-up category's numbers are a true
        // aggregate across every underlying document type, not an average
        // of pre-computed per-document averages.
        $groupKey = self::logbookGroupKeyExpression('dt');

        $byDocTypeQuery = DB::table('request_history as rh')
            ->join('request_document as rd', 'rh.request_id', '=', 'rd.request_id')
            ->join('document_type as dt', 'rd.document_type_id', '=', 'dt.document_type_id')
            ->leftJoin('logbook_category as lc', 'dt.logbook_category_id', '=', 'lc.logbook_category_id')
            ->join('document_request as dr', 'rh.request_id', '=', 'dr.request_id')
            ->whereBetween('rh.changed_at', [$from, $to])
            ->whereNotNull('rh.business_minutes');

        $byDocType = $this->excludeFromProcessingTimeMetrics($byDocTypeQuery, 'dr')
            ->select(
                DB::raw('MIN(dt.document_type_id) as document_type_id'),
                'dt.logbook_category_id',
                DB::raw('COALESCE(MAX(lc.name), MAX(dt.document_name)) as document_name'),
                DB::raw('COUNT(DISTINCT dt.document_type_id) as document_type_count'),
                DB::raw('ROUND(MIN(rh.business_minutes), 1) as min_minutes'),
                DB::raw('ROUND(AVG(rh.business_minutes), 1) as avg_minutes'),
                DB::raw('ROUND(MAX(rh.business_minutes), 1) as max_minutes'),
                DB::raw('COUNT(*) as sample_count')
            )
            ->groupBy(DB::raw($groupKey), 'dt.logbook_category_id')
            ->orderBy('avg_minutes')
            ->get();

        // Staff Performance panel (per-admin Requests Handled / Avg
        // Processing Time). Two correctness fixes vs. the naive version:
        //
        //   1. avg_minutes uses rh.business_minutes, NOT rh.minutes_processed.
        //      minutes_processed is cumulative wall-clock time since the
        //      request's original requested_at, recomputed on every single
        //      transition (see DocumentRequestService::recordStatusHistory's
        //      doc block) — so a request that sat untouched for days before
        //      an admin finally moved it gets that entire wait attributed to
        //      that admin, like a relay runner timed for the whole race
        //      instead of just their leg. business_minutes is the additive,
        //      per-segment duration (time since the *previous* status change,
        //      or since requested_at for the first transition) already
        //      computed and stored for this exact purpose — no new column or
        //      backfill needed, it just wasn't being read here yet.
        //
        //      business_minutes is also calendar-aware (office hours only,
        //      excludes weekends/holidays), which is what we want for a
        //      staff performance number: it doesn't penalize an admin for a
        //      request sitting overnight, only for time they actually held
        //      it during business hours.
        //
        //      whereNotNull('rh.business_minutes') below also takes care of
        //      "reset old records": business_minutes was added as a nullable
        //      column and is NULL on any history row written before that
        //      migration, so those rows simply drop out of this average
        //      instead of polluting it with the old cumulative numbers.
        //
        //   2. requests_handled counts COUNT(DISTINCT rh.request_id), not
        //      COUNT(*). Every request logs one history row per status
        //      transition, so COUNT(*) credited an admin once per step
        //      instead of once per request — a single 4-step request handled
        //      by one admin end-to-end was counted as 4 "requests handled".
        //
        // Plan Step 1a — min_minutes / max_minutes / sample_count added here,
        // mirroring by_document_type above: a bare average hides whether an
        // admin's "requests handled" were consistently fast or a mix of very
        // fast and very slow ones. sample_count is COUNT(*) (transitions
        // averaged), distinct from requests_handled (COUNT(DISTINCT), see
        // point 2 above) — deliberately different numbers for different
        // questions ("how many samples went into this average" vs. "how
        // many requests did this admin handle").
        //
        // Joins document_request so excludeArchived() has a table to filter
        // on — same gap as by_document_type above: this query had no path
        // to is_archived / deleted_at, so an admin's stats included work
        // done on requests that are now archived or soft-deleted.
        $byAdminQuery = DB::table('request_history as rh')
            ->join('users as u', 'rh.changed_by', '=', 'u.user_id')
            ->leftJoin('admin_profile as ap', 'u.user_id', '=', 'ap.user_id')
            ->join('document_request as dr', 'rh.request_id', '=', 'dr.request_id')
            ->whereBetween('rh.changed_at', [$from, $to])
            ->whereNotNull('rh.business_minutes');

        $byAdmin = $this->excludeFromProcessingTimeMetrics($byAdminQuery, 'dr')
            ->select(
                'u.user_id',
                'u.email',
                DB::raw("CONCAT(COALESCE(ap.first_name,''), ' ', COALESCE(ap.last_name,'')) as display_name"),
                DB::raw('ROUND(MIN(rh.business_minutes), 1) as min_minutes'),
                DB::raw('ROUND(AVG(rh.business_minutes), 1) as avg_minutes'),
                DB::raw('ROUND(MAX(rh.business_minutes), 1) as max_minutes'),
                DB::raw('COUNT(*) as sample_count'),
                DB::raw('COUNT(DISTINCT rh.request_id) as requests_handled'),
                // Plan Step 1b — distinct calendar days (in the display
                // timezone, same conversion as everywhere else in this file)
                // this admin touched at least one request. Used below to
                // compute a rate metric (requests handled per active day)
                // instead of a raw count over the whole selected range,
                // which would make an admin who worked 3 of 30 days look
                // "slow" purely because most of the range wasn't theirs to
                // work at all.
                DB::raw('COUNT(DISTINCT ' . self::dateExpression('rh.changed_at') . ') as active_days')
            )
            ->groupBy('u.user_id', 'u.email', 'ap.first_name', 'ap.last_name')
            ->orderBy('avg_minutes')
            ->get();

        // Plan Step 1c — rework/quality signal. Chosen proxy (per the plan's
        // open question 1): count of requests each admin touched in-range
        // that are currently Forfeited (never claimed within the SLA
        // window), as a fraction of the requests they handled. This is a
        // signal, not a verdict — forfeiture is frequently outside staff
        // control (a student simply never returns) — so it's surfaced as a
        // rate alongside the other metrics rather than as a standalone
        // "quality score." The plan's alternative proxy ("re-touched after
        // leaving their hands") would need a self-join across ordered
        // history rows per request and is left as a follow-up if this proxy
        // turns out to be too noisy in practice.
        $forfeitedCounts = $this->excludeArchived(
            DB::table('request_history as rh')
                ->join('document_request as dr', 'rh.request_id', '=', 'dr.request_id')
                ->whereBetween('rh.changed_at', [$from, $to])
                ->whereNotNull('rh.changed_by')
                ->where('dr.status_id', RequestStatusEnum::Forfeited->value),
            'dr'
        )
            ->select('rh.changed_by as user_id', DB::raw('COUNT(DISTINCT rh.request_id) as forfeited_count'))
            ->groupBy('rh.changed_by')
            ->get()
            ->keyBy('user_id');

        // Post-aggregate ratios (rate-per-active-day, forfeit rate) are
        // computed here in PHP rather than as SQL expressions — they're
        // simple divisions of two already-aggregated columns, and doing it
        // here avoids a driver-specific "safe divide by zero" SQL
        // expression for what's a two-line null check in PHP.
        $byAdmin = $byAdmin->map(function ($row) use ($forfeitedCounts) {
            $row->requests_per_active_day = $row->active_days > 0
                ? round($row->requests_handled / $row->active_days, 1)
                : null;

            $forfeited = (int) ($forfeitedCounts[$row->user_id]->forfeited_count ?? 0);
            $row->forfeited_count = $forfeited;
            $row->forfeit_rate = $row->requests_handled > 0
                ? round(($forfeited / $row->requests_handled) * 100, 1)
                : 0.0;

            return $row;
        });

        return [
            'by_document_type' => $byDocType,
            'by_admin'         => $byAdmin,
        ];
    }

    /**
     * Splits elapsed time into the two SLA clocks introduced alongside the
     * PendingSignature status (RequestStatusEnum::PendingSignature):
     *
     *   - registrar_time: business-hours-aware duration of segments where
     *     old_status_id = Processing — i.e. time the Registrar itself
     *     controlled, ending the moment they moved a request to either
     *     ReadyToClaim directly or PendingSignature. This is the fair
     *     number for the Registrar's own performance report; it no longer
     *     includes time spent waiting on an external signatory.
     *
     *   - signature_time: business-hours-aware duration of segments where
     *     old_status_id = PendingSignature — i.e. time an external signing
     *     office (dean, department head, etc.) held the request before it
     *     moved to ReadyToClaim. Grouped by document type only for now;
     *     once individual signing offices are tracked as their own entity
     *     (see the calendar-per-office note in DocumentRequestService::
     *     recordStatusHistory()), this can be grouped by office too.
     *
     * Both use business_minutes (calendar-aware: office hours only,
     * weekends/holidays excluded), not minutes_processed (raw wall-clock,
     * cumulative since requested_at) — see the doc block on migration
     * 2026_08_15_000000_add_pending_signature_status for why the two
     * columns mean different things and aren't interchangeable here.
     *
     * Requests that never went through PendingSignature simply have zero
     * matching rows in signature_time — this method doesn't assume every
     * request needs a signature, only reports on the ones that did.
     */
    public function signatureTurnaroundTime(array $range): array
    {
        [$from, $to] = $range;

        // Both queries below join document_request so excludeArchived() has
        // a table to filter on — neither had a path to is_archived /
        // deleted_at previously, so SLA turnaround stats included segments
        // from requests that are now archived or soft-deleted.
        // Both grouped by logbook_category (falling back to the document
        // type's own name when unset) — see logbookGroupKeyExpression()'s
        // docblock and byDocumentType() for the full rationale.
        $groupKey = self::logbookGroupKeyExpression('dt');

        $registrarTimeQuery = DB::table('request_history as rh')
            ->join('request_document as rd', 'rh.request_id', '=', 'rd.request_id')
            ->join('document_type as dt', 'rd.document_type_id', '=', 'dt.document_type_id')
            ->leftJoin('logbook_category as lc', 'dt.logbook_category_id', '=', 'lc.logbook_category_id')
            ->join('document_request as dr', 'rh.request_id', '=', 'dr.request_id')
            ->where('rh.old_status_id', RequestStatusEnum::Processing->value)
            ->whereBetween('rh.changed_at', [$from, $to])
            ->whereNotNull('rh.business_minutes');

        $registrarTime = $this->excludeFromProcessingTimeMetrics($registrarTimeQuery, 'dr')
            ->select(
                DB::raw('MIN(dt.document_type_id) as document_type_id'),
                'dt.logbook_category_id',
                DB::raw('COALESCE(MAX(lc.name), MAX(dt.document_name)) as document_name'),
                DB::raw('COUNT(DISTINCT dt.document_type_id) as document_type_count'),
                DB::raw('ROUND(MIN(rh.business_minutes), 1) as min_minutes'),
                DB::raw('ROUND(AVG(rh.business_minutes), 1) as avg_minutes'),
                DB::raw('ROUND(MAX(rh.business_minutes), 1) as max_minutes'),
                DB::raw('COUNT(*) as sample_count')
            )
            ->groupBy(DB::raw($groupKey), 'dt.logbook_category_id')
            ->orderBy('avg_minutes')
            ->get();

        $signatureTimeQuery = DB::table('request_history as rh')
            ->join('request_document as rd', 'rh.request_id', '=', 'rd.request_id')
            ->join('document_type as dt', 'rd.document_type_id', '=', 'dt.document_type_id')
            ->leftJoin('logbook_category as lc', 'dt.logbook_category_id', '=', 'lc.logbook_category_id')
            ->join('document_request as dr', 'rh.request_id', '=', 'dr.request_id')
            ->where('rh.old_status_id', RequestStatusEnum::PendingSignature->value)
            ->whereBetween('rh.changed_at', [$from, $to])
            ->whereNotNull('rh.business_minutes');

        $signatureTime = $this->excludeFromProcessingTimeMetrics($signatureTimeQuery, 'dr')
            ->select(
                DB::raw('MIN(dt.document_type_id) as document_type_id'),
                'dt.logbook_category_id',
                DB::raw('COALESCE(MAX(lc.name), MAX(dt.document_name)) as document_name'),
                DB::raw('COUNT(DISTINCT dt.document_type_id) as document_type_count'),
                DB::raw('ROUND(MIN(rh.business_minutes), 1) as min_minutes'),
                DB::raw('ROUND(AVG(rh.business_minutes), 1) as avg_minutes'),
                DB::raw('ROUND(MAX(rh.business_minutes), 1) as max_minutes'),
                DB::raw('COUNT(*) as sample_count')
            )
            ->groupBy(DB::raw($groupKey), 'dt.logbook_category_id')
            ->orderBy('avg_minutes')
            ->get();

        return [
            'registrar_time' => $registrarTime,
            'signature_time' => $signatureTime,
        ];
    }

    // -------------------------------------------------------------------------
    // Peak hours heatmap
    // -------------------------------------------------------------------------

    public function peakHours(array $range): array
    {
        [$from, $to] = $range;

        $rows = DocumentRequest::select(
                DB::raw(self::hourExpression('requested_at') . ' as hour'),
                DB::raw('COUNT(*) as total')
            )
            ->whereBetween('requested_at', [$from, $to])
            ->groupBy('hour')
            ->orderBy('hour')
            ->pluck('total', 'hour');

        $hours = [];
        for ($h = 0; $h < 24; $h++) {
            $hours[] = [
                'hour'  => $h,
                'label' => sprintf('%02d:00', $h),
                'total' => (int) ($rows[$h] ?? 0),
            ];
        }

        return $hours;
    }

    // -------------------------------------------------------------------------
    // Requests by purpose
    // -------------------------------------------------------------------------

    public function byPurpose(array $range): array
    {
        [$from, $to] = $range;

        $query = DB::table('document_request as dr')
            ->join('request_purpose as rp', 'dr.request_purpose_id', '=', 'rp.request_purpose_id')
            ->whereBetween('dr.requested_at', [$from, $to]);

        return $this->excludeArchived($query, 'dr')
            ->select(
                'rp.request_purpose_id',
                'rp.purpose_name',
                DB::raw('COUNT(*) as total')
            )
            ->groupBy('rp.request_purpose_id', 'rp.purpose_name')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => [
                'purpose_id'   => $r->request_purpose_id,
                'purpose_name' => $r->purpose_name,
                'total'        => (int) $r->total,
            ])
            ->all();
    }

    // -------------------------------------------------------------------------
    // AI payload builder
    // -------------------------------------------------------------------------

    /**
     * Assemble a fully anonymised statistics payload for the AI narrative.
     *
     * Rules enforced here (the AI firewall):
     *  - No student/alumni names, emails, or IDs.
     *  - Admin display names are included in processing-time stats because
     *    they are staff performance metrics, not student PII. Remove if
     *    institutional policy requires it.
     *  - Only aggregated counts, averages, and rates are included.
     */
    public function buildAiPayload(array $range): array
    {
        $overview       = $this->overview($range);
        $volumeTrend    = $this->volumeTrend($range);
        $byDocType      = $this->byDocumentType($range);
        $byStatus       = $this->byStatus($range);
        $processingTime = $this->processingTime($range);
        $peakHours      = $this->peakHours($range);
        $byPurpose      = $this->byPurpose($range);

        // Strip admin user_id and email from processing stats — keep only
        // display_name and performance numbers. Includes the Step 1a–1c
        // metrics (min/max spread, rate-per-active-day, forfeit rate) so
        // the narrative can speak to workload and outcomes, not just a
        // single "fastest admin" average.
        $adminPerf = collect($processingTime['by_admin'])->map(fn ($a) => [
            'name'                    => trim($a->display_name) ?: 'Unknown',
            'avg_minutes'             => $a->avg_minutes,
            'min_minutes'             => $a->min_minutes,
            'max_minutes'             => $a->max_minutes,
            'requests_handled'        => $a->requests_handled,
            'requests_per_active_day' => $a->requests_per_active_day,
            'forfeit_rate'            => $a->forfeit_rate,
        ])->values()->all();

        // Peak hours — only top 5 busiest to keep payload compact
        $topHours = collect($peakHours)
            ->sortByDesc('total')
            ->take(5)
            ->map(fn ($h) => ['hour' => $h['label'], 'requests' => $h['total']])
            ->values()
            ->all();

        return [
            'report_period' => [
                'from' => $range[0]->toDateString(),
                'to'   => $range[1]->toDateString(),
            ],
            'overview'              => $overview,
            'monthly_volume_trend'  => $volumeTrend,
            'top_document_types'    => array_slice($byDocType, 0, 10),
            'status_breakdown'      => $byStatus,
            'processing_time' => [
                'by_document_type' => $processingTime['by_document_type'],
                'by_admin'         => $adminPerf,
            ],
            'peak_hours_top5'       => $topHours,
            'requests_by_purpose'   => $byPurpose,
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Return a SQL expression that formats a datetime column as 'YYYY-MM-DD'
     * in the display timezone. Used to count an admin's distinct active
     * working days (Step 1b) — the same local-timezone conversion as
     * monthExpression()/hourExpression() above, truncated to day instead of
     * month/hour, so a transition made at 11:30 PM local time is bucketed
     * onto the correct calendar day rather than the UTC one.
     */
    private static function dateExpression(string $column): string
    {
        $driver = DB::connection()->getDriverName();
        $local  = self::localExpression($column, $driver);

        return match ($driver) {
            'sqlite' => "date({$local})",
            'pgsql'  => "to_char({$local}, 'YYYY-MM-DD')",
            default  => "DATE_FORMAT({$local}, '%Y-%m-%d')",  // MySQL / MariaDB
        };
    }

    /**
     * Return a SQL expression that formats a datetime column as 'YYYY-MM'.
     *
     * Portable across MySQL/MariaDB (default), SQLite (tests/local), and
     * PostgreSQL (future migration path).
     *
     * IMPORTANT: columns are stored in UTC (config('app.timezone')), but a
     * request made at 11:30 PM local time on the last day of the month is
     * still ~3:30 PM UTC the same day, and one made at 12:30 AM local time
     * on the 1st is ~4:30 PM UTC the day before. Near month boundaries that
     * can push a request into the wrong month bucket, so we convert to the
     * local display timezone first, same as hourExpression() below.
     */
    private static function monthExpression(string $column): string
    {
        $driver = DB::connection()->getDriverName();
        $local  = self::localExpression($column, $driver);

        return match ($driver) {
            'sqlite' => "strftime('%Y-%m', {$local})",
            'pgsql'  => "to_char({$local}, 'YYYY-MM')",
            default  => "DATE_FORMAT({$local}, '%Y-%m')",  // MySQL / MariaDB
        };
    }

    /**
     * Return a SQL expression for the local (display-timezone) hour of a
     * UTC-stored datetime column, as an integer 0-23.
     *
     * Bug this fixes: extracting HOUR()/strftime('%H', ...) directly off a
     * UTC column reports the *UTC* hour, not the hour the request actually
     * happened in for users/staff (Asia/Manila, UTC+8). A 1-2 PM local
     * request is stored as 5-6 AM UTC and would otherwise show up as if it
     * happened before dawn. We convert to the configured display timezone
     * first, then extract the hour.
     */
    private static function hourExpression(string $column): string
    {
        $driver = DB::connection()->getDriverName();
        $local  = self::localExpression($column, $driver);

        return match ($driver) {
            'sqlite' => "CAST(strftime('%H', {$local}) AS INTEGER)",
            'pgsql'  => "EXTRACT(HOUR FROM {$local})",
            default  => "HOUR({$local})",  // MySQL / MariaDB
        };
    }

    /**
     * Return a SQL expression that converts a UTC-stored datetime column to
     * the application's configured display timezone (config('app.display_timezone'),
     * default Asia/Manila). Centralised here so every "what local hour/day
     * did this happen on" query converts the same way, once.
     *
     * MySQL: CONVERT_TZ with a fixed UTC offset (e.g. '+08:00') rather than
     * a named zone, so this works even when the server's mysql.time_zone
     * tables haven't been loaded (common on managed DB hosts).
     */
    private static function localExpression(string $column, string $driver): string
    {
        $timezone   = config('app.display_timezone', 'Asia/Manila');
        $offsetSecs = self::utcOffsetSeconds($timezone);

        return match ($driver) {
            // SQLite modifiers want '+N minutes', not a '+HH:MM' string.
            'sqlite' => "datetime({$column}, '" . self::sqliteMinutesModifier($offsetSecs) . "')",
            'pgsql'  => "(({$column} AT TIME ZONE 'UTC') AT TIME ZONE '{$timezone}')",
            // Fixed offset (not a named zone) so this doesn't depend on the
            // mysql.time_zone_name tables being loaded on the DB host.
            default  => "CONVERT_TZ({$column}, '+00:00', '" . self::hhmmOffset($offsetSecs) . "')",
        };
    }

    /**
     * Current UTC offset in seconds for a timezone name. Computed from PHP's
     * timezone database rather than hardcoded so it stays correct if the
     * configured timezone ever observes DST.
     */
    private static function utcOffsetSeconds(string $timezone): int
    {
        $tz = new \DateTimeZone($timezone);

        return $tz->getOffset(new \DateTime('now', new \DateTimeZone('UTC')));
    }

    /** Format offset seconds as e.g. '+08:00', for MySQL CONVERT_TZ. */
    private static function hhmmOffset(int $offsetSecs): string
    {
        $sign = $offsetSecs < 0 ? '-' : '+';
        $abs  = abs($offsetSecs);

        return sprintf('%s%02d:%02d', $sign, intdiv($abs, 3600), intdiv($abs % 3600, 60));
    }

    /** Format offset seconds as e.g. '+480 minutes', for SQLite datetime(). */
    private static function sqliteMinutesModifier(int $offsetSecs): string
    {
        $minutes = intdiv($offsetSecs, 60);
        $sign    = $minutes < 0 ? '-' : '+';

        return sprintf('%s%d minutes', $sign, abs($minutes));
    }
}