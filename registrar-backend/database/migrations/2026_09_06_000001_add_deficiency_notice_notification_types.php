<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Deficiency Notice & Withdrawn Status — Phase 3.
 *
 * Inserts the three notification_types rows fired by
 * DeficiencyNoticeService as a Deficiency Notice moves through its
 * lifecycle (issue → clear | void):
 *
 *   - deficiency_notice_issued  (id 26) — fired by issue().
 *     :item_label is substituted with DeficiencyItemEnum::label(), or
 *     — when item_key is Other — with the staff-entered detail free
 *     text instead. Same substitution rule
 *     DocumentRequestService::withdraw() already uses for
 *     :withdrawal_reason.
 *   - deficiency_notice_cleared (id 27) — fired by clear(). No
 *     placeholders; the request simply resumes processing.
 *   - deficiency_notice_voided  (id 28) — fired by void(). :void_reason
 *     is substituted with the staff-entered void_reason free text
 *     verbatim (there is no enum behind this field — void is the
 *     "never resolved" escalation outcome and the reason is always a
 *     one-off staff explanation, not a fixed list).
 *
 * WHY THIS IS A MIGRATION AND NOT JUST A SEEDER CHANGE: same reasoning
 * as 2026_08_29_000005_add_awaiting_submission_notification_type.php
 * and 2026_09_05_000001_add_request_withdrawn_notification_type.php —
 * NotificationService::send() silently no-ops (logs a warning, returns
 * null) if no active notification_types row matches the trigger_event.
 * A database that only ever runs `migrate` would never get these rows
 * if they only lived in DatabaseSeeder.php, and every Deficiency Notice
 * notification would be silently swallowed.
 *
 * IDs 26–28: the highest existing notification_type_id is 25
 * ('request_withdrawn', added by the Phase 1 migration cited above), so
 * 26, 27, 28 are the next free slots.
 *
 * IDEMPOTENCY: updateOrInsert per row, safe to re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('notification_types')->updateOrInsert(
            ['trigger_event' => 'deficiency_notice_issued'],
            [
                'notification_type_id' => 26,
                'title'                => 'Deficiency Notice Issued',
                'message_template'     => 'Your request is on hold: :item_label. Please visit the Registrar\'s Office to submit this item.',
                'audience'             => 'student_alumni',
                'is_active'            => 1,
            ]
        );

        DB::table('notification_types')->updateOrInsert(
            ['trigger_event' => 'deficiency_notice_cleared'],
            [
                'notification_type_id' => 27,
                'title'                => 'Deficiency Notice Cleared',
                'message_template'     => 'Your submitted item has been received. Your request is resuming processing.',
                'audience'             => 'student_alumni',
                'is_active'            => 1,
            ]
        );

        DB::table('notification_types')->updateOrInsert(
            ['trigger_event' => 'deficiency_notice_voided'],
            [
                'notification_type_id' => 28,
                'title'                => 'Deficiency Notice Voided',
                'message_template'     => 'Your request will not proceed: :void_reason. Please contact the Registrar\'s Office.',
                'audience'             => 'student_alumni',
                'is_active'            => 1,
            ]
        );
    }

    public function down(): void
    {
        DB::table('notification_types')->whereIn('trigger_event', [
            'deficiency_notice_issued',
            'deficiency_notice_cleared',
            'deficiency_notice_voided',
        ])->delete();
    }
};
