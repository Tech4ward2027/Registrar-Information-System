<?php

namespace App\Http\Controllers;

use App\Services\SuperAdminAnalyticsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * System-level analytics for the Super Admin dashboard — Phase 2 of the
 * Analytics & Audit Log Revamp plan. Separate controller from
 * AnalyticsController on purpose: that one answers "how is this
 * Registrar's request queue performing," this one answers "is the system
 * itself (roster, delegated access, cross-system verification) healthy."
 *
 * Route-gated role:4 only (see routes/api.php) — RoleMiddleware treats
 * Super Admin as a superset of every other role, so no additional
 * module: gate is needed the way admin-facing analytics needs
 * module:analytics.
 *
 * Caching mirrors AnalyticsController: Redis, tagged "analytics" so a
 * single Cache::tags(["analytics"])->flush() invalidates every analytics
 * panel (admin- and super-admin-facing) at once.
 */
class SuperAdminAnalyticsController extends Controller
{
    private const CACHE_TTL_MINUTES = 10;

    // Shorter TTL specifically for adminRosterHealth() — it's documented
    // as a "live" snapshot (see that method's docblock), which sits
    // awkwardly next to a 10-minute cache. 2 minutes keeps repeated
    // dashboard loads cheap without the endpoint's own docs contradicting
    // its behavior; the other two panels are trend/throughput numbers
    // where a 10-minute lag is unremarkable.
    private const ROSTER_CACHE_TTL_MINUTES = 2;

    public function __construct(
        private SuperAdminAnalyticsService $superAdminAnalytics,
    ) {}

    // -------------------------------------------------------
    // Shared date-range parser — identical contract to
    // AnalyticsController::dateRange(). Duplicated rather than
    // extracted to a shared trait/base class for now: two call sites
    // sharing ~15 lines doesn't yet justify the indirection, and
    // AnalyticsController is not otherwise a natural parent for this
    // controller. Revisit if a third analytics controller needs the
    // same parsing.
    // -------------------------------------------------------
    // BUG FIX (QA #13 — "'No Prior Data' Despite History"): same
    // UTC-vs-display_timezone boundary bug as AnalyticsController::
    // dateRange() — see that method's docblock for the full
    // explanation. Duplicated there rather than shared (per this
    // method's own existing comment above), so the fix is duplicated
    // too, kept identical on purpose.
    private function dateRange(Request $request): array
    {
        $rangeKey  = $request->query('range', 'month');
        $displayTz = config('app.display_timezone', 'Asia/Manila');
        $storageTz = config('app.timezone', 'UTC');

        if ($rangeKey === 'custom') {
            $defaultFrom = now($displayTz)->startOfMonth()->toDateString();
            $defaultTo   = now($displayTz)->toDateString();

            $from = now($displayTz)->parse($request->query('from', $defaultFrom))->startOfDay()->setTimezone($storageTz);
            $to   = now($displayTz)->parse($request->query('to',   $defaultTo))->endOfDay()->setTimezone($storageTz);
            return [$from, $to];
        }

        $to   = now();
        $from = match ($rangeKey) {
            'today' => now($displayTz)->startOfDay()->setTimezone($storageTz),
            'week'  => now($displayTz)->startOfWeek()->setTimezone($storageTz),
            'year'  => now($displayTz)->startOfYear()->setTimezone($storageTz),
            'all'   => now()->subYears(100),
            default  => now($displayTz)->startOfMonth()->setTimezone($storageTz),
        };
        return [$from, $to];
    }

    private function cacheKey(Request $request, string $endpoint): string
    {
        $range = $request->query('range', 'month');
        $from  = $request->query('from', '');
        $to    = $request->query('to',   '');
        return "system-analytics:{$endpoint}:{$range}:{$from}:{$to}";
    }

    private function cached(Request $request, string $endpoint, callable $fn): mixed
    {
        return Cache::tags(['analytics'])
            ->remember(
                $this->cacheKey($request, $endpoint),
                now()->addMinutes(self::CACHE_TTL_MINUTES),
                $fn,
            );
    }

    /**
     * GET /system-analytics/admin-roster-health
     *
     * Not date-ranged (see SuperAdminAnalyticsService::adminRosterHealth
     * docblock) — cached under a fixed key rather than one that varies by
     * ?range, since the query params are irrelevant to this endpoint.
     */
    // Same reasoning as ROSTER_CACHE_TTL_MINUTES: scheduledJobsHealth()
    // is documented as a "right now" snapshot, so it gets its own short
    // TTL rather than the panels' 10-minute one — a SuperAdmin checking
    // "did the 08:05 job run" a few minutes after 08:05 shouldn't be
    // looking at a cached "no" from before it ran.
    private const JOBS_HEALTH_CACHE_TTL_MINUTES = 1;

    public function adminRosterHealth(Request $request)
    {
        return response()->json(
            Cache::tags(['analytics'])->remember(
                'system-analytics:admin-roster-health',
                now()->addMinutes(self::ROSTER_CACHE_TTL_MINUTES),
                fn () => $this->superAdminAnalytics->adminRosterHealth(),
            )
        );
    }

    public function accessRequestThroughput(Request $request)
    {
        return response()->json(
            $this->cached(
                $request,
                'access-request-throughput',
                fn () => $this->superAdminAnalytics->accessRequestThroughput($this->dateRange($request))
            )
        );
    }

    public function cashierVerificationHealth(Request $request)
    {
        return response()->json(
            $this->cached(
                $request,
                'cashier-verification-health',
                fn () => $this->superAdminAnalytics->cashierVerificationHealth($this->dateRange($request))
            )
        );
    }

    /**
     * GET /system-analytics/scheduled-jobs-health
     *
     * Job-Health Monitoring — not date-ranged, same reasoning as
     * adminRosterHealth() (see that method's docblock): a point-in-time
     * "did each scheduled command last run, and did it succeed" snapshot,
     * cached under a fixed key rather than one that varies by ?range.
     */
    public function scheduledJobsHealth(Request $request)
    {
        return response()->json(
            Cache::tags(['analytics'])->remember(
                'system-analytics:scheduled-jobs-health',
                now()->addMinutes(self::JOBS_HEALTH_CACHE_TTL_MINUTES),
                fn () => $this->superAdminAnalytics->scheduledJobsHealth(),
            )
        );
    }
}