<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data Retention & Disposal Policy — Section 3.3/3.4.
 *
 * Inserts two new notification_types rows:
 *
 *   - deficiency_notice_escalated (id 29) — fired by
 *     EscalateStaleDeficiencyNotices when an open Deficiency Notice
 *     crosses the 30-day compliance window (§3.3). Audience: 'admin'
 *     (Registrar Admin review, per §3.4 — the requestor is NOT notified
 *     of this internal escalation event, only of the eventual outcome
 *     staff decide on: extension, ordinary Withdrawn, or
 *     ClosedUnableToProcess). :item_label and :request_id are
 *     substituted from the escalated RequestRemark.
 *
 *   - request_closed_unable_to_process (id 30) — fired by
 *     DocumentRequestService::closeUnableToProcess() when a request is
 *     closed under §3.4's "worst-case scenario" procedure.
 *     :closure_reason is substituted with ClosureReasonEnum::label(),
 *     or — when closure_reason is Other — with the staff-entered
 *     closure_detail free text instead, same substitution rule
 *     DocumentRequestService::withdraw() already uses for
 *     :withdrawal_reason. Audience: 'student_alumni' — sent as a
 *     best-effort record for the account on file (which may belong to
 *     a deceased requestor); per the Data Retention & Disposal
 *     Policy §3.2, this notification/inbox/email record itself is
 *     retained for its own defined period (3 years) regardless of
 *     whether anyone ever reads it, to preserve the audit trail.
 *
 * WHY THIS IS A MIGRATION AND NOT JUST A SEEDER CHANGE: same reasoning
 * as every prior notification-type-adding migration in this set (see
 * 2026_09_05_000001_add_request_withdrawn_notification_type.php and
 * 2026_09_06_*_deficiency_notice_notification_types.php) —
 * NotificationService::send()/sendToAdmins() silently no-ops (logs a
 * warning) if no active notification_types row matches the trigger
 * event. A database that only ever runs `migrate` would never get
 * these rows if they only lived in DatabaseSeeder.php.
 *
 * IDs 29-30: the highest existing notification_type_id is 28
 * ('deficiency_notice_voided', added by the Phase 3 migration), so 29
 * and 30 are the next free slots.
 *
 * IDEMPOTENCY: updateOrInsert per row, safe to re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('notification_types')->updateOrInsert(
            ['trigger_event' => 'deficiency_notice_escalated'],
            [
                'notification_type_id' => 29,
                'title'                => 'Deficiency Notice Escalated',
                'message_template'     => 'Deficiency Notice on request #:request_id (:item_label) has been open for 30+ days without resolution and requires review.',
                'audience'             => 'admin',
                'is_active'            => 1,
            ]
        );

        DB::table('notification_types')->updateOrInsert(
            ['trigger_event' => 'request_closed_unable_to_process'],
            [
                'notification_type_id' => 30,
                'title'                => 'Request Closed — Unable to Process',
                'message_template'     => 'Your request could not be completed and has been closed: :closure_reason. Please contact the Registrar\'s Office if you have questions.',
                'audience'             => 'student_alumni',
                'is_active'            => 1,
            ]
        );
    }

    public function down(): void
    {
        DB::table('notification_types')->whereIn('trigger_event', [
            'deficiency_notice_escalated',
            'request_closed_unable_to_process',
        ])->delete();
    }
};
