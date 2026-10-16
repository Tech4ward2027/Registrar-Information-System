<?php

namespace App\Services\Health;

use App\Contracts\NotificationServiceInterface;
use App\Models\SystemAlert;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Creates system_alerts rows and notifies Super Admins — exactly once per
 * incident.
 *
 * ATOMIC DE-DUPLICATION: insertOrIgnore() against the UNIQUE alert_key index
 * is one atomic statement, so two concurrent detector runs can never both
 * create (and both notify for) the same incident. This is stronger than the
 * Cache::add() lock in SecurityEventLogger::maybeAlertOnBurst(), which needs
 * a healthy cache; here the database is the single source of truth, and only
 * the caller that actually inserted the row sends the notification.
 *
 * Notification is best-effort: a failure to notify must never lose the alert
 * or break the detection run.
 */
class SystemAlertService
{
    public function __construct(private readonly NotificationServiceInterface $notifications) {}

    /**
     * @param array{alert_key:string,type:string,severity:string,source:string,metric:string,
     *              dimension?:string,window_start:string,window_end:string,observed:float,
     *              baseline_median?:?float,baseline_mad?:?float,score?:?float,context?:array} $a
     * @return SystemAlert|null the alert if newly created, null if it already existed
     */
    public function raise(array $a, bool $notify = true): ?SystemAlert
    {
        $inserted = DB::table('system_alerts')->insertOrIgnore([
            'alert_key'       => $a['alert_key'],
            'type'            => $a['type'],
            'severity'        => $a['severity'],
            'source'          => $a['source'],
            'metric'          => $a['metric'],
            'dimension'       => $a['dimension'] ?? '',
            'window_start'    => $a['window_start'],
            'window_end'      => $a['window_end'],
            'observed'        => $a['observed'],
            'baseline_median' => $a['baseline_median'] ?? null,
            'baseline_mad'    => $a['baseline_mad'] ?? null,
            'score'           => $a['score'] ?? null,
            'status'          => SystemAlert::STATUS_OPEN,
            'context'         => json_encode($a['context'] ?? []),
            'created_at'      => now(),
        ]);

        if ($inserted !== 1) {
            return null;
        }

        $alert = SystemAlert::where('alert_key', $a['alert_key'])->first();

        if ($alert && $notify) {
            $this->notify($alert);
        }

        return $alert;
    }

    private function notify(SystemAlert $alert): void
    {
        try {
            $this->notifications->sendToSuperAdmins('system_health_alert', [
                'severity' => $alert->severity,
                'summary'  => $this->summary($alert),
                'alert_id' => $alert->system_alert_id,
            ]);
        } catch (Throwable $e) {
            Log::warning('[SystemAlertService] notification failed; alert retained', [
                'alert_id' => $alert->system_alert_id,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    /** Human summary built from metric names and counts only. */
    private function summary(SystemAlert $alert): string
    {
        $label = $alert->source . ' ' . $alert->metric . ($alert->dimension !== '' ? " ({$alert->dimension})" : '');
        $isRate = $alert->type === SystemAlert::TYPE_RATE_SPIKE;

        $fmt = fn (?float $v) => $v === null ? 'n/a' : ($isRate ? round($v * 100) . '%' : (string) round($v, 1));

        return sprintf(
            '%s was %s on %s against a typical %s. Open System health to review.',
            $label,
            $fmt($alert->observed),
            $alert->window_end->toDateString(),
            $fmt($alert->baseline_median),
        );
    }
}
