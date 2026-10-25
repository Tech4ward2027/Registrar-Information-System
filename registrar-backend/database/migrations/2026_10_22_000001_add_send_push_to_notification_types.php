<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Web Push — per-type opt-in flag.
 *
 * Only genuinely time-sensitive notification types should reach a user's
 * lock screen; pushing everything trains users to block notifications.
 * send_push defaults to false, then the urgent set below is switched on.
 *
 * Types are keyed on trigger_event (never a hard-coded id), and a trigger
 * that doesn't exist in a given database is simply ignored. Admins can tune
 * this later by flipping the column — NotificationTypeObserver clears the
 * cached type automatically on save.
 */
return new class extends Migration
{
    private const URGENT = [
        // Student / alumni / undergrad requestor
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
        // Staff / super admin
        'deficiency_notice_escalated',
        'admin_deadline_warning',
        'security_alert_failed_login_burst',
        'local_auth_login_used',
    ];

    public function up(): void
    {
        Schema::table('notification_types', function (Blueprint $table) {
            $table->boolean('send_push')->default(false)->after('is_active');
        });

        DB::table('notification_types')
            ->whereIn('trigger_event', self::URGENT)
            ->update(['send_push' => true]);
    }

    public function down(): void
    {
        Schema::table('notification_types', function (Blueprint $table) {
            $table->dropColumn('send_push');
        });
    }
};
