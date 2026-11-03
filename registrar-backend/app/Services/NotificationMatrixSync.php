<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Applies config/notification_matrix.php (Policy §3.4) to notification_types.
 *
 * Idempotent. Flags and wording are updated on every matrix trigger. A type
 * is created only when its matrix entry carries an explicit 'create' block
 * (fixed id, title, audience); everything else must already exist, so this
 * can never widen who receives what by accident. Called from a migration
 * (existing databases) and from the seeders (fresh databases), so both end
 * up identical.
 */
final class NotificationMatrixSync
{
    /** @return int number of matrix triggers that now have a row */
    public static function apply(): int
    {
        $matched = 0;

        foreach ((array) config('notification_matrix.types', []) as $trigger => $row) {
            $exists = DB::table('notification_types')->where('trigger_event', $trigger)->exists();

            if (! $exists && isset($row['create'])) {
                self::create($trigger, $row);
                $exists = true;
            }

            if (! $exists) {
                Log::warning("NotificationMatrixSync: matrix trigger '{$trigger}' has no notification_types row");
                continue;
            }

            DB::table('notification_types')
                ->where('trigger_event', $trigger)
                ->update([
                    'send_email'       => $row['email'] ? 1 : 0,
                    'send_push'        => $row['push'] ? 1 : 0,
                    'message_template' => $row['template'],
                ]);

            $matched++;
            Cache::forget("notif_type:{$trigger}");
        }

        return $matched;
    }

    private static function create(string $trigger, array $row): void
    {
        $create = $row['create'];

        // Prefer the fixed id so every environment agrees; fall back to the
        // next free id if some environment already used it for something else.
        $id = DB::table('notification_types')->where('notification_type_id', $create['id'])->exists()
            ? (int) DB::table('notification_types')->max('notification_type_id') + 1
            : (int) $create['id'];

        DB::table('notification_types')->insert([
            'notification_type_id' => $id,
            'trigger_event'        => $trigger,
            'title'                => $create['title'],
            'message_template'     => $row['template'],
            'audience'             => $create['audience'],
            'is_active'            => 1,
            'send_push'            => $row['push'] ? 1 : 0,
            'send_email'           => $row['email'] ? 1 : 0,
        ]);
    }
}