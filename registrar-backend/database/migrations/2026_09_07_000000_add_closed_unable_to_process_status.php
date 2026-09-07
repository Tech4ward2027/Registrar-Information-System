<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Data Retention & Disposal Policy — Section 3.4 ("Requests That Can
 * Never Be Resolved (e.g., Death of Requestor)").
 *
 * Adds the "Closed — Unable to Process" status (status_id = 14) and the
 * five columns on document_request that make it meaningful:
 * closure_reason, closure_detail, closure_proof_reference, closed_by,
 * closed_at. See RequestStatusEnum::ClosedUnableToProcess for the full
 * reasoning on why this is a distinct terminal status from both
 * Withdrawn (an ordinary administrative correction) and Forfeited (an
 * unclaimed-but-otherwise-successful request).
 *
 * WHY id 14: the highest existing status_id is 13 ("Withdrawn" — see
 * migration 2026_09_05_000000_add_withdrawn_status and
 * DatabaseSeeder::seedRequestStatus()), so 14 is the next free slot at
 * the time this migration was written. As with every prior status
 * addition in this file's lineage, CONFIRM THIS IS STILL FREE against
 * the actual production dump before deploying:
 *   SELECT COUNT(*) FROM document_request WHERE status_id = 14;
 *   SELECT COUNT(*) FROM request_history  WHERE old_status_id = 14 OR new_status_id = 14;
 *   Expected: 0 for both.
 * "Closed - Unable to Process" lowercases to "closed - unable to
 * process" — not an exact match on "pending" — so it does not collide
 * with the frontend's exact-match "pending" lookup
 * (staffDashboardUtils.js), the same landmine documented at length in
 * DatabaseSeeder::seedRequestStatus() and every status-adding migration
 * since.
 *
 * WHY FIVE NEW COLUMNS:
 *   - closure_reason (string, nullable): the fixed reason code, one of
 *     ClosureReasonEnum's cases. Nullable because every row that
 *     existed before this migration has no reason, and never will —
 *     this status can only be reached going forward, via
 *     DocumentRequestService::closeUnableToProcess().
 *   - closure_detail (text, nullable): free-text explanation, REQUIRED
 *     at the validation layer (CloseRequestUnableToProcessRequest) only
 *     when closure_reason = 'other' — same "other requires detail"
 *     convention as withdrawal_detail and request_remarks.detail.
 *   - closure_proof_reference (string, nullable): a REQUIRED (at the
 *     validation layer) reference describing the proof the Registrar
 *     Admin verified before closing the request — e.g. "Death
 *     certificate submitted by [next of kin name], verified
 *     2026-09-10" — per the policy's "Upon receipt of valid proof"
 *     requirement (§3.4). Deliberately a text REFERENCE/description,
 *     not a file upload: this codebase has no document-storage
 *     subsystem for sensitive identity/death-certificate uploads today,
 *     and building one is a materially larger undertaking (secure
 *     storage, access controls, its own retention rule) than this
 *     status change alone justifies. The actual physical/scanned proof
 *     is handled and retained by the Registrar's Office through its
 *     existing offline record-keeping process; this column exists so
 *     the system enforces that SOME verification was recorded before
 *     the closure action is allowed, and so anyone auditing the closure
 *     later can see what was checked.
 *   - closed_by (nullable FK -> users.user_id, restrictOnDelete): the
 *     staff member who performed the closure — same restrictOnDelete
 *     reasoning as request_remarks.issued_by/cleared_by/voided_by and
 *     document_request.archived_by/restored_by: a staff account being
 *     later deleted must not silently erase who closed the request.
 *   - closed_at (nullable timestamp): when the closure took effect.
 *
 * NOT modeled in this migration (deliberately out of scope): the
 * policy's data-handling items #2-5 under §3.4 (OR retention, physical
 * document disposal, unaffected academic records, personal-data
 * retention-then-disposal) describe RETENTION SCHEDULE and DISPOSAL
 * behavior for data that already exists elsewhere in this schema
 * (official receipts, request_document/request_certificate rows,
 * student_profile/academic records, notifications). None of that data
 * is deleted or altered by closeUnableToProcess() itself — the policy's
 * retention periods (10 years for OR, permanent for academic records,
 * etc.) govern eventual disposal on a much longer timescale, which is a
 * separate secure-disposal engine (disposal logs, per-category purge
 * jobs, next-of-kin identity verification workflow) not built as part
 * of this change. This migration and the service method built on it
 * cover ONLY the status transition and its immediate audit trail —
 * see the implementation notes on DocumentRequestService::
 * closeUnableToProcess() for the explicit list of what is and isn't
 * automated here.
 *
 * IDEMPOTENCY: written the same defensive, driver-aware way as
 * 2026_09_05_000000_add_withdrawn_status.php.
 */
return new class extends Migration
{
    private const STATUS_ID  = 14;
    private const FK_NAME    = 'fk_dr_closed_by';
    private const INDEX_NAME = 'dr_closed_by_idx';

    public function up(): void
    {
        DB::table('request_status')->updateOrInsert(
            ['status_id' => self::STATUS_ID],
            ['status_name' => 'Closed - Unable to Process']
        );

        Schema::table('document_request', function (Blueprint $table) {
            if (!Schema::hasColumn('document_request', 'closure_reason')) {
                $table->string('closure_reason', 50)->nullable()->after('superseded_by_request_id');
            }
            if (!Schema::hasColumn('document_request', 'closure_detail')) {
                $table->text('closure_detail')->nullable()->after('closure_reason');
            }
            if (!Schema::hasColumn('document_request', 'closure_proof_reference')) {
                $table->string('closure_proof_reference', 500)->nullable()->after('closure_detail');
            }
            if (!Schema::hasColumn('document_request', 'closed_by')) {
                $table->integer('closed_by')->nullable()->after('closure_proof_reference');
            }
            if (!Schema::hasColumn('document_request', 'closed_at')) {
                $table->timestamp('closed_at')->nullable()->after('closed_by');
            }
        });

        if (!$this->hasIndex('document_request', self::INDEX_NAME)) {
            Schema::table('document_request', function (Blueprint $table) {
                $table->index('closed_by', self::INDEX_NAME);
            });
        }

        if (!$this->hasForeignKey('document_request', self::FK_NAME)) {
            Schema::table('document_request', function (Blueprint $table) {
                $table->foreign('closed_by', self::FK_NAME)
                    ->references('user_id')->on('users')
                    ->restrictOnDelete();
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
                ['closed_at', 'closed_by', 'closure_proof_reference', 'closure_detail', 'closure_reason'],
                fn ($col) => Schema::hasColumn('document_request', $col)
            ));
        });

        $inUse = DB::table('document_request')->where('status_id', self::STATUS_ID)->exists()
            || DB::table('request_history')->where('old_status_id', self::STATUS_ID)->orWhere('new_status_id', self::STATUS_ID)->exists();

        if (!$inUse) {
            DB::table('request_status')->where('status_id', self::STATUS_ID)->delete();
        }
    }

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

    private function hasForeignKey(string $table, string $constraintName): bool
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() === 'sqlite') {
            $foreignKeys = $connection->select("PRAGMA foreign_key_list($table)");
            foreach ($foreignKeys as $fk) {
                if ($fk->from === 'closed_by' && $fk->table === 'users') {
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
