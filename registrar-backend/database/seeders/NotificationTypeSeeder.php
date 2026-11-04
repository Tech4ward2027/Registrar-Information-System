<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| NotificationTypeSeeder
|--------------------------------------------------------------------------
| Seeds the two scheduler-driven notification types introduced by the
| unclaimed-document policy.
|
| Run with:
|   php artisan db:seed --class=NotificationTypeSeeder
|
| updateOrInsert() makes this idempotent — safe to re-run at any time.
|--------------------------------------------------------------------------
*/

class NotificationTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            [
                'trigger_event'    => 'reminder_claim',
                'title'            => 'Reminder: Documents Ready for Pickup',
                'message_template' => "Your documents for request #:request_id have been ready for 7 days. "
                                    . "Please claim them at the Registrar's Office. "
                                    . "Unclaimed documents are shredded after 90 days.",
                'audience'         => 'student_alumni',
                'is_active'        => true,
            ],
            [
                'trigger_event'    => 'reminder_final_warning',
                'title'            => 'Final Notice: Forfeiture Tomorrow',
                'message_template' => 'Final Notice: Your :document_noun :document_name will be forfeited '
                                    . 'and shredded tomorrow per the 90-day unclaimed policy.',
                'send_push'        => true,
                'send_email'       => true,
                'audience'         => 'student_alumni',
                'is_active'        => true,
            ],
        ];

        foreach ($types as $type) {
            DB::table('notification_types')->updateOrInsert(
                ['trigger_event' => $type['trigger_event']],
                array_merge($type, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }

        $this->command->info('NotificationTypeSeeder: reminder_claim and the Day 89 reminder_final_warning seeded.');
    }
}