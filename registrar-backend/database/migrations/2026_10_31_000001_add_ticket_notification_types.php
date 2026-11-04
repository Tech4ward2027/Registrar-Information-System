<?php

use Database\Seeders\TicketNotificationTypeSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Support Ticketing — Phase 6. The ten notification types the ticket feature
 * sends (see TicketNotificationTypeSeeder, the single source of the wording).
 *
 * WHY A MIGRATION AND NOT ONLY A SEEDER: NotificationService::send() silently
 * does nothing when no active notification_types row matches the trigger event,
 * so a database that only ever runs `migrate` would never notify anyone. Same
 * reasoning as every earlier notification-type-adding migration.
 *
 * Idempotent: the seeder only inserts rows that are missing, so re-running it
 * (or running it after DatabaseSeeder has) changes nothing and never overwrites
 * an admin's edited wording. down() removes only rows nobody has been
 * notified through, so rolling back after go-live cannot orphan history.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new TicketNotificationTypeSeeder())->run();
    }

    public function down(): void
    {
        $triggers = array_column(TicketNotificationTypeSeeder::types(), 'trigger_event');

        $ids = DB::table('notification_types')->whereIn('trigger_event', $triggers)->pluck('notification_type_id');

        $used = DB::table('notifications')->whereIn('notification_type_id', $ids)->pluck('notification_type_id')->unique();

        DB::table('notification_types')
            ->whereIn('notification_type_id', $ids->diff($used)->all())
            ->delete();
    }
};
