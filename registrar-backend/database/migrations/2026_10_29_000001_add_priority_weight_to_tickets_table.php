<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Support Ticketing — Phase 3: a sortable form of `priority`.
 *
 * `priority` is a string ('high' / 'medium' / 'low'), which cannot be ordered
 * meaningfully (alphabetically it sorts high, low, medium). The staff queue
 * must list the most urgent tickets first AND page with a cursor, and cursor
 * pagination needs plain columns to order by, not a CASE expression. So the
 * weight (3 / 2 / 1, from TicketPriorityEnum::weight()) is stored alongside.
 *
 * It is derived data: Ticket::booted() sets it whenever `priority` is set, so
 * no caller writes it by hand and the two can never disagree. The mapping is
 * written out literally here (not read from the enum) so this migration keeps
 * meaning the same thing if the enum is ever changed.
 *
 * Works on MySQL and SQLite. Adding an index inside the same Schema::table
 * call as the column is avoided on purpose: SQLite rebuilds the table for
 * some alterations, and keeping column and index in separate statements is
 * the form that behaves identically on both.
 */
return new class extends Migration
{
    private const WEIGHTS = ['high' => 3, 'medium' => 2, 'low' => 1];

    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->unsignedTinyInteger('priority_weight')->default(0)->after('priority');
        });

        foreach (self::WEIGHTS as $priority => $weight) {
            DB::table('tickets')->where('priority', $priority)->update(['priority_weight' => $weight]);
        }

        Schema::table('tickets', function (Blueprint $table) {
            // Queue order: urgent first, then oldest first, within a status.
            $table->index(['status', 'priority_weight', 'created_at'], 'idx_tickets_queue_weight');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex('idx_tickets_queue_weight');
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn('priority_weight');
        });
    }
};
