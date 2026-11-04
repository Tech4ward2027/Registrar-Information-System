<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Support Ticketing — Phase 1. Ticket categories.
 *
 * `code` (CAT-01 ... CAT-05) is the stable business identifier used in
 * reports and exports; `id` is internal. `priority` is a plain string column
 * backed by TicketPriorityEnum (not a database ENUM), so it behaves the same
 * on MySQL and SQLite. Priority is defined here and copied onto each ticket
 * at creation: the requester never picks it.
 *
 * Categories are deactivated (`is_active` = false), never deleted: tickets
 * reference them, and old tickets must keep showing their original category.
 *
 * The rows themselves come from TicketCategorySeeder, not from this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique('ticket_categories_code_unique');
            $table->string('name', 100);
            $table->string('description', 255)->nullable();
            $table->string('priority', 10);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'sort_order'], 'idx_ticket_categories_active_sort');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_categories');
    }
};
