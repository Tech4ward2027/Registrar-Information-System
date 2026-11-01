<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Support Ticketing — Phase 7. The daily retention command looks for tickets
 * that are not yet anonymized and were closed more than 3 years ago. Without
 * this index that is a scan of the whole tickets table every day. Once a ticket
 * is anonymized it drops out of the "anonymized_at IS NULL" range, so the
 * index keeps the query small as history grows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->index(['anonymized_at', 'closed_at'], 'idx_tickets_retention');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex('idx_tickets_retention');
        });
    }
};
