<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 — label suggestions. All columns nullable: existing rows and a
 * disabled feature behave exactly as before.
 *
 * Contents are catalogue data only (type ids/names, scores) — no personal
 * data. Rows follow the existing unmatched_cashier_items retention.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unmatched_cashier_items', function (Blueprint $table) {
            if (!Schema::hasColumn('unmatched_cashier_items', 'suggestions')) {
                $table->json('suggestions')->nullable();
            }
            if (!Schema::hasColumn('unmatched_cashier_items', 'suggestion_source')) {
                $table->string('suggestion_source', 10)->nullable(); // rules | llm
            }
            if (!Schema::hasColumn('unmatched_cashier_items', 'suggested_at')) {
                $table->timestamp('suggested_at')->nullable();
            }
            if (!Schema::hasColumn('unmatched_cashier_items', 'suggestion_accepted')) {
                $table->boolean('suggestion_accepted')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('unmatched_cashier_items', function (Blueprint $table) {
            foreach (['suggestions', 'suggestion_source', 'suggested_at', 'suggestion_accepted'] as $col) {
                if (Schema::hasColumn('unmatched_cashier_items', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
