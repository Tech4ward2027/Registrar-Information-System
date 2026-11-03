<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Applies config/notification_matrix.php (Policy §3.4) to notification_types.
 *
 * Idempotent and update-only: it never creates a type, so it cannot widen
 * who gets what; types come from the seeders and migrations that own them.
 * Called from a migration (existing databases) and from the seeders (fresh
 * databases), so both end up with the same flags and wording.
 */
final class NotificationMatrixSync
{
    /** @return int number of rows that matched a matrix entry */
    public static function apply(): int
    {
        $matched = 0;

        foreach ((array) config('notification_matrix.types', []) as $trigger => $row) {
            $updated = DB::table('notification_types')
                ->where('trigger_event', $trigger)
                ->update([
                    'send_email'       => $row['email'] ? 1 : 0,
                    'send_push'        => $row['push'] ? 1 : 0,
                    'message_template' => $row['template'],
                ]);

            if ($updated === 0) {
                Log::warning("NotificationMatrixSync: matrix trigger '{$trigger}' has no notification_types row");
                continue;
            }

            $matched++;
            Cache::forget("notif_type:{$trigger}");
        }

        return $matched;
    }
}
