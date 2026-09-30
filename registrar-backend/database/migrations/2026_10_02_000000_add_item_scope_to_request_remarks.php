<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 - per-item Deficiency Notices.
 *
 * A request_remarks row is either:
 *   - request-level : request_document_id IS NULL AND request_certificate_id IS NULL
 *                     (every notice that exists today; behaviour unchanged), or
 *   - item-level    : exactly one of the two columns is set.
 *
 * "One open notice per scope" (one per item, plus one request-level) is
 * enforced by DeficiencyNoticeService::issue() under a row lock, for the same
 * reason the original migration gives: MySQL has no partial unique index.
 *
 * Existing rows keep both columns NULL, so nothing needs backfilling.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('request_remarks') || Schema::hasColumn('request_remarks', 'request_document_id')) {
            return;
        }

        Schema::table('request_remarks', function (Blueprint $table) {
            $table->integer('request_document_id')->nullable()->after('request_id');
            $table->integer('request_certificate_id')->nullable()->after('request_document_id');

            $table->index(['request_document_id', 'status'], 'request_remarks_document_status_idx');
            $table->index(['request_certificate_id', 'status'], 'request_remarks_certificate_status_idx');

            $table->foreign('request_document_id', 'request_remarks_document_fk')
                ->references('request_document_id')->on('request_document')
                ->cascadeOnDelete();
            $table->foreign('request_certificate_id', 'request_remarks_certificate_fk')
                ->references('request_certificate_id')->on('request_certificate')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('request_remarks', 'request_document_id')) {
            return;
        }

        $isSqlite = Schema::getConnection()->getDriverName() === 'sqlite';

        Schema::table('request_remarks', function (Blueprint $table) use ($isSqlite) {
            // SQLite (test suite) cannot drop a foreign key on an existing table.
            if (!$isSqlite) {
                $table->dropForeign('request_remarks_document_fk');
                $table->dropForeign('request_remarks_certificate_fk');
            }
            $table->dropIndex('request_remarks_document_status_idx');
            $table->dropIndex('request_remarks_certificate_status_idx');
            $table->dropColumn(['request_document_id', 'request_certificate_id']);
        });
    }
};
