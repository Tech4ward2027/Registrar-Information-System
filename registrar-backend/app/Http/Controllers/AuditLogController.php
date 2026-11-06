<?php

namespace App\Http\Controllers;

use App\Http\Requests\PaginatedIndexRequest;
use App\Models\AuditLog;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class AuditLogController extends Controller
{
    // -------------------------------------------------------
    // GET /audit-logs
    //
    // Supports:
    //   ?search=juan          → matches ANY of: actor email, role,
    //                           action (raw or human-readable), target
    //                           user's email, browser
    //   ?role=admin           → filter by role_name
    //   ?action=login         → filter by action
    //   ?browser=Chrome       → filter by browser
    //   ?from=2026-08-01      → created_at >= start of this date in
    //                           the display timezone (Asia/Manila)
    //   ?to=2026-08-20        → created_at <= end of this date in
    //                           the display timezone (Asia/Manila) —
    //                           i.e. the ENTIRE day is included, not
    //                           just up to 00:00
    //   ?per_page=20          → pagination (default 20)
    //
    // Example: GET /audit-logs?role=admin&action=login&from=2026-08-01&to=2026-08-20&per_page=50
    //
    // Timezone note: created_at is stored in UTC (config('app.timezone')).
    // `from`/`to` are calendar dates in the university's local timezone
    // (config('app.display_timezone'), Asia/Manila) — a Super Admin typing
    // "Aug 20" means the Manila calendar day, not the UTC one, which can
    // differ by up to 8 hours at the boundaries. See resolveDateBoundary()
    // below for how that's converted into a UTC instant for comparison
    // against the stored column.
    //
    // Phase 4 — cashier_verification_enriched rows are deliberately
    // excluded from this listing (see the WHERE below). They're a
    // system-written follow-up to an existing cashier_verification row,
    // not an independent event a Super Admin would search/filter for on
    // their own — per the plan's Phase 4d intent ("no new page, just
    // richer detail on an existing record"). They're folded back into
    // their parent row's `enrichment` field by fetchEnrichmentsFor()
    // below instead. The rows themselves are never deleted or hidden
    // from the database — audit_logs stays a complete, queryable
    // compliance record; they're just not surfaced as their own list
    // entry here.
    // -------------------------------------------------------
    public function index(PaginatedIndexRequest $request)
    {
        $query = AuditLog::query()
            ->where('action', '!=', AuditLog::ACTION_CASHIER_VERIFICATION_ENRICHED)
            ->orderByDesc('created_at');

        // Search across every field a Super Admin would plausibly type
        // into a single search box: actor email, role, action, target
        // user's email, and browser. A single free-text box that only
        // matched email was surprising — typing an admin's role or the
        // browser they used returned nothing even though that data is
        // right there in the table.
        //
        // `action` is matched two ways: the raw stored value (e.g.
        // 'admin_created') and a space-normalized version (spaces →
        // underscores, e.g. typing "Admin Created" still matches),
        // since the column stores snake_case but the UI displays the
        // human-readable label.
        if ($search = trim((string) $request->query('search', ''))) {
            $actionSearch = str_replace(' ', '_', $search);

            $query->where(function ($q) use ($search, $actionSearch) {
                $q->where('email', 'like', "%{$search}%")
                  ->orWhere('role_name', 'like', "%{$search}%")
                  ->orWhere('action', 'like', "%{$search}%")
                  ->orWhere('action', 'like', "%{$actionSearch}%")
                  ->orWhere('target_email', 'like', "%{$search}%")
                  ->orWhere('browser', 'like', "%{$search}%");
            });
        }

        // Filter by role
        if ($role = $request->query('role')) {
            $query->where('role_name', $role);
        }

        // Filter by action
        if ($action = $request->query('action')) {
            $query->where('action', $action);
        }

        // Filter by browser
        if ($browser = $request->query('browser')) {
            $query->where('browser', $browser);
        }

        // Date range — from/to are Manila calendar dates, converted to
        // the equivalent UTC instant boundaries before comparing against
        // the UTC-stored created_at column. See resolveDateBoundary().
        if ($from = $request->query('from')) {
            $fromUtc = $this->resolveDateBoundary($from, startOfDay: true);
            if ($fromUtc) {
                $query->where('created_at', '>=', $fromUtc);
            }
        }

        if ($to = $request->query('to')) {
            $toUtc = $this->resolveDateBoundary($to, startOfDay: false);
            if ($toUtc) {
                $query->where('created_at', '<=', $toUtc);
            }
        }

        $perPage = $request->perPage(20);

        $logs = $query->paginate($perPage);

        $enrichmentByCashierLogId = $this->fetchEnrichmentsFor($logs->getCollection());

        return response()->json([
            'data' => $logs->map(fn($log) => $this->format($log, $enrichmentByCashierLogId)),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page'    => $logs->lastPage(),
                'per_page'     => $logs->perPage(),
                'total'        => $logs->total(),
            ],
        ]);
    }

    // -------------------------------------------------------
    // Convert a 'YYYY-MM-DD' calendar date, interpreted in the display
    // timezone (Asia/Manila), into the equivalent UTC instant at either
    // the start or end of that day — so a range filter compares apples
    // to apples against the UTC-stored created_at column.
    //
    // Why not whereDate('created_at', $date) or CONVERT_TZ in SQL: this
    // resolves the boundary ONCE in PHP and compares the raw UTC column
    // directly, so it stays a plain indexed range comparison (sargable)
    // on every driver (MySQL/SQLite/Postgres) without depending on the
    // DB's timezone tables being loaded — same reasoning as
    // AnalyticsService's own display-timezone conversion, just applied
    // to a range boundary instead of a per-row GROUP BY bucket.
    //
    // Returns null (filter silently skipped) on unparseable input rather
    // than throwing — a malformed date query param shouldn't 500 the
    // whole audit log page.
    // -------------------------------------------------------
    private function resolveDateBoundary(string $date, bool $startOfDay): ?Carbon
    {
        $displayTimezone = config('app.display_timezone', 'Asia/Manila');

        try {
            $local = Carbon::parse($date, $displayTimezone);
        } catch (\Exception $e) {
            return null;
        }

        $local = $startOfDay ? $local->startOfDay() : $local->endOfDay();

        return $local->setTimezone('UTC');
    }

    // -------------------------------------------------------
    // GET /audit-logs/filters
    //
    // Returns the distinct values available for each filter
    // dropdown in your UI (Role, Action, Browser).
    // Call this once on page load to populate the dropdowns.
    //
    // cashier_verification_enriched excluded here too — it's not a
    // filterable, independently-viewed action (see index() above).
    // -------------------------------------------------------
    public function filters()
    {
        return response()->json([
            'roles'    => AuditLog::distinct()->pluck('role_name')->sort()->values(),
            'actions'  => AuditLog::where('action', '!=', AuditLog::ACTION_CASHIER_VERIFICATION_ENRICHED)
                                  ->distinct()->pluck('action')->sort()->values(),
            'browsers' => AuditLog::distinct()->whereNotNull('browser')
                                  ->pluck('browser')->sort()->values(),
        ]);
    }

    // -------------------------------------------------------
    // Phase 4 — batch-fetch enrichment rows for every
    // cashier_verification entry on the current page, in one query.
    //
    // Deliberately NOT a per-row query (N+1): $logs is already bounded to
    // at most 100 rows (per_page cap above), so one IN(...) query against
    // an indexed (action, created_at) pair, filtered further by a JSON
    // path WHERE on metadata->source_audit_log_id, stays cheap regardless
    // of how many cashier_verification rows land on a given page. Same
    // Laravel JSON-path `where()` convention already used elsewhere
    // against this column (see SuperAdminAnalyticsService).
    //
    // @return Collection<int, AuditLog> keyed by the ORIGINAL
    //         cashier_verification row's id.
    // -------------------------------------------------------
    private function fetchEnrichmentsFor(Collection $logs): Collection
    {
        $cashierVerificationIds = $logs
            ->where('action', AuditLog::ACTION_CASHIER_VERIFICATION)
            ->pluck('id');

        if ($cashierVerificationIds->isEmpty()) {
            return collect();
        }

        return AuditLog::where('action', AuditLog::ACTION_CASHIER_VERIFICATION_ENRICHED)
            ->whereIn('metadata->source_audit_log_id', $cashierVerificationIds)
            ->get()
            ->keyBy(fn(AuditLog $entry) => $entry->metadata['source_audit_log_id'] ?? null);
    }

    // -------------------------------------------------------
    // Format a single log entry for the frontend.
    // Matches your UI columns: User, Role, Action, Browser, Date, Time
    //
    // `action_key` is the raw, stable action constant (e.g.
    // 'cashier_verification') alongside the human-readable `action`
    // label — the frontend branches on this to decide whether to render
    // the cashier-verification detail panel, rather than string-matching
    // the formatted label (which is meant to change/localize freely).
    //
    // `metadata` and `enrichment` are only ever populated for
    // cashier_verification rows — every other action's metadata is left
    // out of this response on purpose. This keeps the payload small and
    // avoids incidentally exposing metadata shapes from other actions
    // (e.g. admin management) that the frontend has no UI for yet and
    // hasn't been reviewed for what's safe to surface wholesale.
    // -------------------------------------------------------
    private function format(AuditLog $log, Collection $enrichmentByCashierLogId): array
    {
        $isCashierVerification = $log->action === AuditLog::ACTION_CASHIER_VERIFICATION;

        // created_at is stored in UTC; convert to the display timezone
        // (Asia/Manila) before formatting so the timestamp shown to a
        // Super Admin matches the local wall-clock time the action
        // actually happened at, not the UTC one.
        $localTimestamp = $log->created_at->copy()
            ->setTimezone(config('app.display_timezone', 'Asia/Manila'));

        return [
            'id'         => $log->id,
            'user'       => $log->email,
            'role'       => $log->role_name,
            'action'     => $this->formatAction($log->action),
            'action_key' => $log->action,
            'browser'    => $log->browser ?? 'Unknown',
            'ip_address' => $log->ip_address,
            'date'       => $localTimestamp->format('Y-m-d'),
            'time'       => $localTimestamp->format('H:i:s'),
            'metadata'   => $isCashierVerification ? $log->metadata : null,
            'enrichment' => $isCashierVerification
                ? $this->formatEnrichment($enrichmentByCashierLogId->get($log->id))
                : null,
        ];
    }

    /**
     * @param AuditLog|null $enrichmentLog
     */
    private function formatEnrichment(?AuditLog $enrichmentLog): ?array
    {
        if (!$enrichmentLog) {
            // No enrichment row yet — either the original verification
            // succeeded (nothing to enrich) or NOT_FOUND enrichment is
            // still queued/in-flight. The frontend distinguishes these
            // two cases using the parent row's own metadata.final_approved.
            return null;
        }

        $meta = $enrichmentLog->metadata ?? [];

        return [
            'source_system'     => $meta['source_system']     ?? null,
            'on_file_snapshot'  => $meta['on_file_snapshot']  ?? null,
            'enrichment_status' => $meta['enrichment_status'] ?? 'failed',
            'failure_reason'    => $meta['failure_reason']    ?? null,
            'enriched_at'       => $enrichmentLog->created_at->toIso8601String(),
        ];
    }

    // -------------------------------------------------------
    // Convert action constants to readable labels for the UI.
    // e.g. 'admin_created' → 'Admin Created'
    // -------------------------------------------------------
    private function formatAction(string $action): string
    {
        return match ($action) {
            AuditLog::ACTION_LOGIN                  => 'Login',
            AuditLog::ACTION_LOGOUT                 => 'Logout',
            AuditLog::ACTION_ADMIN_CREATED          => 'Admin Created',
            AuditLog::ACTION_ADMIN_DELETED          => 'Admin Deleted',
            AuditLog::ACTION_ROLE_ASSIGNED          => 'Role Assigned',
            AuditLog::ACTION_ROLE_REVOKED           => 'Role Revoked',
            AuditLog::ACTION_ROLE_EXPIRED           => 'Role Expired',
            AuditLog::ACTION_ROLE_SWITCHED          => 'Role Switched',
            AuditLog::ACTION_REQUEST_STATUS_CHANGED => 'Request Status Changed',
            AuditLog::ACTION_CASHIER_VERIFICATION   => 'Cashier Verification',
            default                                 => ucwords(str_replace('_', ' ', $action)),
        };
    }
}