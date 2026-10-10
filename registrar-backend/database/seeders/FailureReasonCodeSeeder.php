<?php

namespace Database\Seeders;

use App\Models\FailureReasonCode;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Ensures the starter failure-reason codes exist (Phase 2b).
 *
 * Insert-if-absent only: an operator who has edited a description or
 * switched a code off (is_active = false) must not have that reverted by
 * a re-seed. The creating migration already inserts these rows, so this
 * matters mainly for `migrate:fresh --seed` flows and as a repair tool:
 *   php artisan db:seed --class=FailureReasonCodeSeeder
 */
class FailureReasonCodeSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        foreach (FailureReasonCode::STARTER_CODES as $row) {
            DB::table('failure_reason_codes')->insertOrIgnore($row + [
                'is_active'  => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        FailureReasonCode::forgetInactiveCache();
    }
}
