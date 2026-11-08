<?php

namespace App\Http\Controllers;

use App\Models\JobRunLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * GET /api/health: liveness/readiness endpoint used by the DR runbook
 * (Phase 5 health verification), uptime monitors and the load balancer / DNS
 * cut-over checks.
 *
 * Two audiences, one route:
 *
 *   PUBLIC (no credentials)
 *     {"status":"ok"|"unavailable","time":"...Z"}
 *     HTTP 200 when the two hard dependencies work, HTTP 503 when either does
 *     not. Deliberately says nothing about WHICH dependency failed, what
 *     versions are running or what the queue looks like: a public endpoint
 *     must not hand a scanner a map of the stack (VAPT F-02).
 *
 *   DETAILED (header "X-Health-Token: <HEALTH_CHECK_TOKEN>")
 *     Adds per-check results: database, cache (Redis in production), queue
 *     backlog, scheduler heartbeat, last audit-chain verification, disk space.
 *     For the Dev/IT team and monitors only. Disabled unless a token of at
 *     least 32 characters is configured.
 *
 * Design rules
 *   - Never throws. A health endpoint that 500s on the failure it is meant to
 *     report is worse than useless; every probe is wrapped.
 *   - Only the hard dependencies (database, cache) can turn the HTTP status
 *     into 503. Everything else is advisory ("warn"/"fail" in the detailed
 *     view) so a late cron job can never take the site out of rotation.
 *   - The public probe result is cached for config('health.cache_ttl')
 *     seconds in the FILE cache store (not Redis, which is one of the things
 *     being checked), so a flood of /api/health calls costs at most one real
 *     probe per interval per container instead of one per request. The
 *     PHP-FPM pool is small (pm.max_children = 5), so this matters.
 *   - Exception messages are logged server-side only, never returned.
 */
class HealthController extends Controller
{
    private const TOKEN_HEADER = 'X-Health-Token';
    private const MIN_TOKEN_LENGTH = 32;

    public function __invoke(Request $request): JsonResponse
    {
        $critical = $this->criticalChecks();
        $available = $critical['database'] && $critical['cache'];

        $body = [
            'status' => $available ? 'ok' : 'unavailable',
            'time'   => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        ];

        if ($this->hasValidToken($request)) {
            $advisory = $this->advisoryChecks();

            $body['checks'] = array_merge(
                [
                    'database' => ['status' => $critical['database'] ? 'ok' : 'fail'],
                    'cache'    => ['status' => $critical['cache'] ? 'ok' : 'fail'],
                ],
                $advisory,
            );

            if ($available) {
                foreach ($advisory as $check) {
                    if (in_array($check['status'], ['warn', 'fail'], true)) {
                        $body['status'] = 'degraded';
                        break;
                    }
                }
            }
        }

        return response()
            ->json($body, $available ? 200 : 503)
            ->header('Cache-Control', 'no-store');
    }

    // ---------------------------------------------------------------- auth

    private function hasValidToken(Request $request): bool
    {
        $configured = (string) config('health.token', '');
        $given = (string) $request->header(self::TOKEN_HEADER, '');

        if (strlen($configured) < self::MIN_TOKEN_LENGTH || $given === '') {
            return false;
        }

        // Hash both sides so hash_equals() always compares equal-length strings.
        return hash_equals(hash('sha256', $configured), hash('sha256', $given));
    }

    // ------------------------------------------------------------ critical

    /** @return array{database: bool, cache: bool} */
    private function criticalChecks(): array
    {
        $ttl = (int) config('health.cache_ttl', 5);

        if ($ttl <= 0) {
            return $this->probeCritical();
        }

        try {
            return Cache::store('file')->remember('health:critical', $ttl, fn () => $this->probeCritical());
        } catch (Throwable $e) {
            // The file store itself failed (disk full, permissions): probe live.
            Log::warning('[health] result cache unavailable', ['error' => $e::class]);

            return $this->probeCritical();
        }
    }

    /** @return array{database: bool, cache: bool} */
    private function probeCritical(): array
    {
        return [
            'database' => $this->guard('database', function () {
                DB::select('select 1');

                return true;
            }),
            'cache' => $this->guard('cache', function () {
                $key = 'health:probe:' . Str::random(8);
                $value = Str::random(16);
                Cache::put($key, $value, 10);
                $ok = Cache::get($key) === $value;
                Cache::forget($key);

                return $ok;
            }),
        ];
    }

    private function guard(string $name, callable $probe): bool
    {
        try {
            return (bool) $probe();
        } catch (Throwable $e) {
            Log::warning("[health] {$name} check failed", [
                'exception' => $e::class,
                'message'   => $e->getMessage(),
            ]);

            return false;
        }
    }

    // ------------------------------------------------------------ advisory

    /** @return array<string, array<string, mixed>> */
    private function advisoryChecks(): array
    {
        return [
            'queue'       => $this->queueCheck(),
            'scheduler'   => $this->schedulerCheck(),
            'audit_chain' => $this->auditChainCheck(),
            'disk'        => $this->diskCheck(),
        ];
    }

    /** Jobs that have been waiting, unreserved, longer than the threshold. */
    private function queueCheck(): array
    {
        try {
            $stuckMinutes = (int) config('health.queue_stuck_minutes', 10);
            $stuck = DB::table('jobs')
                ->whereNull('reserved_at')
                ->where('available_at', '<=', now()->subMinutes($stuckMinutes)->timestamp)
                ->count();
            $failed24h = DB::table('failed_jobs')
                ->where('failed_at', '>=', now()->subDay())
                ->count();

            return [
                'status'     => ($stuck > 0 || $failed24h > 0) ? 'warn' : 'ok',
                'stuck_jobs' => $stuck,
                'failed_24h' => $failed24h,
            ];
        } catch (Throwable $e) {
            return $this->unknown('queue', $e);
        }
    }

    /**
     * The scheduler is alive if SOME scheduled command finished successfully
     * recently. Hourly jobs exist (role-assignments:expire, health:rollup), so
     * the default window is 150 minutes.
     */
    private function schedulerCheck(): array
    {
        try {
            // value() hydrates the model, so the datetime cast (app timezone) applies.
            $lastAt = JobRunLog::where('status', JobRunLog::STATUS_SUCCESS)
                ->orderByDesc('finished_at')
                ->value('finished_at');
            $maxAge = (int) config('health.scheduler_max_age_minutes', 150);

            if ($lastAt === null) {
                return ['status' => 'warn', 'last_success_at' => null];
            }

            return [
                'status'          => $lastAt->lt(now()->subMinutes($maxAge)) ? 'warn' : 'ok',
                'last_success_at' => $lastAt->utc()->format('Y-m-d\TH:i:s\Z'),
            ];
        } catch (Throwable $e) {
            return $this->unknown('scheduler', $e);
        }
    }

    /** Result of the most recent scheduled `audit:verify` (daily 03:00). */
    private function auditChainCheck(): array
    {
        try {
            $run = JobRunLog::where('job_name', 'audit:verify')
                ->whereNotNull('finished_at')
                ->orderByDesc('started_at')
                ->first();

            if (!$run) {
                return ['status' => 'warn', 'last_run_at' => null];
            }

            $maxAge = (int) config('health.audit_verify_max_age_hours', 36);
            $stale = $run->finished_at->lt(now()->subHours($maxAge));

            return [
                'status'      => $run->status !== JobRunLog::STATUS_SUCCESS ? 'fail' : ($stale ? 'warn' : 'ok'),
                'last_result' => $run->status,
                'last_run_at' => $run->finished_at->utc()->format('Y-m-d\TH:i:s\Z'),
            ];
        } catch (Throwable $e) {
            return $this->unknown('audit_chain', $e);
        }
    }

    private function diskCheck(): array
    {
        $path = storage_path();
        $free = @disk_free_space($path);
        $total = @disk_total_space($path);

        if ($free === false || $total === false || $total <= 0) {
            return ['status' => 'unknown'];
        }

        $freePct = round(($free / $total) * 100, 1);

        return [
            'status'   => $freePct < (float) config('health.disk_min_free_percent', 10) ? 'warn' : 'ok',
            'free_pct' => $freePct,
        ];
    }

    private function unknown(string $name, Throwable $e): array
    {
        Log::warning("[health] {$name} check could not run", ['exception' => $e::class]);

        return ['status' => 'unknown'];
    }
}
