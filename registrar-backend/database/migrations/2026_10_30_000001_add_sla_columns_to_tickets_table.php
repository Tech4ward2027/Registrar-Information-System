<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Support Ticketing — Phase 5. SLA due dates, the pause marker and the
 * at-risk / breach flags.
 *
 *   first_response_due_at   when the first staff reply is due. Set at creation.
 *   resolution_due_at       when the ticket should be Resolved. Set at creation,
 *                           pushed back by the working time spent in Pending
 *                           Client, and restarted when a requester sends a
 *                           Resolved ticket back.
 *   sla_paused_at           set while the ticket is Pending Client (the clock is
 *                           waiting on the requester); null otherwise.
 *   *_at_risk_at /          set ONCE by the sweep (tickets:sla-sweep), which is
 *   *_breached_at           what makes it safe to run repeatedly: a flagged
 *                           target is never flagged, logged or notified twice.
 *
 * Due dates are absolute instants computed with the business calendar when the
 * ticket is created, so deciding "is it overdue" later is a plain comparison.
 * Only the at-risk test needs the calendar again.
 *
 * Portable across MySQL and SQLite (plain nullable timestamps, plain indexes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->timestamp('first_response_due_at')->nullable()->after('last_activity_at');
            $table->timestamp('resolution_due_at')->nullable()->after('first_response_due_at');
            $table->timestamp('sla_paused_at')->nullable()->after('resolution_due_at');

            $table->timestamp('first_response_at_risk_at')->nullable()->after('sla_paused_at');
            $table->timestamp('first_response_breached_at')->nullable()->after('first_response_at_risk_at');
            $table->timestamp('resolution_at_risk_at')->nullable()->after('first_response_breached_at');
            $table->timestamp('resolution_breached_at')->nullable()->after('resolution_at_risk_at');

            // The sweep and the staff "overdue" views scan active tickets by due date.
            $table->index(['status', 'first_response_due_at'], 'idx_tickets_first_response_due');
            $table->index(['status', 'resolution_due_at'], 'idx_tickets_resolution_due');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex('idx_tickets_first_response_due');
            $table->dropIndex('idx_tickets_resolution_due');

            $table->dropColumn([
                'first_response_due_at', 'resolution_due_at', 'sla_paused_at',
                'first_response_at_risk_at', 'first_response_breached_at',
                'resolution_at_risk_at', 'resolution_breached_at',
            ]);
        });
    }
};
