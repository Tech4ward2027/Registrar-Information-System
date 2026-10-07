<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\SecurityEvent;
use App\Models\SystemAlert;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * System Health API (Super Admin, role:4). Mounted inside the existing
 * `system-analytics` route group, behind the `feature:system_health` flag.
 *
 * Reads only pre-aggregated rollups (health_daily_metrics), system_alerts,
 * and two bounded counts. It never returns names, emails or OR numbers; the
 * only person-identifying value is a user_id inside a repeat-failure alert.
 * Every query is bounded by a clamped date range.
 */
class SystemHealthController extends Controller
{
    private const MAX_DAYS = 90;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function cashierTrend(Request $request): JsonResponse
    {
        [$from, $to, $days] = $this->range($request);

        $rows = DB::table('health_daily_metrics')
            ->where('source', 'cashier')
            ->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()])
            ->get(['metric_date', 'metric', 'dimension', 'count']);

        $daily    = $this->emptyDays($from, $to, ['attempts' => 0, 'failures' => 0, 'overrides' => 0]);
        $reasons  = [];
        $codes    = [];

        foreach ($rows as $r) {
            $d = substr((string) $r->metric_date, 0, 10);
            if (!isset($daily[$d])) {
                continue;
            }
            if ($r->dimension === '' && isset($daily[$d][$r->metric])) {
                $daily[$d][$r->metric] = (int) $r->count;
            } elseif ($r->metric === 'failures_by_reason') {
                $reasons[$r->dimension] = ($reasons[$r->dimension] ?? 0) + (int) $r->count;
            } elseif ($r->metric === 'diagnosis_code') {
                $codes[$r->dimension] = ($codes[$r->dimension] ?? 0) + (int) $r->count;
            }
        }

        $series = [];
        foreach ($daily as $date => $v) {
            $series[] = [
                'date'         => $date,
                'attempts'     => $v['attempts'],
                'failures'     => $v['failures'],
                'overrides'    => $v['overrides'],
                'failure_rate' => $v['attempts'] > 0 ? round($v['failures'] / $v['attempts'], 4) : null,
            ];
        }

        arsort($reasons);
        arsort($codes);

        return response()->json(['data' => [
            'days'               => $days,
            'series'             => $series,
            'failure_reasons'    => $this->toList($reasons),
            'diagnosis_codes'    => $this->toList($codes),
            'anomaly_dates'      => $this->anomalyDates('cashier', $from, $to),
            // Backlog = labels still waiting for an admin (link target: Cashier Reconciliation).
            'unresolved_labels'  => DB::table('unmatched_cashier_items')->whereNull('resolved_at')->count(),
        ]]);
    }

    public function provisioningHealth(Request $request): JsonResponse
    {
        [$from, $to, $days] = $this->range($request);

        $rows = DB::table('health_daily_metrics')
            ->where('source', 'provisioning')
            ->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()])
            ->get(['metric_date', 'metric', 'dimension', 'count']);

        $daily   = $this->emptyDays($from, $to, ['ogos' => 0, 'puptaps' => 0]);
        $reasons = [];

        foreach ($rows as $r) {
            $d = substr((string) $r->metric_date, 0, 10);
            if (!isset($daily[$d])) {
                continue;
            }
            if ($r->metric === 'failures' && isset($daily[$d][$r->dimension])) {
                $daily[$d][$r->dimension] = (int) $r->count;
            } elseif ($r->metric === 'failures_by_reason') {
                $reasons[$r->dimension] = ($reasons[$r->dimension] ?? 0) + (int) $r->count;
            }
        }

        $series = [];
        foreach ($daily as $date => $v) {
            $series[] = ['date' => $date, 'ogos' => $v['ogos'], 'puptaps' => $v['puptaps']];
        }

        // SSO denials come straight from security_events (not rolled up):
        // one grouped count over the bounded range.
        $sso = DB::table('security_events')
            ->where('event_type', SecurityEvent::EVENT_TYPE_SSO_LOGIN_DENIED)
            ->where('created_at', '>=', $from->startOfDay())
            ->where('created_at', '<', $to->addDay()->startOfDay())
            ->selectRaw('reason, COUNT(*) as n')
            ->groupBy('reason')
            ->pluck('n', 'reason')
            ->all();

        arsort($reasons);
        arsort($sso);

        return response()->json(['data' => [
            'days'            => $days,
            'series'          => $series,
            'failure_reasons' => $this->toList($reasons),
            'sso_denials'     => $this->toList($sso),
            'anomaly_dates'   => $this->anomalyDates('provisioning', $from, $to),
        ]]);
    }

    public function alerts(Request $request): JsonResponse
    {
        $v = $request->validate([
            'status'   => 'nullable|in:open,acknowledged,resolved,all',
            'type'     => 'nullable|in:count_spike,rate_spike,repeat_failure',
            'per_page' => 'nullable|integer|min:1|max:50',
        ]);

        $status = $v['status'] ?? 'open';

        $page = SystemAlert::query()
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when(!empty($v['type']), fn ($q) => $q->where('type', $v['type']))
            ->orderByDesc('created_at')
            ->orderByDesc('system_alert_id')
            ->paginate((int) ($v['per_page'] ?? 20));

        return response()->json([
            'data' => $page->getCollection()->map(fn (SystemAlert $a) => $this->alertPayload($a))->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page'    => $page->lastPage(),
                'per_page'     => $page->perPage(),
                'total'        => $page->total(),
            ],
        ]);
    }

    public function acknowledge(Request $request, int $alert): JsonResponse
    {
        $row = SystemAlert::findOrFail($alert);

        // Conditional update: only an open alert can be acknowledged, and
        // two simultaneous clicks cannot both "win".
        $updated = SystemAlert::where('system_alert_id', $row->system_alert_id)
            ->where('status', SystemAlert::STATUS_OPEN)
            ->update([
                'status'          => SystemAlert::STATUS_ACKNOWLEDGED,
                'acknowledged_by' => $request->user()->user_id,
                'acknowledged_at' => now(),
            ]);

        if ($updated === 0) {
            return response()->json(['message' => 'This alert is no longer open.'], 409);
        }

        $this->auditLogger->log($request, $request->user(), AuditLog::ACTION_SYSTEM_ALERT_ACKNOWLEDGED, [
            'alert_id' => $row->system_alert_id,
            'type'     => $row->type,
            'source'   => $row->source,
            'metric'   => $row->metric,
            'severity' => $row->severity,
        ]);

        return response()->json(['data' => $this->alertPayload($row->fresh())]);
    }

    // ------------------------------------------------------------------

    /** @return array{0:CarbonImmutable,1:CarbonImmutable,2:int} */
    private function range(Request $request): array
    {
        $validated = $request->validate(['days' => 'nullable|integer|min:1']);
        $days      = max(1, min(self::MAX_DAYS, (int) ($validated['days'] ?? 30)));
        $to   = CarbonImmutable::today();

        return [$to->subDays($days - 1), $to, $days];
    }

    /** @return array<string, array<string,int>> date => zeroed metrics */
    private function emptyDays(CarbonImmutable $from, CarbonImmutable $to, array $zero): array
    {
        $out = [];
        for ($d = $from; $d->lte($to); $d = $d->addDay()) {
            $out[$d->toDateString()] = $zero;
        }

        return $out;
    }

    /** @return list<array{key:string,count:int}> */
    private function toList(array $counts): array
    {
        $out = [];
        foreach ($counts as $key => $n) {
            $out[] = ['key' => (string) $key, 'count' => (int) $n];
        }

        return $out;
    }

    /** @return list<string> dates (Y-m-d) with a count/rate alert for the source, for chart markers */
    private function anomalyDates(string $source, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return SystemAlert::where('source', $source)
            ->whereIn('type', [SystemAlert::TYPE_COUNT_SPIKE, SystemAlert::TYPE_RATE_SPIKE])
            ->whereBetween('window_end', [$from->toDateString(), $to->toDateString()])
            ->pluck('window_end')
            ->map(fn ($d) => substr((string) $d, 0, 10))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function alertPayload(SystemAlert $a): array
    {
        return [
            'id'              => $a->system_alert_id,
            'type'            => $a->type,
            'severity'        => $a->severity,
            'source'          => $a->source,
            'metric'          => $a->metric,
            'dimension'       => $a->dimension,
            'window_start'    => $a->window_start?->toDateString(),
            'window_end'      => $a->window_end?->toDateString(),
            'observed'        => $a->observed,
            'baseline_median' => $a->baseline_median,
            'score'           => $a->score,
            'status'          => $a->status,
            'acknowledged_at' => $a->acknowledged_at?->toIso8601String(),
            'context'         => $a->context,
            'created_at'      => $a->created_at?->toIso8601String(),
        ];
    }
}
