<?php

namespace Database\Seeders;

use App\Enums\NotificationAudienceEnum;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| TicketNotificationTypeSeeder
|--------------------------------------------------------------------------
| Support Ticketing — Phase 6. The notification types the ticket feature sends
| through NotificationService::send(). One per App\Enums\TicketNotificationKindEnum
| case, trigger_event `ticket_<case value>`.
|
| Idempotent and keyed on trigger_event, so it never overwrites an admin's
| edits to a title or template (insert-if-absent), and the database assigns ids
| (see UndergradRequestorNotificationTypeSeeder for why explicit ids are
| avoided). Run by hand with:
|   php artisan db:seed --class=TicketNotificationTypeSeeder
| It is also called from DatabaseSeeder, and by the
| 2026_10_31_000001_add_ticket_notification_types migration, because a missing
| row makes NotificationService::send() log "unknown trigger_event" and do
| nothing: a database that only ever runs `migrate` must still get these.
|
| Placeholders are substituted by NotificationService::buildMessage() from the
| data array the sender passes: :ticket_number, :close_date, :deadline,
| :timeout_hours. Keep them as exact words with no shared prefix.
|
| Audience: requester kinds 'student_alumni' (the label the existing
| request notifications use; NotificationService ignores it for named
| recipients), staff kinds 'admin'. send_push is on for the four kinds a
| requester should see on a lock screen.
|--------------------------------------------------------------------------
*/
class TicketNotificationTypeSeeder extends Seeder
{
    /**
     * @return list<array{trigger_event:string,title:string,message_template:string,audience:string,is_active:bool,send_push:bool}>
     */
    public static function types(): array
    {
        $requester = NotificationAudienceEnum::StudentAlumni->value;
        $staff     = NotificationAudienceEnum::Admin->value;

        return [
            [
                'trigger_event'    => 'ticket_staff_reply',
                'title'            => 'New Reply on Your Ticket',
                'message_template' => 'The Registrar\'s Office replied to your support ticket :ticket_number.',
                'audience'         => $requester, 'is_active' => true, 'send_push' => true,
            ],
            [
                'trigger_event'    => 'ticket_question',
                'title'            => 'We Need More Information',
                'message_template' => 'The Registrar\'s Office needs more information to continue with your support ticket :ticket_number. Please reply.',
                'audience'         => $requester, 'is_active' => true, 'send_push' => true,
            ],
            [
                'trigger_event'    => 'ticket_resolved',
                'title'            => 'Ticket Resolved',
                'message_template' => 'Your support ticket :ticket_number was marked resolved. Please confirm, or tell us if it isn\'t resolved. It closes automatically on :close_date.',
                'audience'         => $requester, 'is_active' => true, 'send_push' => true,
            ],
            [
                'trigger_event'    => 'ticket_resolution_reminder',
                'title'            => 'Ticket Closing Soon',
                'message_template' => 'Your resolved support ticket :ticket_number will close automatically on :close_date unless you reply.',
                'audience'         => $requester, 'is_active' => true, 'send_push' => true,
            ],
            [
                'trigger_event'    => 'ticket_closed',
                'title'            => 'Ticket Closed',
                'message_template' => 'Your support ticket :ticket_number has been closed.',
                'audience'         => $requester, 'is_active' => true, 'send_push' => false,
            ],
            [
                'trigger_event'    => 'ticket_requester_reply',
                'title'            => 'Requester Replied',
                'message_template' => 'The requester replied on support ticket :ticket_number.',
                'audience'         => $staff, 'is_active' => true, 'send_push' => false,
            ],
            [
                'trigger_event'    => 'ticket_returned',
                'title'            => 'Ticket Sent Back',
                'message_template' => 'Support ticket :ticket_number was sent back: the requester says it isn\'t resolved.',
                'audience'         => $staff, 'is_active' => true, 'send_push' => false,
            ],
            [
                'trigger_event'    => 'ticket_sla_at_risk',
                'title'            => 'Ticket Deadline Approaching',
                'message_template' => 'Support ticket :ticket_number is close to missing its :deadline deadline.',
                'audience'         => $staff, 'is_active' => true, 'send_push' => false,
            ],
            [
                'trigger_event'    => 'ticket_sla_breached',
                'title'            => 'Ticket Deadline Missed',
                'message_template' => 'Support ticket :ticket_number has missed its :deadline deadline.',
                'audience'         => $staff, 'is_active' => true, 'send_push' => false,
            ],
            [
                'trigger_event'    => 'ticket_claim_expired',
                'title'            => 'Ticket Claim Released',
                'message_template' => 'Your claim on support ticket :ticket_number was released after :timeout_hours working hours without a reply from you.',
                'audience'         => $staff, 'is_active' => true, 'send_push' => false,
            ],
        ];
    }

    public function run(): void
    {
        // Explicit ids start above 100 so they can never collide with the
        // hand-numbered ids DatabaseSeeder assigns to the base types (1-28 and
        // counting), whichever order `migrate` and `db:seed` run in. Rows that
        // already exist keep whatever id they have.
        $nextId = max(100, (int) DB::table('notification_types')->max('notification_type_id')) + 1;

        foreach (self::types() as $type) {
            $exists = DB::table('notification_types')->where('trigger_event', $type['trigger_event'])->exists();

            if ($exists) {
                continue;
            }

            DB::table('notification_types')->insert($type + [
                'notification_type_id' => $nextId++,
                'created_at'           => now(),
                'updated_at'           => now(),
            ]);
        }

        $this->command?->info('TicketNotificationTypeSeeder: ticket notification types seeded.');
    }
}
