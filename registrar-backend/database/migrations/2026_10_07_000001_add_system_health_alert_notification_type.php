<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Notification type for System Health alerts (Super Admin audience).
 *
 * Id 33: the highest existing notification_type_id is 32. Must be a
 * migration, not only a seeder change, because NotificationService::send()
 * silently skips an unknown trigger. updateOrInsert on trigger_event, safe
 * to re-run. The 'super_admin' audience value was added to the column by
 * 2026_08_01_000000.
 *
 * The template uses only :severity and :summary; the summary is built
 * server-side from counts and metric names, never from personal data.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('notification_types')->updateOrInsert(
            ['trigger_event' => 'system_health_alert'],
            [
                'notification_type_id' => 33,
                'title'                => 'System Health Alert',
                'message_template'     => 'System health alert (:severity): :summary',
                'audience'             => 'super_admin',
                'is_active'            => 1,
            ]
        );
    }

    public function down(): void
    {
        DB::table('notification_types')->where('trigger_event', 'system_health_alert')->delete();
    }
};
