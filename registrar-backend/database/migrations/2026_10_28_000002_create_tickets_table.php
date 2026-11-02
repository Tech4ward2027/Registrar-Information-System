<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Support Ticketing — Phase 1. The tickets table.
 *
 * Conventions followed from the rest of the schema:
 *   - users.user_id is a signed INT, so every user reference here is
 *     integer(), not unsignedBigInteger(), or the foreign key is rejected.
 *   - public_id is a lowercase ULID (char 26) and is the only identifier that
 *     ever appears in a URL. The integer id stays internal.
 *   - status / priority are plain strings backed by enums, not DB ENUMs.
 *
 * Shape decisions:
 *   - There is no `description` column. The requester's description is the
 *     first row of ticket_messages, so the thread is the single source of
 *     truth and the text is never stored twice.
 *   - `priority` is copied from the category at creation (see
 *     ticket_categories) so later reclassification cannot rewrite the SLA of
 *     tickets already in flight.
 *   - Authenticated requesters are identified by requester_user_id. PUBLIC
 *     requesters have requester_user_id NULL and carry a verified
 *     requester_name / requester_email, plus the IP they submitted from for
 *     rate limiting. Email is stored lowercased so per-email limits cannot be
 *     dodged with letter case. Both are anonymized at the retention mark
 *     (anonymized_at).
 *   - Claiming is one atomic UPDATE on claimed_by_user_id. It is a temporary
 *     marker, not a permanent assignment. claim_expires_at is stored so the
 *     "release stale claims" sweep is an indexed query rather than a
 *     calendar calculation per row.
 *   - requester_returns counts Resolved -> In Progress moves (limit in
 *     config/tickets.php). auto_close_at (= resolved_at + auto-close days) is
 *     stored so the auto-close job is an indexed range scan.
 *   - Users can be deleted by AdminUserService, so the user references on this
 *     table are ON DELETE SET NULL: a ticket must outlive the staff account
 *     that touched it. (ticket_events and ticket_messages deliberately carry
 *     NO foreign key to users for the same reason, see those migrations.)
 *
 * SLA due/pause columns are added by the SLA phase, not here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique('tickets_public_id_unique');
            $table->string('ticket_number', 20)->unique('tickets_ticket_number_unique');

            $table->unsignedBigInteger('category_id');
            $table->string('priority', 10);
            $table->string('status', 20)->default('open');
            $table->string('subject', 150);

            // Requester
            $table->integer('requester_user_id')->nullable();
            $table->string('requester_name', 150)->nullable();
            $table->string('requester_email', 254)->nullable();
            $table->string('requester_ip', 45)->nullable();

            // Claim (temporary marker; no permanent assignment)
            $table->integer('claimed_by_user_id')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('claim_expires_at')->nullable();

            // Activity
            $table->timestamp('first_responded_at')->nullable();
            $table->timestamp('last_activity_at')->useCurrent();

            // Resolution and closure
            $table->unsignedTinyInteger('requester_returns')->default(0);
            $table->text('resolution_note')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->integer('resolved_by_user_id')->nullable();
            $table->timestamp('auto_close_at')->nullable();
            $table->timestamp('reminder_sent_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('close_reason', 20)->nullable();

            // Retention
            $table->timestamp('anonymized_at')->nullable();

            $table->timestamps();

            // Staff queue: filter by status, order by priority then age.
            $table->index(['status', 'priority', 'created_at'], 'idx_tickets_queue');
            $table->index(['status', 'last_activity_at'], 'idx_tickets_status_activity');
            // "My tickets" and the per-requester open-ticket checks.
            $table->index(['requester_user_id', 'status'], 'idx_tickets_requester');
            $table->index(['requester_email', 'status'], 'idx_tickets_email_status');
            // "Claimed by me" and stale-claim sweep.
            $table->index(['claimed_by_user_id', 'status'], 'idx_tickets_claimed');
            $table->index(['status', 'claim_expires_at'], 'idx_tickets_claim_expiry');
            // Auto-close and reminder sweeps.
            $table->index(['status', 'auto_close_at'], 'idx_tickets_auto_close');
            // Retention purge and date-range reporting.
            $table->index('created_at', 'idx_tickets_created');
            $table->index('category_id', 'idx_tickets_category');

            $table->foreign('category_id', 'fk_tickets_category')
                ->references('id')->on('ticket_categories')
                ->onDelete('restrict')->onUpdate('cascade');
            $table->foreign('requester_user_id', 'fk_tickets_requester')
                ->references('user_id')->on('users')
                ->onDelete('set null')->onUpdate('cascade');
            $table->foreign('claimed_by_user_id', 'fk_tickets_claimed_by')
                ->references('user_id')->on('users')
                ->onDelete('set null')->onUpdate('cascade');
            $table->foreign('resolved_by_user_id', 'fk_tickets_resolved_by')
                ->references('user_id')->on('users')
                ->onDelete('set null')->onUpdate('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
