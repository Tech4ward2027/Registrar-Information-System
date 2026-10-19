<?php

namespace App\Services\Health;

use App\Models\AuditLog;
use App\Models\SecurityEvent;
use App\Models\SystemAlert;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Raises one alert for a user who has failed N or more times inside the
 * rolling window, across cashier verification and OGOS/PUPTAPS provisioning.
 *
 * PRIVACY: the alert context holds the user ID and counts only. Emails are
 * used transiently to map provisioning events to an existing account and are
 * never stored. A refused brand-new person has no account, so provisioning
 * failures cannot be attributed to them; that is a known, documented limit.
 *
 * DEDUPE: skipped while any alert for the same user was created inside the
 * window, and the per-day alert_key stays unique on top of that
 * (SystemAlertService::raise is the single atomic gate).
 */
class RepeatFailureWatch
{
    public function __construct(private readonly SystemAlertService $alerts) {}

    /** @return int alerts raised */
    public function evaluate(CarbonImmutable $today, bool $notify = true): int
    {
        $threshold = max(2, (int) config('system_health.repeat_failure_threshold', 3));
        $window    = max(1, (int) config('system_health.repeat_failure_window_days', 7));
        $from      = $today->startOfDay()->subDays($window - 1);
        $to        = $today->startOfDay()->addDay();

        $cashier      = $this->cashierFailuresByUser($from, $to);
        $provisioning = $this->provisioningFailuresByUser($from, $to);

        $raised = 0;

        foreach (array_unique(array_merge(array_keys($cashier), array_keys($provisioning))) as $userId) {
            $c     = $cashier[$userId] ?? 0;
            $p     = $provisioning[$userId] ?? 0;
            $total = $c + $p;

            if ($total < $threshold) {
                continue;
            }

            $dimension = 'user:' . $userId;

            $recent = SystemAlert::where('type', SystemAlert::TYPE_REPEAT_FAILURE)
                ->where('dimension', $dimension)
                ->where('created_at', '>=', $from)
                ->exists();

            if ($recent) {
                continue;
            }

            $created = $this->alerts->raise([
                'alert_key'    => implode(':', ['repeat_failure', $userId, $today->toDateString()]),
                'type'         => SystemAlert::TYPE_REPEAT_FAILURE,
                'severity'     => $total >= $threshold * 2
                    ? SystemAlert::SEVERITY_CRITICAL
                    : SystemAlert::SEVERITY_WARNING,
                'source'       => 'users',
                'metric'       => 'repeat_failures',
                'dimension'    => $dimension,
                'window_start' => $from->toDateString(),
                'window_end'   => $today->toDateString(),
                'observed'     => $total,
                'context'      => [
                    'user_id'               => (int) $userId,
                    'cashier_failures'      => $c,
                    'provisioning_failures' => $p,
                    'window_days'           => $window,
                ],
            ], $notify);

            if ($created !== null) {
                $raised++;
            }
        }

        return $raised;
    }

    /** @return array<int,int> user_id => failed cashier verifications */
    private function cashierFailuresByUser(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $out = [];

        DB::table('audit_logs')
            ->where('action', AuditLog::ACTION_CASHIER_VERIFICATION)
            ->whereNotNull('user_id')
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to)
            ->select(['id', 'user_id', 'metadata'])
            ->chunkById(1000, function ($chunk) use (&$out) {
                foreach ($chunk as $row) {
                    $m = is_array($row->metadata) ? $row->metadata : json_decode((string) $row->metadata, true);

                    // Overrides and mock rows are not failures; only explicit
                    // final_approved = false counts.
                    if (is_array($m)
                        && ($m['method'] ?? null) !== 'admin_override'
                        && empty($m['is_mock'])
                        && array_key_exists('final_approved', $m)
                        && !$m['final_approved']) {
                        $out[(int) $row->user_id] = ($out[(int) $row->user_id] ?? 0) + 1;
                    }
                }
            }, 'id');

        return $out;
    }

    /** @return array<int,int> user_id => provisioning failures (existing accounts only) */
    private function provisioningFailuresByUser(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $byEmail = DB::table('security_events')
            ->where('event_type', SecurityEvent::EVENT_TYPE_PROVISIONING_FAILED)
            ->whereNotNull('email')
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to)
            ->selectRaw('email, COUNT(*) as n')
            ->groupBy('email')
            ->pluck('n', 'email');

        if ($byEmail->isEmpty()) {
            return [];
        }

        $out = [];

        foreach ($byEmail->keys()->chunk(500) as $emails) {
            // (verify) users.email is the lookup column and user_id the key.
            $ids = DB::table('users')->whereIn('email', $emails->all())->pluck('user_id', 'email');

            foreach ($ids as $email => $userId) {
                $out[(int) $userId] = ($out[(int) $userId] ?? 0) + (int) $byEmail[$email];
            }
        }

        return $out;
    }
}
