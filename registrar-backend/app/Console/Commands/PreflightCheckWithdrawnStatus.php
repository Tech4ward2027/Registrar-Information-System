<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| PreflightCheckWithdrawnStatus  (php artisan requests:preflight-withdrawn)
|--------------------------------------------------------------------------
| Deficiency Notice & Withdrawn Status — Phase 0 (Pre-flight & Groundwork).
|
| Read-only. Run this against a real database (a staging copy of the
| production dump, or production itself under a read replica) BEFORE
| deploying 2026_09_05_000000_add_withdrawn_status.php. It performs the
| exact checks the implementation plan calls for, in one command instead
| of hand-typed SQL, and exits non-zero if anything looks wrong so it can
| gate a deploy pipeline as well as be run interactively.
|
| This mirrors — rather than replaces — the documented "IMPORTANT: run
| before deploying" SQL blocks already used by house convention (see
| 2026_08_15_000000_add_pending_signature_status.php and
| 2026_08_29_000004_add_awaiting_submission_status.php's docblocks). Those
| migrations only ever documented the queries; this project has since
| accumulated three of these one-off "is this status_id really free"
| incidents (the abandoned "Pending" at id=6 landmine being the original),
| so this command exists to make the check itself run-able and
| CI-gate-able rather than relying on a human to copy/paste SQL correctly
| every time a new status is introduced.
|
| Checks performed:
|   1. document_request has zero rows at status_id = 13.
|   2. request_history has zero rows referencing status_id = 13 (as
|      either old_status_id or new_status_id).
|   3. request_status.status_id = 13 either doesn't exist yet, or already
|      exists with status_name EXACTLY 'Withdrawn' (i.e. re-running this
|      after the migration has already shipped is still a clean pass —
|      matches the idempotent updateOrInsert() the migration itself uses).
|   4. No OTHER row in request_status is already named exactly
|      'Withdrawn' under a different status_id — would indicate the
|      value was seeded by hand outside of this migration.
|   5. No row in notification_types already uses trigger_event =
|      'request_withdrawn' under a different notification_type_id than
|      the one this feature will use — see the notification-type
|      migration this preflight also protects.
|
| NOTE ON THE FRONTEND COLLISION CHECK (Phase 0 item 2): whether
| "withdrawn" collides with staffDashboardUtils.js's exact-match
| "pending" lookup is a STATIC property of that file's source code, not
| of any database's data — it cannot be checked by querying a database.
| That check was done by reading staffDashboardUtils.js directly (see
| the Phase 0 write-up); this command only checks the parts of Phase 0
| that ARE database facts.
|--------------------------------------------------------------------------
*/
class PreflightCheckWithdrawnStatus extends Command
{
    private const WITHDRAWN_STATUS_ID   = 13;
    private const WITHDRAWN_STATUS_NAME = 'Withdrawn';
    private const WITHDRAWN_TRIGGER_EVENT = 'request_withdrawn';

    protected $signature   = 'requests:preflight-withdrawn';
    protected $description = 'Read-only pre-flight checks before deploying the Withdrawn-status migration (Deficiency Notice & Withdrawn Status, Phase 0)';

    public function handle(): int
    {
        $this->info('[requests:preflight-withdrawn] Running Phase 0 checks against: ' . DB::connection()->getDatabaseName());

        $failures = [];

        $failures = array_merge($failures, $this->checkNoExistingUsage());
        $failures = array_merge($failures, $this->checkStatusRowState());
        $failures = array_merge($failures, $this->checkNoDuplicateStatusName());
        $failures = array_merge($failures, $this->checkNoNotificationTriggerCollision());

        if (empty($failures)) {
            $this->info('[requests:preflight-withdrawn] OK — status_id 13 is free and safe to claim as "Withdrawn". Safe to deploy the migration.');
            return self::SUCCESS;
        }

        $this->error('[requests:preflight-withdrawn] FAILED — ' . count($failures) . ' issue(s) found. STOP and investigate before deploying:');
        foreach ($failures as $failure) {
            $this->line("  - {$failure}");
        }

        return self::FAILURE;
    }

    /**
     * Mirrors the two SQL statements documented (but not enforced) in
     * 2026_08_29_000004_add_awaiting_submission_status.php:
     *   SELECT COUNT(*) FROM document_request WHERE status_id = 13;
     *   SELECT COUNT(*) FROM request_history  WHERE old_status_id = 13 OR new_status_id = 13;
     * Both must be 0 — nothing could legitimately have used status_id 13
     * before this migration creates the row, since RequestStatusEnum
     * never exposed it as a case until now.
     */
    private function checkNoExistingUsage(): array
    {
        $failures = [];

        $requestUsage = DB::table('document_request')
            ->where('status_id', self::WITHDRAWN_STATUS_ID)
            ->count();

        if ($requestUsage > 0) {
            $failures[] = "document_request has {$requestUsage} row(s) already at status_id = " . self::WITHDRAWN_STATUS_ID . ' — this id is NOT free. Pick a different id and update RequestStatusEnum accordingly before proceeding.';
        }

        $historyUsage = DB::table('request_history')
            ->where('old_status_id', self::WITHDRAWN_STATUS_ID)
            ->orWhere('new_status_id', self::WITHDRAWN_STATUS_ID)
            ->count();

        if ($historyUsage > 0) {
            $failures[] = "request_history has {$historyUsage} row(s) already referencing status_id = " . self::WITHDRAWN_STATUS_ID . ' — this id is NOT free.';
        }

        return $failures;
    }

    /**
     * request_status.status_id = 13 should either not exist yet (fresh
     * environment / never touched), or already exist with EXACTLY the
     * name this migration will (re-)assert via updateOrInsert(). Any
     * other name at that id means something else has already claimed
     * it — most likely by hand, outside of a migration, the same way the
     * original "Pending" landmine at id=6 happened.
     */
    private function checkStatusRowState(): array
    {
        if (!Schema::hasTable('request_status')) {
            return ["request_status table does not exist on this connection — nothing to check (fresh schema?)."];
        }

        $row = DB::table('request_status')->where('status_id', self::WITHDRAWN_STATUS_ID)->first();

        if ($row && $row->status_name !== self::WITHDRAWN_STATUS_NAME) {
            return ["request_status.status_id = " . self::WITHDRAWN_STATUS_ID . " already exists with status_name = '{$row->status_name}', not '" . self::WITHDRAWN_STATUS_NAME . "'. This id has already been claimed by something else — STOP."];
        }

        return [];
    }

    /**
     * Guards the reverse collision: 'Withdrawn' already seeded under a
     * DIFFERENT status_id than 13. Would mean either this command is
     * being run after a manual/ad-hoc seed, or a naming collision is
     * about to be introduced. Either way, worth a human's attention
     * before the migration runs.
     */
    private function checkNoDuplicateStatusName(): array
    {
        if (!Schema::hasTable('request_status')) {
            return [];
        }

        $duplicate = DB::table('request_status')
            ->where('status_name', self::WITHDRAWN_STATUS_NAME)
            ->where('status_id', '!=', self::WITHDRAWN_STATUS_ID)
            ->first();

        if ($duplicate) {
            return ["request_status already has a row named '" . self::WITHDRAWN_STATUS_NAME . "' at status_id = {$duplicate->status_id} (expected 13). Resolve this naming collision before proceeding."];
        }

        return [];
    }

    /**
     * Same "id must be free or already exactly ours" check as
     * checkStatusRowState(), applied to notification_types.trigger_event
     * = 'request_withdrawn' — protects the companion notification-type
     * migration (2026_09_05_000001_add_request_withdrawn_notification_type.php)
     * the same way.
     */
    private function checkNoNotificationTriggerCollision(): array
    {
        if (!Schema::hasTable('notification_types')) {
            return [];
        }

        $row = DB::table('notification_types')
            ->where('trigger_event', self::WITHDRAWN_TRIGGER_EVENT)
            ->first();

        if ($row && (int) $row->notification_type_id !== 25) {
            return ["notification_types.trigger_event = '" . self::WITHDRAWN_TRIGGER_EVENT . "' already exists under notification_type_id = {$row->notification_type_id} (expected 25). Resolve before proceeding."];
        }

        return [];
    }
}
