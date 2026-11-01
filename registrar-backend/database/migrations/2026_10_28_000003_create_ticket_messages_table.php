<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Support Ticketing — Phase 1. The conversation thread.
 *
 * Append-only: there is no updated_at, and the TicketMessage model refuses
 * updates and deletes. The first message of a ticket is the requester's
 * description.
 *
 * sender_user_id is a plain indexed integer with NO foreign key to users, on
 * purpose. AdminUserService hard-deletes staff accounts, and a conversation
 * must keep showing who said what after that. A foreign key would either block
 * the deletion or null the column and rewrite history. The id is kept as a
 * historical reference only.
 *
 * Deleting a ticket (only the retention purge ever does) cascades to its
 * messages.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ticket_id');
            $table->string('sender_type', 10);
            $table->integer('sender_user_id')->nullable();
            $table->text('body');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['ticket_id', 'id'], 'idx_ticket_messages_thread');
            $table->index('sender_user_id', 'idx_ticket_messages_sender');

            $table->foreign('ticket_id', 'fk_ticket_messages_ticket')
                ->references('id')->on('tickets')
                ->onDelete('cascade')->onUpdate('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_messages');
    }
};
