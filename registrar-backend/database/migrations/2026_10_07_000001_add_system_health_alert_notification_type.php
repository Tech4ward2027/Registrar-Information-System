<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * System Health Phase 3 — notification type for Super Admin health alerts.
 *
 * The row is identified by its trigger_event, never by a hardcoded id.
 * An earlier revision of this migration inserted id 33, which collides
 * with ids already taken in seeded databases (33/34 are the undergrad
 * requestor types). The id is now allocated at run time, and never below
 * FIRST_ID so ids that other seeders may still claim stay clear.
 *
 * Idempotent: re-running updates the existing row and never duplicates it.
 */
return new class extends Migration
{
    private const TRIGGER_EVENT = 'system_health_alert';

    // Ids below this are left to the seeded/legacy types.
    private const FIRST_ID = 35;

    public function up(): void
    {
        $fields = [
            'title'            => 'System Health Alert',
            'message_template' => 'System health alert (:severity): :summary',
            'audience'         => 'super_admin',
            'is_active'        => 1,
        ];

        $exists = DB::table('notification_types')
            ->where('trigger_event', self::TRIGGER_EVENT)
            ->exists();

        if ($exists) {
            DB::table('notification_types')
                ->where('trigger_event', self::TRIGGER_EVENT)
                ->update($fields);

            return;
        }

        $nextId = max(
            self::FIRST_ID,
            ((int) DB::table('notification_types')->max('notification_type_id')) + 1
        );

        DB::table('notification_types')->insert($fields + [
            'notification_type_id' => $nextId,
            'trigger_event'        => self::TRIGGER_EVENT,
        ]);
    }

    public function down(): void
    {
        DB::table('notification_types')
            ->where('trigger_event', self::TRIGGER_EVENT)
            ->delete();
    }
};
