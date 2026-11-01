<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Support Ticketing — Phase 1. The ticket activity / audit timeline.
 *
 * Append-only and tamper-evident. Each ticket has its own hash chain:
 *   seq        1, 2, 3, ... per ticket, no gaps.
 *   prev_hash  the previous event's hash ('0' for seq 1).
 *   hash       sha256 over prev_hash and this row's own fields
 *              (TicketEvent::computeHash is the single implementation).
 *
 * Why per ticket and not one global chain like audit_logs: a global chain
 * makes every ticket write queue behind one row lock, and a retention purge
 * would break the chain for everything after the purged rows. A per-ticket
 * chain locks only the ticket already being written, and purging a ticket
 * removes its whole chain at once. UNIQUE (ticket_id, seq) is the backstop: two
 * writers racing for the same position cannot both succeed.
 *
 * actor_user_id has NO foreign key to users, on purpose: hashed rows must never
 * change, and a foreign key's ON DELETE SET NULL would rewrite them when a
 * staff account is deleted. See the ticket_messages migration.
 *
 * created_at is written truncated to whole seconds so the value hashed at
 * insert time equals the value read back on both MySQL and SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ticket_id');
            $table->unsignedInteger('seq');
            $table->string('event_type', 40);
            $table->string('actor_type', 10);
            $table->integer('actor_user_id')->nullable();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->char('prev_hash', 64);
            $table->char('hash', 64);

            $table->unique(['ticket_id', 'seq'], 'ticket_events_ticket_seq_unique');
            $table->index(['event_type', 'created_at'], 'idx_ticket_events_type_created');
            $table->index('actor_user_id', 'idx_ticket_events_actor');

            $table->foreign('ticket_id', 'fk_ticket_events_ticket')
                ->references('id')->on('tickets')
                ->onDelete('cascade')->onUpdate('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_events');
    }
};
