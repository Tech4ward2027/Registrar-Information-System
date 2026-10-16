<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Failed-verification queries always filter audit_logs by `action` and a
 * date range (JSON-path filters are only ever applied on top of that). The
 * table had no index on either column, so those reads would scan it.
 *
 * Idempotent: skipped when the index already exists.
 */
return new class extends Migration
{
    private const INDEX = 'idx_audit_logs_action_created';

    public function up(): void
    {
        if (Schema::hasIndex('audit_logs', self::INDEX)) {
            return;
        }

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->index(['action', 'created_at'], self::INDEX);
        });
    }

    public function down(): void
    {
        if (!Schema::hasIndex('audit_logs', self::INDEX)) {
            return;
        }

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(self::INDEX);
        });
    }
};
