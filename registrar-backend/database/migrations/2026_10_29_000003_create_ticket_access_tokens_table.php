<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Support Ticketing — Phase 4: magic-link tokens for public tickets.
 *
 * Only the SHA-256 hash is stored; the token itself (32+ random bytes) exists
 * only in the email. There is deliberately no expires_at: a token is valid
 * until the ticket is Closed plus a grace period, which is a property of the
 * TICKET (TicketAccessTokenService decides it), so closing a ticket needs no
 * extra writes here.
 *
 * Deleting a ticket (retention purge) cascades to its tokens.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ticket_id');
            $table->char('token_hash', 64)->unique();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('last_used_at')->nullable();

            $table->index('ticket_id', 'idx_ticket_access_tokens_ticket');

            $table->foreign('ticket_id', 'fk_ticket_access_tokens_ticket')
                ->references('id')->on('tickets')
                ->onDelete('cascade')->onUpdate('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_access_tokens');
    }
};
