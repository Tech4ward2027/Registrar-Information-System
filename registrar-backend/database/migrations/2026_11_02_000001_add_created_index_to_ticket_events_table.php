<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Support Ticketing — Phase 8. The audited log export selects events by date
 * range. The existing (event_type, created_at) index cannot serve a range on
 * created_at alone, so without this the export would scan every event.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_events', function (Blueprint $table) {
            $table->index('created_at', 'idx_ticket_events_created');
        });
    }

    public function down(): void
    {
        Schema::table('ticket_events', function (Blueprint $table) {
            $table->dropIndex('idx_ticket_events_created');
        });
    }
};
