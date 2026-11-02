<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Support Ticketing — Phase 4: a public submission waiting for its email to be
 * confirmed. No ticket exists until it is.
 *
 * Holds what the person typed so they need not retype it after clicking the
 * emailed link. Only the SHA-256 of the confirmation token is stored. Once
 * confirmed, the content columns are wiped (the text now lives on the ticket)
 * and only the bookkeeping remains until the prune command deletes the row.
 * Unconfirmed rows are deleted after the same period, so a stranger's typed
 * message and email address do not linger because they never clicked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_public_submissions', function (Blueprint $table) {
            $table->id();
            $table->char('token_hash', 64)->unique();
            $table->string('name', 150)->nullable();
            $table->string('email', 254);
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('subject', 255)->nullable();
            $table->text('description')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->unsignedBigInteger('ticket_id')->nullable();

            $table->index('email', 'idx_ticket_pubsub_email');
            $table->index('created_at', 'idx_ticket_pubsub_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_public_submissions');
    }
};
