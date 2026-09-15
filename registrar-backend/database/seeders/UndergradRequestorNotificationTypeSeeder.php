<?php

namespace Database\Seeders;

use App\Enums\NotificationAudienceEnum;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| UndergradRequestorNotificationTypeSeeder
|--------------------------------------------------------------------------
| Undergrad Requestor Registration — Phase 4.
|
| Seeds the two decision notification types the Admin verification
| workflow fires through NotificationService::send().
|
| Run with:
|   php artisan db:seed --class=UndergradRequestorNotificationTypeSeeder
|
| Also invoked from DatabaseSeeder::run(), so `migrate:fresh --seed` and
| every RefreshDatabase test run pick these up automatically — without
| which NotificationService::send() would log "unknown trigger_event" and
| return null, and the decision notification would silently never exist.
|
| ── Why a standalone seeder, keyed on trigger_event ───────────────────
| DatabaseSeeder::seedNotificationTypes() assigns explicit
| notification_type_id values (1-24 today). Adding rows there means
| hand-picking the next free integer and hoping no parallel branch picks
| the same one — a merge conflict that resolves cleanly in Git and
| silently corrupts data. Keying on trigger_event instead (the same
| pattern NotificationTypeSeeder already uses for the unclaimed-document
| types) lets the database assign the id and makes the seeder idempotent
| on the column that actually identifies a notification type.
|
| ── Audience ──────────────────────────────────────────────────────────
| NotificationAudienceEnum has no case for Undergrad Requestor, and
| adding one would require a MySQL ENUM migration on
| notification_types.audience for a column that only labels notifications
| in the admin-facing template manager — NotificationService::send()
| ignores it entirely when delivering to a named recipient. `All` is the
| honest existing value here; revisit only if the audience column ever
| becomes a real delivery filter.
|--------------------------------------------------------------------------
*/
class UndergradRequestorNotificationTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            [
                // Fired by UndergradRequestorVerificationService::approve().
                // The recipient cannot have a live session at this point
                // (approval is what unlocks their first login), so this
                // row is waiting for them the moment they do log in — the
                // email sent alongside it, via UndergradRequestorDecisionMail,
                // is what actually reaches them today.
                'trigger_event'    => 'undergrad_requestor_approved',
                'title'            => 'Registration Approved',
                'message_template' => 'Your Undergrad Requestor registration has been approved. Sign in with the '
                                    . 'same email address you used on the onboarding form to activate your account.',
                'audience'         => NotificationAudienceEnum::All->value,
                'is_active'        => true,
            ],
            [
                // Fired by UndergradRequestorVerificationService::reject().
                // :reason is substituted by NotificationService::buildMessage()
                // from the data array the service passes.
                'trigger_event'    => 'undergrad_requestor_rejected',
                'title'            => 'Registration Not Approved',
                'message_template' => 'Your Undergrad Requestor registration was not approved. Reason: :reason. '
                                    . 'Please contact the Registrar\'s Office if you believe this was an error.',
                'audience'         => NotificationAudienceEnum::All->value,
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

        $this->command?->info('UndergradRequestorNotificationTypeSeeder: approved/rejected notification types seeded.');
    }
}
