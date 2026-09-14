<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Undergrad Requestor Registration — Phase 1 (D2).
 *
 * Widens users.status with two new values, both deliberately distinct
 * from the existing admin-invite pair (see
 * 2026_08_03_000000_add_pending_activation_status_and_nullable_password.php):
 *
 *   'Pending Verification' — RIS has pre-created the account row at
 *                             public onboarding submission time
 *                             (idp_user_id = NULL). Waiting on a
 *                             Registrar Admin decision, not on an IDP
 *                             link — a different cause from
 *                             'Pending Activation', which is already
 *                             assigned and only waiting on first SSO
 *                             login. Set by
 *                             UndergradRequestorRegistrationService.
 *   'Rejected'              — an Admin explicitly declined the
 *                             verification (undergrad_requestor_
 *                             verifications.status = Rejected). A
 *                             different cause from 'Deactivated' (was
 *                             fine, then turned off) — see the new
 *                             AccountRejectedException (Phase 3, D8).
 *                             Set by the Admin reject action
 *                             (Phase 4).
 *
 * Same MySQL-MODIFY / SQLite-no-op split as the migration above, for the
 * same reason: MySQL stores `status` as a real ENUM requiring a column
 * rebuild to add values; SQLite (test suite) has no enforced ENUM
 * constraint, so nothing to widen there.
 *
 * Idempotent by nature (re-issuing the same MODIFY is a no-op on MySQL);
 * no guard needed, matching the precedent this mirrors.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE users MODIFY status ENUM('Activated', 'Deactivated', 'Pending Activation', 'Expired', 'Pending Verification', 'Rejected') NOT NULL DEFAULT 'Activated'"
            );
            return;
        }

        // SQLite (test suite): status has no real ENUM constraint to widen.
        // Confirm the column exists so this migration still fails loudly
        // if run against a schema that predates the base users table.
        if (!Schema::hasColumn('users', 'status')) {
            throw new \RuntimeException('users.status column not found — is create_base_schema up to date?');
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            // Only safe to revert if no rows currently use the new values —
            // left as a manual/ops step rather than silently truncating
            // data on a down() nobody expects to lose records, matching
            // the precedent this mirrors.
            DB::statement(
                "ALTER TABLE users MODIFY status ENUM('Activated', 'Deactivated', 'Pending Activation', 'Expired') NOT NULL DEFAULT 'Activated'"
            );
        }
    }
};
