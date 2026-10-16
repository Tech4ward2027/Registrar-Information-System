<?php

use App\Models\FailureReasonCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cashier Reconciliation / System Health — Phase 2b.
 *
 * Descriptive catalog for cashier-failure diagnosis codes. See
 * App\Models\FailureReasonCode for what is (label, severity, on/off) and
 * is not (the matching rules) data-driven.
 *
 * The starter rows are inserted here — not only in a seeder — because
 * seeders are not run on deploy; without this a production database would
 * have the table but an empty catalog. Insert-if-absent, so re-running or
 * running the seeder afterwards never reverts an operator's edits.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('failure_reason_codes')) {
            Schema::create('failure_reason_codes', function (Blueprint $table) {
                $table->id('failure_reason_code_id');
                $table->string('code', 50)->unique();
                $table->string('category', 20);
                $table->string('description', 255);
                $table->string('severity', 10)->default('info');
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        $now = now();

        foreach (FailureReasonCode::STARTER_CODES as $row) {
            DB::table('failure_reason_codes')->insertOrIgnore($row + [
                'is_active'  => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('failure_reason_codes');
    }
};
