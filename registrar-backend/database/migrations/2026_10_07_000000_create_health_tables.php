<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * System Health — Phase 3.
 *
 * health_daily_metrics: one row per (day, source, metric, dimension). Counts
 *   only, no personal data. `dimension` is NOT NULL with '' meaning "none" on
 *   purpose: a UNIQUE index treats NULLs as distinct on MySQL/Postgres/SQLite,
 *   which would let duplicate rows through and break idempotent upserts.
 *
 * system_alerts: one row per incident. `alert_key` is UNIQUE, and that index
 *   (not application code) is what guarantees one incident = one alert, even
 *   under concurrent detector runs. Only portable column types are used.
 *   `context` holds user IDs at most — never names or emails.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('health_daily_metrics', function (Blueprint $table) {
            $table->bigIncrements('health_daily_metric_id');
            $table->date('metric_date');
            $table->string('source', 30);
            $table->string('metric', 50);
            $table->string('dimension', 100)->default('');
            $table->unsignedInteger('count')->default(0);
            $table->timestamp('computed_at')->nullable();

            $table->unique(['metric_date', 'source', 'metric', 'dimension'], 'health_metrics_unique_idx');
            $table->index(['source', 'metric', 'metric_date'], 'health_metrics_series_idx');
        });

        Schema::create('system_alerts', function (Blueprint $table) {
            $table->bigIncrements('system_alert_id');
            $table->string('alert_key', 191);
            $table->string('type', 30);
            $table->string('severity', 10);
            $table->string('source', 30);
            $table->string('metric', 50);
            $table->string('dimension', 100)->default('');
            $table->date('window_start');
            $table->date('window_end');
            $table->decimal('observed', 12, 4);
            $table->decimal('baseline_median', 12, 4)->nullable();
            $table->decimal('baseline_mad', 12, 4)->nullable();
            $table->decimal('score', 12, 4)->nullable();
            $table->string('status', 20)->default('open');
            $table->integer('acknowledged_by')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique('alert_key', 'system_alerts_key_unique');
            $table->index(['status', 'created_at'], 'system_alerts_status_created_idx');
            $table->index(['source', 'metric'], 'system_alerts_series_idx');

            $table->foreign('acknowledged_by', 'fk_system_alerts_ack_user')
                ->references('user_id')->on('users')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_alerts');
        Schema::dropIfExists('health_daily_metrics');
    }
};
