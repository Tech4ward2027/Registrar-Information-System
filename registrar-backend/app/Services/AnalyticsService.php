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
    /**
     * Per-instance memo of the processing-time aggregate, keyed by range.
     * overview(), byDocumentType(), processingTime() and signatureTurnaroundTime()
     * all read the same pass, so a caller that asks for several of them in one
     * go (buildAiPayload) pays for the history walk once.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $processingAggregates = [];

    public function __construct(private AnalyticsProcessingMetrics $processingMetrics) {}

    /**
     * Processing-time figures for a range, from the shared per-transaction
     * measurement (ProcessingTimeService) — see AnalyticsProcessingMetrics.
     *
     * @param  array{0: \Carbon\CarbonInterface, 1: \Carbon\CarbonInterface}  $range
     * @return array<string, mixed>
     */
    private function processingAggregate(array $range): array
    {
        $key = $range[0]->getTimestamp() . ':' . $range[1]->getTimestamp();

        return $this->processingAggregates[$key] ??= $this->processingMetrics->aggregate($range);
    }

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

        // Mean processing time per transaction (one document/certificate
        // line), from the shared ProcessingTimeService rule — the same figure
        // the logbook and the OPCR report use. Replaces the old average of
        // stored per-segment minutes, which included Awaiting Submission and
        // the signing wait. Null when nothing was processed in the range.
        $avgProcessing = $this->processingAggregate($range)['overall']['avg_minutes'];

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
            'avg_processing_minutes' => $avgProcessing,
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

        // Average processing time per logbook group comes from the shared
        // per-transaction measurement (see processingAggregate()), keyed the
        // same way as the rows below: 'category:<id>' for a logbook category,
        // 'document:<id>' for an uncategorized document type. It is a true
        // weighted mean across every transaction in the group, and it follows
        // the selected date range (a transaction belongs to the period in
        // which its processing finished).
        $averages = collect($this->processingAggregate($range)['by_group'])->keyBy('group_key');

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
                DB::raw('SUM(rd.number_of_copies) as total_copies')
            )
            ->groupBy(DB::raw($outerGroupKey), 'dt.logbook_category_id')
            ->orderByDesc('total_documents')
            ->get();

        return $rows->map(function ($row) use ($averages) {
            $groupKey = $row->logbook_category_id !== null
                ? 'category:' . $row->logbook_category_id
                : 'document:' . $row->document_type_id;

            return [
                'document_type_id'    => $row->document_type_id,
                'logbook_category_id' => $row->logbook_category_id,
                'document_name'       => $row->document_name,
                'document_type_count' => (int) $row->document_type_count,
                'total_documents'     => (int) $row->total_documents,
                'total_copies'        => (int) $row->total_copies,
                'avg_processing_min'  => data_get($averages->get($groupKey), 'avg_minutes'),
            ];
        })->all();
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
        // Both panels read the shared per-transaction measurement
        // (AnalyticsProcessingMetrics -> ProcessingTimeService): the minutes
        // the Registrar itself worked on a document, office hours only, from
        // filing to Pending Signature (or Ready to Claim when no signature is
        // needed), with Awaiting Submission, the signing wait and pickup never
        // counted. Replaces averages of the stored per-segment business_minutes.
        //
        // by_document_type: grouped by logbook category (falling back to the
        // document/certificate type's own name) — a true aggregate across the
        // group's transactions, not an average of per-type averages.
        //
        // by_admin: each transaction is credited to the staff member who moved
        // it into the stopping status. requests_handled counts distinct
        // requests, sample_count the transactions averaged, active_days the
        // distinct display-timezone days that person finished work, and
        // forfeited_count/forfeit_rate the credited requests that later
        // expired unclaimed (a signal, not a verdict — forfeiture is often
        // outside staff control).
        $aggregate = $this->processingAggregate($range);

        return [
            'by_document_type' => $aggregate['by_group'],
            'by_admin'         => $aggregate['by_admin'],
        ];
    }

    /**
     * Splits elapsed time into the two SLA clocks introduced alongside the
     * PendingSignature status (RequestStatusEnum::PendingSignature). Both are
     * derived per document/certificate line from the history TIMESTAMPS by
     * ProcessingTimeService (office hours only: weekends, holidays, Monday WFH
     * and closures excluded) and grouped by logbook category, falling back to
     * the type's own name. The stored request_history.business_minutes column
     * is not read, so old rows with inaccurate stored values are measured
     * correctly with no backfill.
     *
     *   - registrar_time: the time the Registrar itself controlled, from filing
     *     to Pending Signature (or Ready to Claim when no signature is needed),
     *     with Awaiting Submission paused. Identical to processingTime()'s
     *     by_document_type, so the two panels always agree. Counted in the
     *     period in which the registrar's part finished.
     *
     *   - signature_time: the time an external signing office (dean,
     *     department head, etc.) held the document in Pending Signature, for
     *     documents that needed a signature. Counted in the period in which the
     *     wait finished (the document left Pending Signature). A document still
     *     waiting for its signature has no finished wait yet and is not
     *     averaged. Grouped by document type only for now; once individual
     *     signing offices are tracked as their own entity (see the
     *     calendar-per-office note in DocumentRequestService::
     *     recordStatusHistory()), this can be grouped by office too.
     *
     * Archived, soft-deleted, Withdrawn and Closed-Unable-To-Process requests
     * and line items are excluded from both (see AnalyticsProcessingMetrics).
     * Forfeited requests stay in.
     */
    public function signatureTurnaroundTime(array $range): array
    {
        $aggregate = $this->processingAggregate($range);

        return [
            'registrar_time' => $aggregate['by_group'],
            'signature_time' => $aggregate['signature_by_group'],
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
            'name'                    => trim($a['display_name']) ?: 'Unknown',
            'avg_minutes'             => $a['avg_minutes'],
            'min_minutes'             => $a['min_minutes'],
            'max_minutes'             => $a['max_minutes'],
            'requests_handled'        => $a['requests_handled'],
            'requests_per_active_day' => $a['requests_per_active_day'],
            'forfeit_rate'            => $a['forfeit_rate'],
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