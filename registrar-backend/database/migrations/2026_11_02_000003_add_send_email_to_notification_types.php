<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Notification Policy Section 3.2: System Mailer channel.
 *
 * - notification_types.send_email: the per-type email switch, default off.
 *   Switched on only for the seven policy triggers. App\Services\Mail\MailPolicy
 *   is the second lock: a type flagged here but not in the allow-list still
 *   cannot send.
 * - mail_quota_alert: the Super Admin alert raised when daily mail usage nears
 *   or reaches the configured limit.
 *
 * Rows are updated through the query builder, which bypasses the model
 * observer, so the type cache entries are cleared explicitly.
 */
return new class extends Migration
{
    private const EMAIL_TRIGGERS = [
        'ready_to_claim',
        'reminder_claim',
        'reminder_final_warning',
        'awaiting_submission',
        'deficiency_notice_issued',
        'request_withdrawn',
        'request_closed_unable_to_process',
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('notification_types', 'send_email')) {
            Schema::table('notification_types', function (Blueprint $table) {
                $table->boolean('send_email')->default(false)->after('send_push');
            });
        }

        DB::table('notification_types')
            ->whereIn('trigger_event', self::EMAIL_TRIGGERS)
            ->update(['send_email' => true]);

        $fields = [
            'title'            => 'Mailer Quota Alert',
            'message_template' => 'Mailer quota alert: :summary',
            'audience'         => 'super_admin',
            'is_active'        => 1,
        ];

        if (DB::table('notification_types')->where('trigger_event', 'mail_quota_alert')->exists()) {
            DB::table('notification_types')->where('trigger_event', 'mail_quota_alert')->update($fields);
        } else {
            DB::table('notification_types')->insert($fields + [
                'notification_type_id' => max(35, (int) DB::table('notification_types')->max('notification_type_id') + 1),
                'trigger_event'        => 'mail_quota_alert',
            ]);
        }

        foreach ([...self::EMAIL_TRIGGERS, 'mail_quota_alert'] as $trigger) {
            Cache::forget("notif_type:{$trigger}");
        }
    }

    public function down(): void
    {
        DB::table('notification_types')->where('trigger_event', 'mail_quota_alert')->delete();

        if (Schema::hasColumn('notification_types', 'send_email')) {
            Schema::table('notification_types', function (Blueprint $table) {
                $table->dropColumn('send_email');
            });
        }
    }
};
