<?php

namespace Database\Seeders;

use App\Models\TicketCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
 *
 * Skips (with a warning) when the table does not exist yet. start.sh runs
 * `db:seed` on every LOCAL boot, which happens before a developer has run
 * `migrate` for a newly pulled branch; under `set -e` a missing table here
 * would crash the container into a restart loop. Staging and production never
 * seed at boot, and the deploy pipeline migrates first, so for them the
 * table always exists.
 */
class TicketCategorySeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('ticket_categories')) {
            $this->command?->warn('ticket_categories does not exist yet; run `php artisan migrate`, then re-seed. Skipping.');

            return;
        }

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