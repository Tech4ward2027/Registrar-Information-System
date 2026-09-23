<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Bug fix — Staff Dashboard "Completed" visibility window.
 *
 * document_request had no column tracking WHEN status_id last changed.
 * StaffDashboard's default-visibility filter (isDefaultVisible() in
 * staffDashboardUtils.js) needs exactly that for its Completed rule
 * ("show for 24h after completion"), but had no real signal to read —
 * so it fell back to requested_at (the request's ORIGINAL FILING date).
 * Since real requests almost always take longer than 24h between filing
 * and being claimed, a request would frequently drop out of the default
 * "Active requests" view the instant it was marked Completed, with
 * nothing in the UI indicating the claim actually succeeded. That
 * presented to staff/students as "the QR scan / status update doesn't
 * work" even though the underlying claim was recorded correctly.
 *
 * This migration adds document_request.status_updated_at: a single,
 * always-current "when did status_id last change" timestamp. It is
 * auto-stamped by a model-level event hook (see DocumentRequest::booted())
 * whenever status_id is dirty on save — covering every current and
 * future code path that mutates status (manual staff updates, QR/
 * release-group claims, the hourly auto-forfeit cron,
 * RequestItemStatusService's aggregate recompute) with one change,
 * rather than patching each call site individually.
 *
 * Existing rows are backfilled from request_history — the most recent
 * whole-request transition (request_document_id AND
 * request_certificate_id both NULL, per that table's docblock) whose
 * new_status_id matches the request's CURRENT status_id — falling back
 * to requested_at when no matching history row exists (e.g. a request
 * that has never changed status since creation). Done in PHP via
 * chunkById() rather than a single correlated SQL UPDATE so the same
 * migration runs unchanged on both MySQL (production) and SQLite
 * (tests), matching this codebase's existing "portable, re-runnable
 * migration" convention.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_request', function (Blueprint $table) {
            if (!Schema::hasColumn('document_request', 'status_updated_at')) {
                $table->timestamp('status_updated_at')->nullable()->after('requested_at');
            }
        });

        $this->backfillStatusUpdatedAt();

        if (!$this->hasIndex('document_request', 'dr_status_updated_at_idx')) {
            Schema::table('document_request', function (Blueprint $table) {
                // Supports the Staff Dashboard's default-visibility query
                // pattern (status_id = Completed AND status_updated_at
                // within the last 24h) without a full table scan as the
                // table grows.
                $table->index('status_updated_at', 'dr_status_updated_at_idx');
            });
        }
    }

    public function down(): void
    {
        if ($this->hasIndex('document_request', 'dr_status_updated_at_idx')) {
            Schema::table('document_request', function (Blueprint $table) {
                $table->dropIndex('dr_status_updated_at_idx');
            });
        }

        Schema::table('document_request', function (Blueprint $table) {
            if (Schema::hasColumn('document_request', 'status_updated_at')) {
                $table->dropColumn('status_updated_at');
            }
        });
    }

    /**
     * One-time backfill for rows that existed before this column did.
     * Idempotent: only ever touches rows where status_updated_at is
     * still NULL, so re-running this migration (or a future manual
     * re-run) never clobbers a value the new booted() hook has since
     * written for real.
     */
    private function backfillStatusUpdatedAt(): void
    {
        DB::table('document_request')
            ->whereNull('status_updated_at')
            ->orderBy('request_id')
            ->chunkById(500, function ($requests) {
                foreach ($requests as $request) {
                    $latestMatchingHistory = DB::table('request_history')
                        ->where('request_id', $request->request_id)
                        ->where('new_status_id', $request->status_id)
                        ->whereNull('request_document_id')
                        ->whereNull('request_certificate_id')
                        ->orderByDesc('changed_at')
                        ->first();

                    $backfillValue = $latestMatchingHistory->changed_at
                        ?? $request->requested_at;

                    if ($backfillValue === null) {
                        continue;
                    }

                    DB::table('document_request')
                        ->where('request_id', $request->request_id)
                        ->update(['status_updated_at' => $backfillValue]);
                }
            }, 'request_id');
    }

    /**
     * Portable "does this index already exist" check — same approach as
     * 2026_09_04_000001_add_channel_to_document_request.php.
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

        $database = $connection->getDatabaseName();

        $result = $connection->selectOne(
            'SELECT COUNT(*) AS count
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = ?
               AND TABLE_NAME = ?
               AND INDEX_NAME = ?',
            [$database, $table, $indexName]
        );

        return $result && $result->count > 0;
    }
};
