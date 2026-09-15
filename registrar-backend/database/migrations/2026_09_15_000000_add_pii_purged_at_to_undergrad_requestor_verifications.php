<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Undergrad Requestor Registration — Phase 4 (D9).
 *
 * Adds undergrad_requestor_verifications.pii_purged_at: the timestamp at
 * which the 90-day rejected-PII purge (undergrad-requestors:purge-rejected-pii)
 * removed this record's self-declared personal data.
 *
 * WHY A COLUMN RATHER THAN INFERRING IT
 *
 * The purge deletes the undergrad_requestor_profiles row outright and
 * pseudonymizes the users row's email, so "has this been purged?" could
 * technically be inferred from the profile's absence. That inference is
 * wrong in two directions and would make the job neither idempotent nor
 * honest:
 *
 *   - A profile row can be absent for reasons other than the purge (a
 *     future manual support action, a partial failure), which would make
 *     an inferred flag claim a retention action that never happened.
 *   - Without an explicit marker, the sweep has no cheap, indexed way to
 *     skip already-processed rows, so every run would re-scan and
 *     re-attempt every historical rejection forever.
 *
 * Recording the purge explicitly also means the verification record
 * itself can truthfully tell an auditor "a rejection happened on
 * <reviewed_at>, and its supporting personal data was disposed of on
 * <pii_purged_at>" — the disposal half of the Data Privacy Act story,
 * not just the decision half. audit_logs remains the permanent,
 * tamper-evident record of both events and is never touched by the
 * purge.
 *
 * Nullable with no default: NULL means "not purged," which is the
 * correct value for every existing row and for every record that has not
 * yet aged past config('undergrad_requestor.rejected_retention_days').
 *
 * Indexed alongside status because the sweep's only query is
 * "status = Rejected AND pii_purged_at IS NULL AND reviewed_at < cutoff"
 * — a composite (status, pii_purged_at) index keeps that a narrow index
 * scan rather than a full table scan as rejected volume accumulates.
 *
 * IDEMPOTENT: guarded by Schema::hasTable()/hasColumn(), matching every
 * other migration in this feature's set.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('undergrad_requestor_verifications')) {
            return;
        }

        if (Schema::hasColumn('undergrad_requestor_verifications', 'pii_purged_at')) {
            return;
        }

        Schema::table('undergrad_requestor_verifications', function (Blueprint $table) {
            $table->timestamp('pii_purged_at')
                ->nullable()
                ->after('rejection_reason');

            $table->index(
                ['status', 'pii_purged_at'],
                'undergrad_requestor_verifications_status_purged_idx'
            );
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('undergrad_requestor_verifications')) {
            return;
        }

        if (!Schema::hasColumn('undergrad_requestor_verifications', 'pii_purged_at')) {
            return;
        }

        Schema::table('undergrad_requestor_verifications', function (Blueprint $table) {
            // Drop the index before the column it covers — MySQL will
            // otherwise refuse, and SQLite silently leaves an orphan.
            $table->dropIndex('undergrad_requestor_verifications_status_purged_idx');
            $table->dropColumn('pii_purged_at');
        });
    }
};
