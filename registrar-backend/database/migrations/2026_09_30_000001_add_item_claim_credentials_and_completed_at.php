<?php

use App\Support\ClaimCredential;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Per-item claiming (Phase 3): every document and certificate row becomes
 * claimable on its own, so each gets:
 *
 *   uuid          char(36)   the QR payload for this one item
 *   claim_code    char(6)    typeable fallback, unique across ALL ticket tables
 *   completed_at  timestamp  when THIS item was released. Feeds the logbook,
 *                            daily reports and SLA metrics per item, without
 *                            waiting for the parent request to finish.
 *
 * Steps (expand phase — nothing is dropped, old code keeps working):
 *   1. add the columns (nullable)
 *   2. backfill uuid/claim_code for existing rows, in chunks
 *   3. backfill completed_at for items already Completed, from the item's
 *      own history row, else the parent's status_updated_at, else its
 *      requested_at
 *   4. add the unique / lookup indexes
 *
 * Idempotent: every step checks whether it is already done, so a run that
 * stopped half-way can simply be re-run. Columns stay nullable in the
 * database; the ClaimableItem model trait guarantees new rows always get
 * credentials. A later "contract" migration can make them NOT NULL once
 * this has run everywhere.
 *
 * Existing release-group tickets are NOT touched: tickets already printed
 * or sent to students keep resolving until request_release_group is
 * retired in a later phase.
 *
 * Back up first. The backfill rewrites credentials on existing rows and
 * down() drops the columns (and with them any per-item completion time).
 */
return new class extends Migration
{
    private const CHUNK = 500;

    /** table => primary key */
    private const ITEM_TABLES = [
        'request_document'    => 'request_document_id',
        'request_certificate' => 'request_certificate_id',
    ];

    public function up(): void
    {
        foreach (self::ITEM_TABLES as $table => $pk) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                if (!Schema::hasColumn($table, 'uuid')) {
                    $blueprint->char('uuid', 36)->nullable();
                }
                if (!Schema::hasColumn($table, 'claim_code')) {
                    $blueprint->string('claim_code', 6)->nullable();
                }
                if (!Schema::hasColumn($table, 'completed_at')) {
                    $blueprint->timestamp('completed_at')->nullable();
                }
            });
        }

        foreach (self::ITEM_TABLES as $table => $pk) {
            $this->backfillCredentials($table, $pk);
            $this->backfillCompletedAt($table, $pk);
        }

        foreach (self::ITEM_TABLES as $table => $pk) {
            $this->addIndex($table, "{$table}_uuid_unique", ['uuid'], unique: true);
            $this->addIndex($table, "{$table}_claim_code_unique", ['claim_code'], unique: true);
            $this->addIndex($table, "{$table}_completed_at_idx", ['completed_at']);
        }
    }

    public function down(): void
    {
        foreach (self::ITEM_TABLES as $table => $pk) {
            foreach (["{$table}_uuid_unique", "{$table}_claim_code_unique", "{$table}_completed_at_idx"] as $index) {
                if ($this->hasIndex($table, $index)) {
                    Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($index));
                }
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                foreach (['uuid', 'claim_code', 'completed_at'] as $column) {
                    if (Schema::hasColumn($table, $column)) {
                        $blueprint->dropColumn($column);
                    }
                }
            });
        }
    }

    // ── Backfill ─────────────────────────────────────────────────────────

    private function backfillCredentials(string $table, string $pk): void
    {
        $fixed = 0;

        // Always re-query "rows still missing a credential" instead of
        // paging by offset: every processed row drops out of the result,
        // and a re-run after a failure just continues where it stopped.
        while (true) {
            $rows = DB::table($table)
                ->where(fn ($q) => $q->whereNull('uuid')->orWhereNull('claim_code'))
                ->orderBy($pk)
                ->limit(self::CHUNK)
                ->get([$pk, 'uuid', 'claim_code']);

            if ($rows->isEmpty()) {
                break;
            }

            DB::transaction(function () use ($rows, $table, $pk) {
                foreach ($rows as $row) {
                    DB::table($table)->where($pk, $row->{$pk})->update([
                        'uuid'       => $row->uuid ?: ClaimCredential::uuid(),
                        'claim_code' => $row->claim_code ?: ClaimCredential::code(),
                    ]);
                }
            });

            $fixed += $rows->count();
        }

        Log::info("[item-claim migration] {$table}: credentials backfilled", ['rows' => $fixed]);
    }

    private function backfillCompletedAt(string $table, string $pk): void
    {
        $completed = 3; // RequestStatusEnum::Completed
        $historyFk = $table === 'request_document' ? 'request_document_id' : 'request_certificate_id';

        $bounds = DB::table($table)
            ->where('status_id', $completed)
            ->whereNull('completed_at')
            ->selectRaw("MIN({$pk}) AS lo, MAX({$pk}) AS hi")
            ->first();

        if (!$bounds || $bounds->lo === null) {
            return;
        }

        $fixed = 0;

        for ($lo = (int) $bounds->lo; $lo <= (int) $bounds->hi; $lo += self::CHUNK) {
            $hi = $lo + self::CHUNK - 1;

            $fixed += DB::update(
                "UPDATE {$table} SET completed_at = COALESCE(
                    (SELECT MAX(h.changed_at) FROM request_history h
                      WHERE h.{$historyFk} = {$table}.{$pk} AND h.new_status_id = ?),
                    (SELECT dr.status_updated_at FROM document_request dr WHERE dr.request_id = {$table}.request_id),
                    (SELECT dr.requested_at FROM document_request dr WHERE dr.request_id = {$table}.request_id)
                 )
                 WHERE status_id = ? AND completed_at IS NULL AND {$pk} BETWEEN ? AND ?",
                [$completed, $completed, $lo, $hi]
            );
        }

        Log::info("[item-claim migration] {$table}: completed_at backfilled", ['rows' => $fixed]);
    }

    // ── Index helpers (MySQL + SQLite, same approach as the other index migrations) ──

    private function addIndex(string $table, string $name, array $columns, bool $unique = false): void
    {
        if ($this->hasIndex($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $name, $unique) {
            $unique ? $blueprint->unique($columns, $name) : $blueprint->index($columns, $name);
        });
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() === 'sqlite') {
            foreach ($connection->select("PRAGMA index_list($table)") as $index) {
                if ($index->name === $indexName) {
                    return true;
                }
            }

            return false;
        }

        $row = $connection->selectOne(
            'SELECT COUNT(*) AS count
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$connection->getDatabaseName(), $table, $indexName]
        );

        return (int) ($row->count ?? 0) > 0;
    }
};
