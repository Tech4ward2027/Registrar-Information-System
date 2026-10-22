<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Web Push — also notify requestors about document-request progress.
 *
 * The first push migration only flagged urgent/action-needed types. Requestors
 * also want to hear when their request is submitted, payment is verified,
 * processing starts and the request is completed.
 *
 * To stop a type pushing later, set notification_types.send_push = 0 for it
 * (and run `php artisan cache:clear`, or save it through the model so the
 * observer clears the cache).
 *
 * NotificationService caches each type for 6 hours under
 * "notif_type:{trigger_event}". The updates below bypass Eloquent (so no
 * observer fires), so the cache entries are cleared here explicitly —
 * otherwise a type cached before send_push existed would keep skipping push
 * until the TTL expires. The keys of the previously flagged types are cleared
 * too, which repairs any stale entries left by the first push migration.
 */
return new class extends Migration
{
    private const PROGRESS = [
        'request_submitted',
        'payment_verified',
        'request_processing',
        'request_completed',
    ];

    private const PREVIOUSLY_FLAGGED = [
        'payment_invalid',
        'action_needed',
        'ready_to_claim',
        'pending_signature',
        'deficiency_notice_issued',
        'deficiency_notice_voided',
        'request_closed',
        'request_closed_unable_to_process',
        'request_item_closed_unable_to_process',
        'reminder_claim',
        'undergrad_requestor_approved',
        'undergrad_requestor_rejected',
        'deficiency_notice_escalated',
        'admin_deadline_warning',
        'security_alert_failed_login_burst',
        'local_auth_login_used',
    ];

    public function up(): void
    {
        DB::table('notification_types')
            ->whereIn('trigger_event', self::PROGRESS)
            ->update(['send_push' => true]);

        $this->forgetCachedTypes(array_merge(self::PROGRESS, self::PREVIOUSLY_FLAGGED));
    }

    public function down(): void
    {
        DB::table('notification_types')
            ->whereIn('trigger_event', self::PROGRESS)
            ->update(['send_push' => false]);

        $this->forgetCachedTypes(self::PROGRESS);
    }

    /** @param  list<string>  $events */
    private function forgetCachedTypes(array $events): void
    {
        foreach ($events as $event) {
            Cache::forget("notif_type:{$event}");
        }
    }
};
