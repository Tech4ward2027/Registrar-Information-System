<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Notification Policy v1.2.0 §3.5: Day 89 final forfeiture notice.
 *
 * reminder_final_warning used to exist only through the seeders, worded as a
 * past-tense "documents shredded" message that nothing ever sent. It is now
 * the forward-looking Day 89 notice (email, push, in-app). This migration
 * creates it when missing and rewrites it when present, so a database that
 * never ran NotificationTypeSeeder still gets it with the email flag on.
 *
 * request_forfeited (the Day 90 disposition entry) gets push on, per the
 * matrix. Its wording is left to the Phase 4 template alignment because the
 * same type is used by other forfeiture paths.
 */
return new class extends Migration
{
    private const NOTICE = 'reminder_final_warning';

    public function up(): void
    {
        $fields = [
            'title'            => 'Final Notice: Forfeiture Tomorrow',
            'message_template' => 'Final Notice: Your :document_noun :document_name will be forfeited and shredded tomorrow per the 90-day unclaimed policy.',
            'audience'         => 'student_alumni',
            'is_active'        => 1,
            'send_push'        => 1,
            'send_email'       => 1,
        ];

        $existing = DB::table('notification_types')->where('trigger_event', self::NOTICE);

        if ($existing->exists()) {
            $existing->update($fields);
        } else {
            // Id 15 is where DatabaseSeeder keeps this type; take it when
            // free so a fresh migrate --seed lands on one row, not two.
            $id = DB::table('notification_types')->where('notification_type_id', 15)->exists()
                ? max(35, (int) DB::table('notification_types')->max('notification_type_id') + 1)
                : 15;

            DB::table('notification_types')->insert($fields + [
                'notification_type_id' => $id,
                'trigger_event'        => self::NOTICE,
            ]);
        }

        DB::table('notification_types')->where('trigger_event', 'request_forfeited')->update(['send_push' => 1]);

        $this->forget();
    }

    public function down(): void
    {
        DB::table('notification_types')->where('trigger_event', self::NOTICE)->update([
            'title'            => 'Documents Shredded — Request Forfeited',
            'message_template' => 'Your unclaimed documents for request #:request_id have been shredded after 90 days per Registrar policy. Please submit a new request if you still need these documents.',
            'send_push'        => 0,
            'send_email'       => 0,
        ]);
        DB::table('notification_types')->where('trigger_event', 'request_forfeited')->update(['send_push' => 0]);

        $this->forget();
    }

    private function forget(): void
    {
        foreach ([self::NOTICE, 'request_forfeited'] as $trigger) {
            Cache::forget("notif_type:{$trigger}");
        }
    }
};
