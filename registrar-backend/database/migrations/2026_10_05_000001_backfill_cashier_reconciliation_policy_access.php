<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cashier Reconciliation — Phase 1.
 *
 * The unmatched-cashier-items routes used to sit in the bare `role:3`
 * group with no module gate, so every registrar admin could call them.
 * They now require the new `cashier_reconciliation` policy module. To
 * keep "no admin loses or gains access unintentionally", this grants
 * `["Access"]` on that module to every existing policy, then lets a
 * Super Admin narrow it afterwards through Policy Management.
 *
 * One deliberate exception: the zero-access fallback policy
 * (Policy::DEFAULT_NAME, "No Access"). It exists so an admin with no
 * policy attached gets NOTHING (see
 * 2026_08_03_000005_seed_zero_access_default_policy). Granting this
 * module to it would silently reopen that fail-closed default, so it is
 * skipped. Consequence: an admin who has no policy attached (or is on
 * "No Access") and previously could reach the unmatched-items screen
 * through the old ungated route no longer can, which matches the intent
 * of that policy.
 *
 * Idempotent and non-destructive: a policy that already has the key (a
 * Super Admin set it deliberately, including an explicit empty array) is
 * never overwritten, so re-running cannot undo a narrowing.
 *
 * The literal strings are inlined (not Policy::DEFAULT_NAME) on purpose:
 * migrations must keep working even if the model constant later changes.
 */
return new class extends Migration
{
    private const MODULE = 'cashier_reconciliation';
    private const ZERO_ACCESS_POLICY_NAME = 'No Access';

    public function up(): void
    {
        DB::table('policies')
            ->where('name', '!=', self::ZERO_ACCESS_POLICY_NAME)
            ->orderBy('policy_id')
            ->get()
            ->each(function ($row) {
                $permissions = json_decode($row->permissions ?? '[]', true);
                $permissions = is_array($permissions) ? $permissions : [];

                if (array_key_exists(self::MODULE, $permissions)) {
                    return;
                }

                $permissions[self::MODULE] = ['Access'];

                DB::table('policies')
                    ->where('policy_id', $row->policy_id)
                    ->update([
                        'permissions' => json_encode($permissions),
                        'updated_at'  => now(),
                    ]);
            });
    }

    /**
     * Removes the key from every policy. Mirrors up(): after rollback the
     * module no longer exists, so no policy should reference it.
     */
    public function down(): void
    {
        DB::table('policies')->orderBy('policy_id')->get()->each(function ($row) {
            $permissions = json_decode($row->permissions ?? '[]', true);
            $permissions = is_array($permissions) ? $permissions : [];

            if (!array_key_exists(self::MODULE, $permissions)) {
                return;
            }

            unset($permissions[self::MODULE]);

            DB::table('policies')
                ->where('policy_id', $row->policy_id)
                ->update([
                    'permissions' => json_encode($permissions),
                    'updated_at'  => now(),
                ]);
        });
    }
};
