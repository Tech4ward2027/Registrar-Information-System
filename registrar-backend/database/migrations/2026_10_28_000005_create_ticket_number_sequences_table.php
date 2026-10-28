<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Support Ticketing — Phase 1. Per-year counter behind ticket numbers.
 *
 * One row per year holding the last number issued. TicketNumberGenerator
 * increments it with a single atomic UPDATE, so numbers are unique and
 * gap-free under concurrency without MAX()+1 races or table-wide locks. A new
 * year is simply a new row, so the sequence restarts at 00001 each January.
 *
 * `year` is the primary key, not an auto-increment id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_number_sequences', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_number_sequences');
    }
};
