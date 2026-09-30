<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Notification types for per-item Withdraw and Close (Phase 4). Sent when
 * ONE item leaves a request that is still alive. When the last remaining
 * item leaves, the existing request_withdrawn / request_closed_unable_
 * to_process notifications go out instead, so the student never receives
 * two messages for one action.
 *
 * Ids 31 and 32: the highest existing notification_type_id is 30. As with
 * the earlier notification-type migrations this must be a migration, not
 * only a seeder change: NotificationService::send() silently skips an
 * unknown trigger. updateOrInsert on trigger_event, safe to re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('notification_types')->updateOrInsert(
            ['trigger_event' => 'request_item_withdrawn'],
            [
                'notification_type_id' => 31,
                'title'                => 'Item Withdrawn',
                'message_template'     => 'An item on your request #:request_id (:item_name) has been withdrawn: :item_reason. Your other items are not affected.',
                'audience'             => 'student_alumni',
                'is_active'            => 1,
            ]
        );

        DB::table('notification_types')->updateOrInsert(
            ['trigger_event' => 'request_item_closed_unable_to_process'],
            [
                'notification_type_id' => 32,
                'title'                => 'Item Closed',
                'message_template'     => 'An item on your request #:request_id (:item_name) could not be processed and was closed: :item_reason. Your other items are not affected.',
                'audience'             => 'student_alumni',
                'is_active'            => 1,
            ]
        );
    }

    public function down(): void
    {
        DB::table('notification_types')
            ->whereIn('trigger_event', ['request_item_withdrawn', 'request_item_closed_unable_to_process'])
            ->delete();
    }
};
