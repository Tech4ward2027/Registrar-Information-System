<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deficiency Notice & Withdrawn Status — Phase 1.
 *
 * Adds the "Withdrawn" status (status_id = 13) and the three columns on
 * document_request that make it meaningful: withdrawal_reason,
 * withdrawal_detail, and superseded_by_request_id. See
 * RequestStatusEnum::Withdrawn for the full reasoning on why this is a
 * distinct terminal status from Forfeited and the deprecated Cancelled.
 *
 * WHY id 13: the highest existing status_id is 12 ("Awaiting Submission"
 * — see DatabaseSeeder::seedRequestStatus()), so 13 is simply the next
 * free slot. Named "Withdrawn", which lowercases to "withdrawn" — NOT an
 * exact match on "pending" — so it does not collide with the frontend's
 * exact-match "pending" lookup (staffDashboardUtils.js), the same
 * landmine documented at length in DatabaseSeeder::seedRequestStatus()
 * and in every status-adding migration since (see
 * 2026_08_15_000000_add_pending_signature_status and
 * 2026_08_29_000004_add_awaiting_submission_status).
 *
 * IMPORTANT — run before deploying to a database with real data:
 *   php artisan requests:preflight-withdrawn
 * (see app/Console/Commands/PreflightCheckWithdrawnStatus.php) — a
 * runnable equivalent of the manual SQL checks documented in the two
 * migrations cited above:
 *   SELECT COUNT(*) FROM document_request WHERE status_id = 13;
 *   SELECT COUNT(*) FROM request_history  WHERE old_status_id = 13 OR new_status_id = 13;
 *   Expected: 0 for both. If either query returns non-zero, STOP and
 *   investigate before proceeding; something else has claimed this id.
 *
 * WHY THREE NEW COLUMNS INSTEAD OF ONE:
 *   - withdrawal_reason (string, nullable): the fixed reason code, one
 *     of WithdrawalReasonEnum's cases. Nullable because every row that
 *     existed before this migration has no reason (and never will —
 *     Withdrawn can only be reached going forward, via
 *     DocumentRequestService::withdraw()).
 *   - withdrawal_detail (text, nullable): free-text explanation, REQUIRED
 *     at the validation layer (WithdrawDocumentRequestRequest) only when
 *     withdrawal_reason = 'other'. See WithdrawalReasonEnum's docblock
 *     for why this column exists even though the implementation plan's
 *     Phase 1 spec only explicitly lists withdrawal_reason — without it
 *     there would be nowhere to store a staff member's typed explanation
 *     for an "Other" withdrawal, silently discarding exactly the detail
 *     that makes an audit trail useful.
 *   - superseded_by_request_id (nullable FK -> document_request.request_id):
 *     optional pointer at whichever request actually proceeds when this
 *     one is being closed out as a mistake/duplicate (see
 *     WithdrawalReasonEnum::DuplicateSubmission). Self-referencing,
 *     nullOnDelete so a later hard-delete of the superseding request
 *     (soft-deletes normally, but see DocumentRequestController::destroy())
 *     never leaves a dangling FK on this row.
 *
 * The paid OR (or_number / receipt_date) is deliberately left untouched
 * by this migration and by withdraw() itself — it stays permanently
 * attached to the withdrawn row for finance reconciliation, per the
 * implementation plan's Phase 1 exit criteria.
 *
 * IDEMPOTENCY: written the same defensive, driver-aware way as
 * 2026_07_13_000000_add_archiving_to_document_request.php — MySQL/
 * Postgres get an information_schema check, SQLite (used by the test
 * suite) gets a PRAGMA-based check.
 */
return new class extends Migration
{
    private const STATUS_ID  = 13;
    private const FK_NAME    = 'fk_dr_superseded_by_request_id';
    private const INDEX_NAME = 'dr_superseded_by_request_id_idx';

    public function up(): void
    {
        DB::table('request_status')->updateOrInsert(
            ['status_id' => self::STATUS_ID],
            ['status_name' => 'Withdrawn']
        );

        Schema::table('document_request', function (Blueprint $table) {
            if (!Schema::hasColumn('document_request', 'withdrawal_reason')) {
                $table->string('withdrawal_reason', 50)->nullable()->after('status_id');
            }
            if (!Schema::hasColumn('document_request', 'withdrawal_detail')) {
                $table->text('withdrawal_detail')->nullable()->after('withdrawal_reason');
            }
            if (!Schema::hasColumn('document_request', 'superseded_by_request_id')) {
                $table->integer('superseded_by_request_id')->nullable()->after('withdrawal_detail');
            }
        });

        if (!$this->hasIndex('document_request', self::INDEX_NAME)) {
            Schema::table('document_request', function (Blueprint $table) {
                $table->index('superseded_by_request_id', self::INDEX_NAME);
            });
        }

        if (!$this->hasForeignKey('document_request', self::FK_NAME)) {
            Schema::table('document_request', function (Blueprint $table) {
                // nullOnDelete rather than restricting: a superseding
                // request being deleted should not block deletion, and
                // should not leave this row's FK dangling — it should
                // simply forget the (now-gone) pointer.
                $table->foreign('superseded_by_request_id', self::FK_NAME)
                    ->references('request_id')->on('document_request')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('document_request', function (Blueprint $table) {
            if ($this->hasForeignKey('document_request', self::FK_NAME)) {
                $table->dropForeign(self::FK_NAME);
            }
            if ($this->hasIndex('document_request', self::INDEX_NAME)) {
                $table->dropIndex(self::INDEX_NAME);
            }
        });

        Schema::table('document_request', function (Blueprint $table) {
            $table->dropColumn(array_filter(
                ['superseded_by_request_id', 'withdrawal_detail', 'withdrawal_reason'],
                fn ($col) => Schema::hasColumn('document_request', $col)
            ));
        });

        // Only remove the status row if nothing references it — mirrors
        // the caution in every other status-adding migration in this
        // project (see 2026_08_29_000004_add_awaiting_submission_status).
        $inUse = DB::table('document_request')->where('status_id', self::STATUS_ID)->exists()
            || DB::table('request_history')->where('old_status_id', self::STATUS_ID)->orWhere('new_status_id', self::STATUS_ID)->exists();

        if (!$inUse) {
            DB::table('request_status')->where('status_id', self::STATUS_ID)->delete();
        }
    }

    /**
     * Portable "does this index already exist" check — same approach as
     * 2026_07_13_000000_add_archiving_to_document_request.php.
     */
    private function hasIndex(string $table, string $indexName): bool
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() === 'sqlite') {
            $indexes = $connection->select("PRAGMA index_list($table)");
            foreach ($indexes as $index) {
                if ($index->name === $indexName) {
                    return true;
                }
            }
            return false;
        }

        $database = DB::getDatabaseName();

        $result = DB::selectOne(
            'SELECT COUNT(*) AS count
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = ?
               AND TABLE_NAME = ?
               AND INDEX_NAME = ?',
            [$database, $table, $indexName]
        );

        return $result && $result->count > 0;
    }

    /**
     * Portable "does this foreign key already exist" check — same
     * approach as 2026_07_13_000000_add_archiving_to_document_request.php.
     */
    private function hasForeignKey(string $table, string $constraintName): bool
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() === 'sqlite') {
            $foreignKeys = $connection->select("PRAGMA foreign_key_list($table)");
            foreach ($foreignKeys as $fk) {
                if ($fk->from === 'superseded_by_request_id' && $fk->table === 'document_request') {
                    return true;
                }
            }
            return false;
        }

        $database = DB::getDatabaseName();

        $result = DB::selectOne(
            'SELECT COUNT(*) AS count
             FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = ?
               AND TABLE_NAME = ?
               AND CONSTRAINT_NAME = ?
               AND CONSTRAINT_TYPE = "FOREIGN KEY"',
            [$database, $table, $constraintName]
        );

        return $result && $result->count > 0;
    }
};
