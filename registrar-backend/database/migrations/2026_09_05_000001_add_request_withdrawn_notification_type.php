<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Deficiency Notice & Withdrawn Status — Phase 1.
 *
 * Inserts the 'request_withdrawn' notification_type row (id 25), fired
 * by DocumentRequestService::withdraw() when staff close out a request
 * via RequestStatusEnum::Withdrawn.
 *
 * :withdrawal_reason in the template is substituted by
 * NotificationService::buildMessage() with the human-readable label from
 * WithdrawalReasonEnum::label() — or, when the reason is Other, with the
 * staff-entered withdrawal_detail free text instead (see
 * DocumentRequestService::withdraw()'s notification call for exactly how
 * that substitution value is built).
 *
 * WHY THIS IS A MIGRATION AND NOT JUST A SEEDER CHANGE: same reasoning as
 * 2026_08_29_000005_add_awaiting_submission_notification_type.php —
 * NotificationService::send() silently no-ops (logs a warning, returns
 * null) if no active notification_types row matches the trigger_event.
 * A database that only ever runs `migrate` (never a fresh `db:seed`)
 * would never get this row if it only lived in DatabaseSeeder.php, and
 * every request_withdrawn notification would be silently swallowed —
 * exactly the same class of gap that migration fixed for
 * 'awaiting_submission' (and flagged, but deliberately did not fix, for
 * the still-outstanding 'pending_signature' case).
 *
 * ID 25: the highest existing notification_type_id is 24
 * ('awaiting_submission', added by the migration cited above), so 25 is
 * the next free slot.
 *
 * IDEMPOTENCY: updateOrInsert, safe to re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('notification_types')->updateOrInsert(
            ['trigger_event' => 'request_withdrawn'],
            [
                'notification_type_id' => 25,
                'title'                => 'Request Withdrawn',
                'message_template'     => 'Your request has been withdrawn: :withdrawal_reason. Please visit the Registrar\'s Office if you have questions.',
                'audience'             => 'student_alumni',
                'is_active'            => 1,
            ]
        );
    }

    public function down(): void
    {
        DB::table('notification_types')->where('trigger_event', 'request_withdrawn')->delete();
    }
};
