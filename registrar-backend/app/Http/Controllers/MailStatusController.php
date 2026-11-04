<?php

namespace App\Http\Controllers;

use App\Models\NotificationDispatchLog;
use App\Services\Mail\MailPolicy;
use App\Services\Mail\MailQuotaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * System Mailer status for Super Admins (Notification Policy: Super Admin
 * monitors mailer quota and dispatch). Mounted under the role:4 group.
 *
 * Read-only and bounded: the quota snapshot, a 7-day roll-up by trigger and
 * status, and the 20 most recent failures. It exposes no addresses, message
 * text or links. The dispatch log stores none, and user_id is the only
 * person-identifying value.
 */
class MailStatusController extends Controller
{
    private const WINDOW_DAYS     = 7;
    private const RECENT_FAILURES = 20;

    public function __construct(private readonly MailQuotaService $quota) {}

    public function show(): JsonResponse
    {
        $since = now()->subDays(self::WINDOW_DAYS)->startOfDay();

        $rollup = NotificationDispatchLog::query()
            ->where('channel', NotificationDispatchLog::CHANNEL_MAIL)
            ->where('created_at', '>=', $since)
            ->select('trigger_event', 'status', DB::raw('COUNT(*) as total'))
            ->groupBy('trigger_event', 'status')
            ->orderBy('trigger_event')
            ->get()
            ->map(fn ($r) => [
                'trigger_event' => $r->trigger_event,
                'status'        => $r->status,
                'total'         => (int) $r->total,
            ]);

        $failures = NotificationDispatchLog::query()
            ->where('channel', NotificationDispatchLog::CHANNEL_MAIL)
            ->where('status', NotificationDispatchLog::STATUS_FAILED)
            ->orderByDesc('id')
            ->limit(self::RECENT_FAILURES)
            ->get(['trigger_event', 'reason', 'error', 'user_id', 'request_id', 'created_at']);

        return response()->json([
            'enabled'         => (bool) config('notification_mail.enabled'),
            'quota'           => $this->quota->snapshot(),
            'rate_per_minute' => (int) config('notification_mail.rate_per_minute'),
            'approved'        => [
                'lifecycle'  => MailPolicy::LIFECYCLE_TRIGGERS,
                'exceptions' => MailPolicy::APPROVED_EXCEPTIONS,
            ],
            'window_days'     => self::WINDOW_DAYS,
            'rollup'          => $rollup,
            'recent_failures' => $failures,
        ]);
    }
}
