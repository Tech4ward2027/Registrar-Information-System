<?php

namespace Database\Seeders;

use App\Models\TicketCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Ensures the five approved ticket categories (CAT-01 ... CAT-05) exist.
 *
 * Insert-if-absent only, keyed on the unique `code`: a Super Admin who has
 * renamed a category, changed its priority or deactivated it (Phase 8
 * category management) must not have that reverted by a re-seed. Safe to run
 * any number of times:
 *
 *     php artisan db:seed --class=TicketCategorySeeder
 *
 * Also called from DatabaseSeeder so fresh environments and the test suite
 * get the categories. The list itself lives in
 * TicketCategory::STARTER_CATEGORIES.
 */
class TicketCategorySeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        foreach (TicketCategory::STARTER_CATEGORIES as $row) {
            DB::table('ticket_categories')->insertOrIgnore($row + [
                'is_active'  => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
