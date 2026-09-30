<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * One-time data repair for the "QR scan doesn't update the status, admin
 * still has to click Done" bug.
 *
 * DocumentRequestService::claimRequest() used to set only
 * document_request.status_id = Completed and left the request's own
 * request_document / request_certificate rows (and any
 * request_release_group rows) at Ready to Claim. The staff dashboard
 * draws each row's pill and Done button from those item statuses, so
 * every request claimed by a whole-request scan since item statuses
 * were introduced (migration 2026_08_29_000007) still shows "Ready to
 * Claim". The code fix (RequestStatusCascade) stops new rows from being
 * left behind; this migration repairs the rows already left behind.
 *
 * WHAT IT CHANGES
 *   An item or release group that is still Ready to Claim (status_id 2)
 *   while its parent request is already final is moved to the parent's
 *   final status:
 *       parent Completed (3)  ->  item/group Completed (3)
 *       parent Forfeited (4)  ->  item/group Forfeited (4)
 *   Forfeited is included because ShredExpiredRequests wrote only the
 *   parent row before it was taught to cascade, which left the same
 *   stale rows behind.
 *
 * WHAT IT DELIBERATELY DOES NOT CHANGE
 *   - Items under a Withdrawn or Closed - Unable to Process parent.
 *     withdraw() and closeUnableToProcess() do not update items either,
 *     but what an item's status should become there is a product
 *     decision that belongs to the per-document Withdraw/Close work, not
 *     to this repair. The migration only counts and logs them, so the
 *     size of that gap is visible.
 *   - Items that are not at Ready to Claim, and items under a request
 *     that is not final. Those are live workflow state.
 *   - The request rows themselves, so no status_updated_at is bumped
 *     and no request-level history or notification is produced.
 *
 * AUDIT TRAIL
 *   Each repaired item gets one request_history row (old status 2, new
 *   status = the parent's final status). changed_at is the parent's own
 *   status_updated_at, so it records when the claim or forfeiture really
 *   happened rather than when this migration ran. changed_by is NULL and
 *   processed_by_email is 'system-repair', which tells these rows apart
 *   from real staff actions and from the shredder's 'system'.
 *   business_minutes is left NULL on purpose: every timing report in
 *   AnalyticsService filters on it IS NOT NULL, so repaired rows cannot
 *   skew processing-time averages.
 *
 * SAFETY
 *   - Idempotent. It selects only rows still at status 2 under a final
 *     parent, so a second run finds nothing. It is safe to re-run after
 *     a partial failure.
 *   - Chunked (500 rows) with one short transaction per chunk, so no
 *     long-held locks and progress survives an interruption.
 *   - Inside each chunk the rows are re-selected FOR UPDATE and
 *     re-checked at status 2, so a concurrent staff action on the same
 *     item cannot be overwritten.
 *   - Uses the query builder (not Eloquent), so it does not depend on
 *     model scopes or events, and it also repairs archived requests.
 *   - Portable across MySQL (production) and SQLite (tests).
 *   - Take a database backup first. down() cannot restore the old
 *     values, because the pre-repair state is exactly the bug.
 */
return new class extends Migration
{
    private const CHUNK_SIZE = 500;

    private const READY_TO_CLAIM = 2;
    private const COMPLETED      = 3;
    private const FORFEITED      = 4;

    public function up(): void
    {
        $totals = [
            'request_document'      => 0,
            'request_certificate'   => 0,
            'request_release_group' => 0,
        ];

        foreach ([self::COMPLETED, self::FORFEITED] as $finalStatusId) {
            $totals['request_document'] += $this->repairItemTable(
                'request_document', 'request_document_id', 'request_document_id', $finalStatusId
            );
            $totals['request_certificate'] += $this->repairItemTable(
                'request_certificate', 'request_certificate_id', 'request_certificate_id', $finalStatusId
            );
            $totals['request_release_group'] += $this->repairReleaseGroups($finalStatusId);
        }

        Log::info('[repair_stale_item_statuses_after_claim] rows repaired', $totals + [
            'left_under_withdrawn_or_closed_requests' => $this->countLeftUnderWithdrawnOrClosed(),
        ]);
    }

    public function down(): void
    {
        // Intentionally a no-op. The state this migration removes is the
        // bug itself (items still Ready to Claim under a claimed request),
        // so restoring it would re-introduce inconsistent data. Restore
        // from the backup taken before running it if a rollback is ever
        // genuinely required.
    }

    /**
     * Repairs one item table (request_document or request_certificate)
     * for one final parent status, and writes the audit history rows.
     *
     * @return int number of item rows repaired
     */
    private function repairItemTable(string $table, string $primaryKey, string $historyColumn, int $finalStatusId): int
    {
        $repaired = 0;

        DB::table($table)
            ->join('document_request as dr', 'dr.request_id', '=', "{$table}.request_id")
            ->where("{$table}.status_id", self::READY_TO_CLAIM)
            ->where('dr.status_id', $finalStatusId)
            ->select(
                "{$table}.{$primaryKey} as id",
                "{$table}.request_id as request_id",
                'dr.status_updated_at as final_at'
            )
            ->chunkById(
                self::CHUNK_SIZE,
                function ($rows) use ($table, $primaryKey, $historyColumn, $finalStatusId, &$repaired) {
                    DB::transaction(function () use ($rows, $table, $primaryKey, $historyColumn, $finalStatusId, &$repaired) {
                        $ids = $rows->pluck('id')->all();

                        // Re-check under lock: only rows that are STILL
                        // at Ready to Claim are touched.
                        $liveIds = DB::table($table)
                            ->whereIn($primaryKey, $ids)
                            ->where('status_id', self::READY_TO_CLAIM)
                            ->lockForUpdate()
                            ->pluck($primaryKey)
                            ->all();

                        if (empty($liveIds)) {
                            return;
                        }

                        DB::table($table)
                            ->whereIn($primaryKey, $liveIds)
                            ->where('status_id', self::READY_TO_CLAIM)
                            ->update(['status_id' => $finalStatusId]);

                        $now     = now();
                        $history = [];
                        foreach ($rows as $row) {
                            if (!in_array($row->id, $liveIds, false)) {
                                continue;
                            }

                            $history[] = [
                                'request_id'         => $row->request_id,
                                $historyColumn       => $row->id,
                                'old_status_id'      => self::READY_TO_CLAIM,
                                'new_status_id'      => $finalStatusId,
                                'changed_at'         => $row->final_at ?? $now,
                                'changed_by'         => null,
                                'processed_by_email' => 'system-repair',
                                'minutes_processed'  => null,
                                'business_minutes'   => null,
                            ];
                        }

                        // Inserted in batches of 100 so one statement never
                        // carries thousands of bound parameters (SQLite's
                        // older builds cap a statement at 999).
                        foreach (array_chunk($history, 100) as $batch) {
                            DB::table('request_history')->insert($batch);
                        }

                        $repaired += count($liveIds);
                    });
                },
                "{$table}.{$primaryKey}",
                'id'
            );

        return $repaired;
    }

    /**
     * Repairs release groups still at Ready to Claim under a final
     * parent. Groups have no history table, so this is a plain update.
     *
     * @return int number of group rows repaired
     */
    private function repairReleaseGroups(int $finalStatusId): int
    {
        $repaired = 0;

        DB::table('request_release_group')
            ->join('document_request as dr', 'dr.request_id', '=', 'request_release_group.request_id')
            ->where('request_release_group.status_id', self::READY_TO_CLAIM)
            ->where('dr.status_id', $finalStatusId)
            ->select('request_release_group.request_release_group_id as id')
            ->chunkById(
                self::CHUNK_SIZE,
                function ($rows) use ($finalStatusId, &$repaired) {
                    DB::transaction(function () use ($rows, $finalStatusId, &$repaired) {
                        $liveIds = DB::table('request_release_group')
                            ->whereIn('request_release_group_id', $rows->pluck('id')->all())
                            ->where('status_id', self::READY_TO_CLAIM)
                            ->lockForUpdate()
                            ->pluck('request_release_group_id')
                            ->all();

                        if (empty($liveIds)) {
                            return;
                        }

                        DB::table('request_release_group')
                            ->whereIn('request_release_group_id', $liveIds)
                            ->where('status_id', self::READY_TO_CLAIM)
                            ->update(['status_id' => $finalStatusId]);

                        $repaired += count($liveIds);
                    });
                },
                'request_release_group.request_release_group_id',
                'id'
            );

        return $repaired;
    }

    /**
     * Informational only: items still at a non-final status under a
     * Withdrawn (13) or Closed - Unable to Process (14) request. Not
     * repaired here — see the class docblock.
     */
    private function countLeftUnderWithdrawnOrClosed(): int
    {
        // Statuses an item can never leave: Completed, Forfeited,
        // Cancelled, Withdrawn, Closed - Unable to Process.
        $finalIds = [3, 4, 5, 13, 14];
        $count    = 0;

        foreach (['request_document', 'request_certificate'] as $table) {
            $count += DB::table($table)
                ->join('document_request as dr', 'dr.request_id', '=', "{$table}.request_id")
                ->whereIn('dr.status_id', [13, 14])
                ->whereNotNull("{$table}.status_id")
                ->whereNotIn("{$table}.status_id", $finalIds)
                ->count();
        }

        return $count;
    }
};
