<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Undergrad Requestor Registration — Phase 1 (D1).
 *
 * Inserts role_id = 5, 'undergrad_requestor' into the roles table.
 * SystemUser::ROLE_UNDERGRAD_REQUESTOR is the named constant application
 * code must use — see that file for the "never magic numbers" convention
 * this codebase already enforces for role_id everywhere else.
 *
 * Data-seeded via a migration (not left to DatabaseSeeder alone) so this
 * reference row reliably exists after a plain `php artisan migrate` in
 * any environment, matching the precedent set by
 * 2026_08_03_000005_seed_zero_access_default_policy.php. DatabaseSeeder::
 * seedRoles() is updated in the same change so a full `migrate:fresh
 * --seed` stays consistent with this row.
 *
 * Written idempotently (insertOrIgnore) — safe to re-run from any partial
 * state, matching the rest of this batch.
 */
return new class extends Migration
{
    public const ROLE_ID   = 5;
    public const ROLE_NAME = 'undergrad_requestor';

    public function up(): void
    {
        DB::table('roles')->insertOrIgnore([
            'role_id'   => self::ROLE_ID,
            'role_name' => self::ROLE_NAME,
        ]);
    }

    public function down(): void
    {
        // Only removed if nothing currently references it — restrict-on-delete
        // FK from users.role_id (see create_base_schema) already prevents this
        // from silently orphaning any Undergrad Requestor accounts.
        DB::table('roles')->where('role_id', self::ROLE_ID)->where('role_name', self::ROLE_NAME)->delete();
    }
};
