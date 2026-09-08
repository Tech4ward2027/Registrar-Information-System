<?php

namespace App\Services;

use App\Models\AccessRequest;
use App\Models\AuditLog;
use App\Models\JobRunLog;
use App\Models\SystemUser;
use App\Models\UnmatchedCashierItem;
use Illuminate\Support\Facades\DB;

/**
 * System-level analytics for the Super Admin role — deliberately separate
 * from AnalyticsService, which is scoped to a single Registrar's document
 * request queue (volume, processing time, staff performance). This service
 * answers a different question: "is the system itself healthy" — roster
 * state, delegated-access throughput, and cross-system verification health
 * — not "how is one queue of requests performing."
 *
 * Per the Analytics & Audit Log Revamp plan, Phase 2:
 *   - System-wide volume/trend is intentionally NOT duplicated here. It's
 *     the same shape as AnalyticsService::overview()/volumeTrend(), and
 *     Super Admin already has access to those endpoints (role:4 bypasses
 *     the role:3 route group via RoleMiddleware) — the frontend calls them
 *     directly rather than this service re-deriving the same numbers.
 *   - "Security posture snapshot" (failed logins, lockouts, IDP-unreachable
 *     count) is intentionally NOT included yet — it depends on the
 *     security_events table proposed in Phase 3, which has not been built.
 *     Add that panel here once Phase 3 lands; do not approximate it against
 *     audit_log in the meantime (that's the exact log-mixing problem Phase
 *     3's design write-up argues against).
 */
class SuperAdminAnalyticsService
{
    // -------------------------------------------------------------------------
    // Admin roster health
    // -------------------------------------------------------------------------

    /**
     * Snapshot of the admin/super-admin population: live role-assignment
     * status breakdown, pending activations (and which are close to
     * expiring), and recent IDP sync failures.
     *
     * Not date-ranged — this is a "right now" snapshot, not a trend. Every
     * other panel in this service accepts a $range because it's counting
     * events that happened in a window (access requests submitted, cashier
     * verifications attempted); roster health is a point-in-time state,
     * so a date range doesn't apply the same way. Pending-activation and
     * IDP-sync-failure timestamps are exposed on the individual rows so
     * the frontend can still show "how recent" without the caller having
     * to pick a window up front.
     */
    public function adminRosterHealth(): array
    {
        $adminRoleIds = [SystemUser::ROLE_ADMIN, SystemUser::ROLE_SUPER_ADMIN];

        // ── Role assignment breakdown (live, not the raw persisted status) ──
        //
        // role_assignments also holds a baseline row for every student/
        // alumni account (see 2026_08_10_000001_backfill_role_assignments_
        // from_users.php) — whereIn('role_id', $adminRoleIds) below is not
        // optional, it's what keeps this a roster-health query instead of
        // an accidental full-population scan.
        //
        // "Active" here is computed the same way RoleAssignment::
        // scopeActive() computes it (status = Active AND (no expiry, or
        // expiry still in the future)) rather than trusting the raw
        // `status` column outright — that column is only flipped
        // Active -> Expired once a day by the role-assignments:expire
        // sweep (see RoleAssignment's class docblock, BUG FIX #5), so a
        // row can sit expired-but-unswept for up to ~24h. due_to_expire
        // surfaces exactly that unswept population as its own bucket
        // rather than silently folding it into "Active."
        $now = now();

        $roleBreakdown = DB::table('role_assignments as ra')
            ->join('roles as r', 'ra.role_id', '=', 'r.role_id')
            ->whereIn('ra.role_id', $adminRoleIds)
            ->selectRaw(
                "ra.role_id, r.role_name,
                 SUM(CASE WHEN ra.status = 'Active' AND (ra.expires_at IS NULL OR ra.expires_at > ?) THEN 1 ELSE 0 END) as active_count,
                 SUM(CASE WHEN ra.status = 'Active' AND ra.expires_at IS NOT NULL AND ra.expires_at <= ? THEN 1 ELSE 0 END) as due_to_expire_count,
                 SUM(CASE WHEN ra.status = 'Expired' THEN 1 ELSE 0 END) as expired_count,
                 SUM(CASE WHEN ra.status = 'Revoked' THEN 1 ELSE 0 END) as revoked_count",
                [$now, $now]
            )
            ->groupBy('ra.role_id', 'r.role_name')
            ->get()
            ->map(fn ($row) => [
                'role_id'            => (int) $row->role_id,
                'role_name'          => self::roleLabel($row->role_name),
                'active_count'       => (int) $row->active_count,
                'due_to_expire_count' => (int) $row->due_to_expire_count,
                'expired_count'      => (int) $row->expired_count,
                'revoked_count'      => (int) $row->revoked_count,
            ])
            ->all();

        // ── Pending activations ──
        //
        // 'Pending Activation' = pre-registered in RIS, no matching IdP
        // login yet (see AdminUserService, users.status migration). Not
        // yet an active role assignment at all, so it doesn't show up in
        // the breakdown above — surfaced separately here since a growing
        // pending queue (or one sitting close to its 14-day
        // pending_expires_at window) is exactly the kind of thing a Super
        // Admin wants a heads-up on without digging through Admin
        // Management manually.
        $pendingActivations = SystemUser::whereIn('role_id', $adminRoleIds)
            ->where('status', 'Pending Activation')
            ->orderBy('pending_expires_at')
            ->get(['user_id', 'email', 'role_id', 'pending_expires_at']);

        $pendingExpiringSoon = $pendingActivations
            ->filter(fn ($u) => $u->pending_expires_at && $u->pending_expires_at->isBetween($now, $now->copy()->addDays(3)))
            ->count();

        // ── IDP sync failures ──
        //
        // ACTION_ADMIN_IDP_SYNC_FAILED already exists in the audit log
        // (written wherever an admin's IdP-side create/update call fails)
        // — this just surfaces it here instead of requiring a Super Admin
        // to know to go filter the Audit Trail tab by that action. Limited
        // to the last 30 days: an indefinite lookback would make this
        // panel grow unbounded and stop being a "is something actively
        // wrong" signal.
        $idpSyncFailuresQuery = AuditLog::where('action', AuditLog::ACTION_ADMIN_IDP_SYNC_FAILED)
            ->where('created_at', '>=', $now->copy()->subDays(30));

        $idpSyncFailuresCount30d = (clone $idpSyncFailuresQuery)->count();

        $recentIdpSyncFailures = (clone $idpSyncFailuresQuery)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get(['target_email', 'metadata', 'created_at'])
            ->map(fn ($row) => [
                'target_email' => $row->target_email,
                'reason'       => $row->metadata['reason'] ?? null,
                'created_at'   => $row->created_at,
            ])
            ->all();

        return [
            'role_breakdown' => $roleBreakdown,
            'pending_activations' => [
                'total'         => $pendingActivations->count(),
                'expiring_soon' => $pendingExpiringSoon,
                'items'         => $pendingActivations->map(fn ($u) => [
                    'user_id'           => $u->user_id,
                    'email'             => $u->email,
                    'role_id'           => (int) $u->role_id,
                    'pending_expires_at' => $u->pending_expires_at,
                ])->all(),
            ],
            'idp_sync_failures' => [
                'count_last_30_days' => $idpSyncFailuresCount30d,
                'recent'             => $recentIdpSyncFailures,
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Access request throughput
    // -------------------------------------------------------------------------

    /**
     * Volume and outcome metrics for the self-service access-request queue
     * (AccessRequestService) within the given range.
     *
     * IMPORTANT: AccessRequest::STATUS_APPROVED is a defined constant but
     * is never actually persisted — AccessRequestService::approve() writes
     * status = STATUS_FULFILLED directly (approval IS fulfillment: it
     * creates the SystemUser in the same transaction). Computing
     * "approval rate" against STATUS_APPROVED would therefore always
     * read 0% against real data. approval_rate below is deliberately
     * fulfilled / (fulfilled + rejected) — the two true terminal outcomes
     * of a reviewed request.
     */
    public function accessRequestThroughput(array $range): array
    {
        [$from, $to] = $range;

        $counts = AccessRequest::whereBetween('created_at', [$from, $to])
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as requested,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as rejected,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as fulfilled,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as expired
            ', [
                AccessRequest::STATUS_REQUESTED,
                AccessRequest::STATUS_REJECTED,
                AccessRequest::STATUS_FULFILLED,
                AccessRequest::STATUS_EXPIRED,
            ])
            ->first();

        $fulfilled = (int) $counts->fulfilled;
        $rejected  = (int) $counts->rejected;
        $decided   = $fulfilled + $rejected;

        // Time-to-review: reviewed_at - created_at, for requests actually
        // reviewed (approved or rejected) within the range. Driver-portable
        // minute-diff expression, same reasoning as AnalyticsService's
        // localExpression()/hourExpression() helpers — one place computes
        // "how do I diff two timestamps on this driver" rather than each
        // caller re-deriving it.
        $avgReviewMinutes = DB::table('access_requests')
            ->whereBetween('created_at', [$from, $to])
            ->whereNotNull('reviewed_at')
            ->selectRaw('AVG(' . self::minutesDiffExpression('created_at', 'reviewed_at') . ') as avg_minutes')
            ->value('avg_minutes');

        return [
            'total'                    => (int) $counts->total,
            'requested'                => (int) $counts->requested,
            'fulfilled'                => $fulfilled,
            'rejected'                 => $rejected,
            'expired'                  => (int) $counts->expired,
            'approval_rate'            => $decided > 0 ? round(($fulfilled / $decided) * 100, 1) : null,
            'avg_time_to_review_hours' => $avgReviewMinutes !== null ? round($avgReviewMinutes / 60, 1) : null,
        ];
    }

    // -------------------------------------------------------------------------
    // Cashier verification health
    // -------------------------------------------------------------------------

    /**
     * Match/no-match rate for OR-verification attempts against the Cashier
     * API (ACTION_CASHIER_VERIFICATION audit entries), plus the current
     * unresolved backlog in unmatched_cashier_items — the operational
     * signal that used to require manually cross-referencing OGOS and
     * Cashier by hand (see Phase 4 of the plan for the deeper diagnostics
     * follow-up; this panel is just the aggregate health view).
     */
    public function cashierVerificationHealth(array $range): array
    {
        [$from, $to] = $range;

        $attemptsQuery = AuditLog::where('action', AuditLog::ACTION_CASHIER_VERIFICATION)
            ->whereBetween('created_at', [$from, $to]);

        $total   = (clone $attemptsQuery)->count();
        // metadata is a genuine JSON column (see 2026_07_12_000000_add_
        // target_and_metadata_to_audit_logs) — Laravel's JSON path `where`
        // is portable across the MySQL/SQLite/Postgres drivers this app
        // targets, so no driver-specific branching is needed here.
        $matched = (clone $attemptsQuery)->where('metadata->final_approved', true)->count();

        $backlogTotal = UnmatchedCashierItem::whereNull('resolved_at')->count();

        $topBacklogItems = UnmatchedCashierItem::whereNull('resolved_at')
            ->orderByDesc('occurrence_count')
            ->limit(5)
            ->get(['unmatched_cashier_item_id', 'raw_label', 'occurrence_count', 'first_seen_at', 'last_seen_at'])
            ->map(fn ($item) => [
                'id'               => $item->unmatched_cashier_item_id,
                'raw_label'        => $item->raw_label,
                'occurrence_count' => $item->occurrence_count,
                'first_seen_at'    => $item->first_seen_at,
                'last_seen_at'     => $item->last_seen_at,
            ])
            ->all();

        return [
            'total_attempts'     => $total,
            'matched'            => $matched,
            'unmatched'          => $total - $matched,
            'match_rate'         => $total > 0 ? round(($matched / $total) * 100, 1) : null,
            'unresolved_backlog' => $backlogTotal,
            'top_backlog_items'  => $topBacklogItems,
        ];
    }

    // -------------------------------------------------------------------------
    // Scheduled jobs health
    // -------------------------------------------------------------------------

    /**
     * "Is the system itself healthy" panel, Job-Health Monitoring addendum
     * to Phase 2 — closes the gap surfaced during the Sep 2026 production
     * verification of ShredExpiredRequests/SendUnclaimedReminders: the
     * only prior signal that a scheduled command ran at all was a line in
     * storage/logs/scheduler.log inside the scheduler container, which
     * nobody was alerted to. See JobRunLog and the LogsJobRun trait for
     * how each command now records its own run.
     *
     * Not date-ranged — same reasoning as adminRosterHealth(): this is a
     * "right now, per job" snapshot (latest run + its status), not a
     * trend over a window.
     *
     * Iterates JobRunLog::JOBS (the canonical list of every command
     * registered in routes/console.php) rather than just whatever
     * job_names happen to already be in the table — a job that has NEVER
     * produced a single row still shows up as "no runs recorded" instead
     * of silently being absent, which is itself important signal (e.g.
     * the scheduler container never having been deployed with a newly
     * added command registered).
     *
     * Each job's `status` is one of:
     *   'running'   — a run is currently in progress (started, not yet
     *                 past the stalled ceiling below).
     *   'success' | 'failed' — the latest run's own outcome.
     *   'stalled'   — the latest run has sat in 'running' past
     *                 $stalledAfterMinutes: the process almost certainly
     *                 died mid-run without ever closing out its row.
     *   'overdue'   — the latest run finished (successfully) long enough
     *                 ago that the job should have started again by now
     *                 and hasn't — see JobRunLog::EXPECTED_INTERVAL_MINUTES.
     *                 This is the case a 'stalled'-only check can't see:
     *                 last run looked completely fine, the schedule just
     *                 never fired again (scheduler container down across
     *                 an entire tick, e.g. during a deploy).
     *   'never_run' — no row for this job_name has ever been written.
     */
    public function scheduledJobsHealth(): array
    {
        // "For each job_name, the row with the max started_at" — a plain
        // GROUP BY + MAX + IN, portable across the drivers this app
        // targets (MySQL/MariaDB, SQLite for tests, PostgreSQL as a
        // future migration path) rather than a driver-specific window
        // function, same portability-first approach as
        // minutesDiffExpression() below. Two round trips total,
        // regardless of how many jobs or how many historical rows exist.
        $latestIds = DB::table('job_run_logs')
            ->selectRaw('MAX(job_run_id) as id')
            ->groupBy('job_name')
            ->pluck('id');

        $latestRuns = JobRunLog::query()
            ->whereIn('job_run_id', $latestIds)
            ->get()
            ->keyBy('job_name');

        $now = now();

        // A run still sitting in 'running' well past a generous ceiling
        // almost certainly means the process died mid-run without ever
        // reaching finishJobRun()/failJobRun() (container killed, OOM,
        // deploy restart mid-execution) rather than a genuinely long
        // job — every one of these commands normally completes in well
        // under a minute against this schema's data volumes.
        $stalledAfterMinutes = 30;

        $jobs = collect(JobRunLog::JOBS)
            ->map(function (string $scheduleLabel, string $jobName) use ($latestRuns, $now, $stalledAfterMinutes) {
                /** @var JobRunLog|null $run */
                $run = $latestRuns->get($jobName);

                $stalled = $run
                    && $run->status === JobRunLog::STATUS_RUNNING
                    && $run->started_at->diffInMinutes($now) >= $stalledAfterMinutes;

                // 'overdue' — see JobRunLog::EXPECTED_INTERVAL_MINUTES's
                // docblock for the full reasoning. Deliberately scoped to
                // STATUS_SUCCESS only: a 'failed' latest row is already
                // surfaced via STATUS_FAILED below and still proves the
                // container was alive and the schedule fired, and a
                // 'running'/'stalled' row is already covered by its own
                // branch above — overdue exists specifically to catch
                // "the last run looked completely fine, but the schedule
                // itself never fired again," which only a genuinely
                // successful latest row can mask.
                $maxGapMinutes = JobRunLog::EXPECTED_INTERVAL_MINUTES[$jobName] ?? null;
                $overdue = !$stalled
                    && $run
                    && $run->status === JobRunLog::STATUS_SUCCESS
                    && $maxGapMinutes !== null
                    && $run->started_at->diffInMinutes($now) > $maxGapMinutes;

                $status = match (true) {
                    $stalled => 'stalled',
                    $overdue => 'overdue',
                    default  => $run->status ?? 'never_run',
                };

                return [
                    'job_name'         => $jobName,
                    'schedule'         => $scheduleLabel,
                    'status'           => $status,
                    'last_started_at'  => $run?->started_at,
                    'last_finished_at' => $run?->finished_at,
                    'duration_ms'      => $run?->duration_ms,
                    'rows_affected'    => $run?->rows_affected,
                    'error_message'    => $run?->error_message,
                ];
            })
            ->values();

        $needsAttention = $jobs->filter(
            fn (array $job) => in_array($job['status'], [JobRunLog::STATUS_FAILED, 'stalled', 'never_run', 'overdue'], true)
        )->count();

        return [
            'jobs'            => $jobs->all(),
            'needs_attention' => $needsAttention,
            'checked_at'      => $now,
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Human-friendly label for a `roles.role_name` value ('super_admin',
     * 'admin', etc.). Sourced from the roles table (single source of
     * truth for what roles exist) rather than a hardcoded role_id => label
     * map duplicated from SystemUser's constants.
     */
    private static function roleLabel(string $roleName): string
    {
        return str($roleName)->replace('_', ' ')->title()->toString();
    }

    /**
     * Return a SQL expression for the number of minutes between two
     * datetime columns ($end - $start), portable across the drivers this
     * app targets (MySQL/MariaDB, SQLite for tests, PostgreSQL as a future
     * migration path) — same rationale as AnalyticsService's
     * localExpression()/hourExpression()/monthExpression() helpers:
     * centralise the one place that knows how to do driver-specific date
     * math, rather than letting it leak into individual query call sites.
     */
    private static function minutesDiffExpression(string $startColumn, string $endColumn): string
    {
        $driver = DB::connection()->getDriverName();

        return match ($driver) {
            'sqlite' => "(julianday({$endColumn}) - julianday({$startColumn})) * 24 * 60",
            'pgsql'  => "EXTRACT(EPOCH FROM ({$endColumn} - {$startColumn})) / 60",
            default  => "TIMESTAMPDIFF(MINUTE, {$startColumn}, {$endColumn})", // MySQL / MariaDB
        };
    }
}