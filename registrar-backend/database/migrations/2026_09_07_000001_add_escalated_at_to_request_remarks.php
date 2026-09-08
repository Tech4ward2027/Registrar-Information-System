<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data Retention & Disposal Policy — Section 3.3 ("Deficiency Notice
 * compliance window (NEW) — 30 Days").
 *
 * Adds request_remarks.escalated_at, distinct from the Phase 4
 * dashboard-badge "staleness" concept (RequestRemark::STALE_AFTER_DAYS
 * = 14, computed at read time via RequestRemark::getIsStaleAttribute()
 * — a purely cosmetic UI warning tier, never persisted).
 *
 * WHY THIS ONE COULD NOT ALSO BE COMPUTED AT READ TIME: the 14-day
 * staleness badge only ever needs to answer "is this notice currently
 * past 14 days" at the moment someone views it — a stateless date
 * comparison is sufficient, and the implementation plan's Phase 0
 * explicitly chose "no new scheduled jobs" on that basis. The 30-day
 * mark is different: per the policy, crossing it is supposed to
 * trigger something (escalation for Registrar Admin review), not just
 * change how a badge looks the next time someone happens to open the
 * request. That requires a job that actually runs, actually notices
 * the threshold was crossed, and actually notifies admins exactly
 * once — none of which a purely computed attribute can do, since nothing
 * would ever detect the crossing if no one happened to view that
 * specific request that day. escalated_at is the durable record that
 * this already happened, so:
 *   (a) EscalateStaleDeficiencyNotices (see that command) never
 *       re-notifies admins for the same notice on a later run, and
 *   (b) staff can query/filter "already-escalated" notices directly,
 *       rather than recomputing a date comparison across every open
 *       notice on every request.
 *
 * Nullable — most notices never reach 30 days open (the median case is
 * cleared well before then); only escalated by
 * EscalateStaleDeficiencyNotices when issued_at is 30+ days in the past
 * AND the notice is still open AND escalated_at is still null.
 *
 * IDEMPOTENCY: guarded by Schema::hasColumn(), matching the convention
 * of every other additive column migration in this schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('request_remarks', function (Blueprint $table) {
            if (!Schema::hasColumn('request_remarks', 'escalated_at')) {
                $table->timestamp('escalated_at')->nullable()->after('issued_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('request_remarks', function (Blueprint $table) {
            if (Schema::hasColumn('request_remarks', 'escalated_at')) {
                $table->dropColumn('escalated_at');
            }
        });
    }
};
