<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
 * Policy v1.2.0: break-glass and 30-day deficiency-escalation alerts go to
 * Super Admin only. Recipients are decided in code from
 * config('notification_matrix.alert_audience_role_ids'); this keeps the
 * descriptive `audience` column on the types in step with it.
 */
return new class extends Migration
{
    private const TRIGGERS = ['local_auth_login_used', 'deficiency_notice_escalated'];

    public function up(): void
    {
        DB::table('notification_types')
            ->whereIn('trigger_event', self::TRIGGERS)
            ->update(['audience' => 'super_admin']);

        foreach (self::TRIGGERS as $trigger) {
            Cache::forget("notif_type:{$trigger}");
        }
    }

    public function down(): void
    {
        // Break-glass was already 'super_admin' when it was created; only the
        // escalation type changes audience, so only it is restored.
        DB::table('notification_types')->where('trigger_event', 'deficiency_notice_escalated')->update(['audience' => 'admin']);

        foreach (self::TRIGGERS as $trigger) {
            Cache::forget("notif_type:{$trigger}");
        }
    }
};
