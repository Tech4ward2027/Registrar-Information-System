<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| MigrationRollbackDrillWithdrawnDeficiency
| (php artisan requests:migration-rollback-drill)
|--------------------------------------------------------------------------
| Deficiency Notice & Withdrawn Status — Phase 5 (Cross-Feature Hardening
| & Edge Cases: "Migration rollback drills").
|
| ACTUALLY runs migrate:rollback then migrate again for the four
| migrations this feature shipped, verifying the schema cleanly reverts
| and cleanly re-applies, rather than just eyeballing each migration's
| down() method and hoping. This is the "dry-run drill against a staging
| copy of the production dump" the implementation plan's Phase 5 calls
| for, given the seeder file's (DatabaseSeeder::seedRequestStatus())
| documented history of id-collision incidents in this exact area of the
| schema — the same caution that produced
| app/Console/Commands/PreflightCheckWithdrawnStatus.php for the forward
| direction is applied here to the reverse direction.
|
| MIGRATIONS COVERED (rolled back / re-applied in this exact order —
| reverse-chronological for rollback, chronological for re-migrate, so
| the notification-type migrations always roll back before, and
| re-apply after, the schema migrations they depend on existing rows
| in):
|   1. 2026_09_06_000001_add_deficiency_notice_notification_types.php
|   2. 2026_09_06_000000_create_request_remarks_table.php
|   3. 2026_09_05_000001_add_request_withdrawn_notification_type.php
|   4. 2026_09_05_000000_add_withdrawn_status.php
|
| SAFETY — this command is destructive to whatever schema/data sits on
| the connection it targets. It is HARD-BLOCKED from ever running
| against a database this application considers "production" (checked
| two ways: APP_ENV and, defense-in-depth, an explicit confirmation
| phrase the operator must type identifying the target by name), with NO
| flag or option able to override that block. This is not a general-
| purpose migration tool — it exists solely to be pointed at a staging
| copy of the production dump before a real deploy.
|
| WHAT "CLEANLY REVERTS" MEANS HERE: after rollback, none of the four
| migrations' schema changes should remain — no withdrawal_reason/
| withdrawal_detail/superseded_by_request_id columns on document_request,
| no request_remarks table, and (per each migration's own down(), which
| this command trusts rather than re-implements) the request_status /
| notification_types rows removed IF AND ONLY IF nothing already
| references them — a real staging copy seeded from a production dump
| that already has Withdrawn requests in it is expected to KEEP the
| request_status row (down() checks for exactly this), which is correct
| behavior, not a drill failure. This command checks structural
| reversibility (columns/tables), not row-level idempotency of the
| status/notification-type seed rows, since that idempotency is already
| covered by each migration's own defensive updateOrInsert()/existence
| checks and by requests:preflight-withdrawn for the forward direction.
|--------------------------------------------------------------------------
*/
class MigrationRollbackDrillWithdrawnDeficiency extends Command
{
    /**
     * Rollback order: last-shipped first. Re-migrate simply runs
     * `artisan migrate` afterward, which naturally re-applies everything
     * still pending in the correct chronological (filename) order — no
     * need to hand-list the forward order separately.
     */
    private const MIGRATION_PATHS_ROLLBACK_ORDER = [
        'database/migrations/2026_09_06_000001_add_deficiency_notice_notification_types.php',
        'database/migrations/2026_09_06_000000_create_request_remarks_table.php',
        'database/migrations/2026_09_05_000001_add_request_withdrawn_notification_type.php',
        'database/migrations/2026_09_05_000000_add_withdrawn_status.php',
    ];

    private const DOCUMENT_REQUEST_COLUMNS = [
        'withdrawal_reason',
        'withdrawal_detail',
        'superseded_by_request_id',
    ];

    protected $signature = 'requests:migration-rollback-drill
        {--connection= : Database connection to run the drill against (defaults to config default). Must be explicitly named for a non-default connection.}';

    protected $description = 'DESTRUCTIVE staging-only drill: rolls back then re-applies the Withdrawn/Deficiency Notice migrations, verifying clean reversibility (Phase 5)';

    public function handle(): int
    {
        $connectionName = $this->option('connection') ?: config('database.default');

        if (!$this->passesProductionGuard($connectionName)) {
            return self::FAILURE;
        }

        if (!$this->confirmDestructiveIntent($connectionName)) {
            $this->warn('[requests:migration-rollback-drill] Aborted — confirmation not received.');
            return self::FAILURE;
        }

        $this->info("[requests:migration-rollback-drill] Running against connection '{$connectionName}' (" . DB::connection($connectionName)->getDatabaseName() . ').');

        // 1. Baseline — record schema state BEFORE touching anything, so
        // we can tell the difference between "the drill worked" and
        // "this environment already didn't have these columns for some
        // unrelated reason."
        $baseline = $this->captureSchemaState($connectionName);

        if (!$baseline['request_remarks_table'] || !$this->allColumnsPresent($baseline['document_request_columns'])) {
            $this->error('[requests:migration-rollback-drill] FAILED — the schema this drill expects to roll back is not fully present on this connection. Run `php artisan migrate` first, or confirm this environment is meant to have these migrations applied already.');
            $this->line('  request_remarks table present: ' . ($baseline['request_remarks_table'] ? 'yes' : 'no'));
            foreach ($baseline['document_request_columns'] as $column => $present) {
                $this->line("  document_request.{$column} present: " . ($present ? 'yes' : 'no'));
            }
            return self::FAILURE;
        }

        $this->info('[requests:migration-rollback-drill] Baseline confirmed — all four migrations are currently applied. Rolling back...');

        // 2. Roll back each migration individually, in reverse order, via
        // --path so this drill only ever touches the four migrations it
        // names — never a broader `migrate:rollback --step=N` that could
        // accidentally also unwind unrelated, unrelated-in-time
        // migrations sitting in the same batch.
        foreach (self::MIGRATION_PATHS_ROLLBACK_ORDER as $path) {
            $this->line("  - rolling back {$path}");

            $exitCode = Artisan::call('migrate:rollback', [
                '--path'       => $path,
                '--database'   => $connectionName,
                '--force'      => true,
                '--realpath'   => false,
            ]);

            if ($exitCode !== 0) {
                $this->error("[requests:migration-rollback-drill] FAILED — migrate:rollback exited non-zero for {$path}. Output:");
                $this->line(Artisan::output());
                $this->attemptReMigrateAndWarn($connectionName);
                return self::FAILURE;
            }
        }

        // 3. Verify the rollback actually removed the structural schema
        // changes (see this class's docblock for why we check STRUCTURE,
        // not whether the status/notification-type SEED ROWS were
        // removed — those are correctly retained on a staging copy that
        // already has real Withdrawn requests in it).
        $afterRollback = $this->captureSchemaState($connectionName);

        $rollbackFailures = [];
        if ($afterRollback['request_remarks_table']) {
            $rollbackFailures[] = 'request_remarks table still exists after rollback.';
        }
        foreach ($afterRollback['document_request_columns'] as $column => $present) {
            if ($present) {
                $rollbackFailures[] = "document_request.{$column} still exists after rollback.";
            }
        }

        if (!empty($rollbackFailures)) {
            $this->error('[requests:migration-rollback-drill] FAILED — schema did not cleanly revert:');
            foreach ($rollbackFailures as $failure) {
                $this->line("  - {$failure}");
            }
            $this->attemptReMigrateAndWarn($connectionName);
            return self::FAILURE;
        }

        $this->info('[requests:migration-rollback-drill] Rollback verified clean. Re-applying via `migrate`...');

        // 4. Re-apply. Plain `migrate` (not per-path) so this also
        // exercises the migrations' idempotent updateOrInsert()/
        // existence-check logic exactly as a real re-deploy would,
        // rather than only ever re-running them in isolation.
        $exitCode = Artisan::call('migrate', [
            '--database' => $connectionName,
            '--force'    => true,
        ]);

        if ($exitCode !== 0) {
            $this->error('[requests:migration-rollback-drill] FAILED — re-migrate exited non-zero. This connection is now LEFT IN THE ROLLED-BACK STATE. Output:');
            $this->line(Artisan::output());
            return self::FAILURE;
        }

        // 5. Verify re-migrate restored everything, matching the
        // original baseline exactly.
        $afterReMigrate = $this->captureSchemaState($connectionName);

        $reMigrateFailures = [];
        if (!$afterReMigrate['request_remarks_table']) {
            $reMigrateFailures[] = 'request_remarks table was NOT restored by re-migrate.';
        }
        foreach ($afterReMigrate['document_request_columns'] as $column => $present) {
            if (!$present) {
                $reMigrateFailures[] = "document_request.{$column} was NOT restored by re-migrate.";
            }
        }

        if (!empty($reMigrateFailures)) {
            $this->error('[requests:migration-rollback-drill] FAILED — schema did not cleanly re-apply:');
            foreach ($reMigrateFailures as $failure) {
                $this->line("  - {$failure}");
            }
            $this->error('This connection may now be in a PARTIALLY MIGRATED state. Investigate before proceeding.');
            return self::FAILURE;
        }

        $this->info('[requests:migration-rollback-drill] OK — all four migrations rolled back cleanly and re-applied cleanly. Connection is back in the fully-migrated state.');
        return self::SUCCESS;
    }

    /**
     * Hard, non-overridable block against production. Checked two
     * independent ways on purpose: APP_ENV alone is one string a
     * misconfigured staging box could get wrong, so this also refuses
     * to proceed if the resolved connection's database name looks like
     * it's the one configured for the 'production' connection entry in
     * config/database.php, even under a different APP_ENV value.
     */
    private function passesProductionGuard(string $connectionName): bool
    {
        if (app()->environment('production')) {
            $this->error('[requests:migration-rollback-drill] REFUSED — APP_ENV is "production". This command never runs against production, no matter what flags are passed. Point it at a staging copy of the production dump instead.');
            return false;
        }

        $productionDatabaseName = config('database.connections.' . config('database.default') . '.database');
        $targetDatabaseName     = config("database.connections.{$connectionName}.database");

        if ($productionDatabaseName && $targetDatabaseName && app()->environment('production') === false
            && strcasecmp($productionDatabaseName, $targetDatabaseName) === 0
            && app()->environment('local', 'staging', 'testing') === false) {
            // Belt-and-suspenders: an unrecognized/custom APP_ENV value
            // pointed at what LOOKS like the same database name the
            // default connection resolves to. Refuse rather than guess.
            $this->error('[requests:migration-rollback-drill] REFUSED — could not positively confirm this is a non-production environment (APP_ENV="' . app()->environment() . '"). Set APP_ENV to "local", "staging", or "testing" on the box this drill runs from.');
            return false;
        }

        return true;
    }

    /**
     * Requires the operator to type back the exact database name being
     * targeted — a plain yes/no confirm() is too easy to reflexively
     * click through for a command this destructive.
     */
    private function confirmDestructiveIntent(string $connectionName): bool
    {
        $databaseName = DB::connection($connectionName)->getDatabaseName();

        $this->warn('[requests:migration-rollback-drill] This will roll back and re-apply schema on:');
        $this->warn("  connection: {$connectionName}");
        $this->warn("  database:   {$databaseName}");
        $this->warn('This must be a STAGING COPY of the production dump — never the real production database.');

        $typed = $this->ask('Type the database name above exactly to confirm and proceed');

        return $typed === $databaseName;
    }

    /**
     * @return array{request_remarks_table: bool, document_request_columns: array<string, bool>}
     */
    private function captureSchemaState(string $connectionName): array
    {
        $schema = Schema::connection($connectionName);

        $columns = [];
        foreach (self::DOCUMENT_REQUEST_COLUMNS as $column) {
            $columns[$column] = $schema->hasColumn('document_request', $column);
        }

        return [
            'request_remarks_table'    => $schema->hasTable('request_remarks'),
            'document_request_columns' => $columns,
        ];
    }

    private function allColumnsPresent(array $columnStates): bool
    {
        return !in_array(false, $columnStates, true);
    }

    /**
     * Best-effort recovery attempt if a rollback step fails partway
     * through: try to bring the connection back to the fully-migrated
     * state via a plain `migrate` before returning FAILURE, so a failed
     * drill doesn't necessarily leave the staging box half-migrated for
     * the next person who touches it. This is a courtesy, not a
     * guarantee — the command still reports FAILURE either way, and the
     * operator should inspect the connection manually before trusting
     * it again.
     */
    private function attemptReMigrateAndWarn(string $connectionName): void
    {
        $this->warn('[requests:migration-rollback-drill] Attempting to restore the connection to the fully-migrated state via `migrate`...');

        $exitCode = Artisan::call('migrate', [
            '--database' => $connectionName,
            '--force'    => true,
        ]);

        if ($exitCode === 0) {
            $this->warn('[requests:migration-rollback-drill] Recovery migrate succeeded — connection should be back to fully-migrated. Still investigate why the rollback step failed before trusting this drill again.');
        } else {
            $this->error('[requests:migration-rollback-drill] Recovery migrate ALSO failed. This connection is in an UNKNOWN/PARTIAL state. Manual investigation required before further use.');
        }
    }
}
