<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Support Ticketing — Phase 4: the per-IP daily ticket limit counts tickets by
 * requester_ip over the last 24 hours; this index keeps that a short range
 * read instead of a table scan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->index(['requester_ip', 'created_at'], 'idx_tickets_ip_created');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex('idx_tickets_ip_created');
        });
    }
};
