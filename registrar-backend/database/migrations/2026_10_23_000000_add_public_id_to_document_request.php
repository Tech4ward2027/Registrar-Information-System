<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * document_request.public_id — the identifier used in URLs and API calls.
 *
 * Why not reuse `uuid`: the uuid is the claim credential embedded in the QR
 * ticket. An identifier that appears in URLs, access logs and browser history
 * must not also be something that releases documents at the counter, so the
 * two are separate.
 *
 * Format: lowercase ULID (26 chars, 128 bits, URL-safe, time-ordered).
 *
 * The column is nullable + unique, matching claim_code's pattern and keeping
 * the migration portable (MySQL in production, sqlite in the test suite).
 * New rows always get a value from DocumentRequest::booted(); this migration
 * backfills every existing row. Unique allows many NULLs, so the backfill can
 * run after the column exists without blocking writes.
 *
 * The backfill uses the query builder (not the model) so archived and
 * soft-deleted rows are included and no model events or scopes interfere.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('document_request', 'public_id')) {
            Schema::table('document_request', function (Blueprint $table) {
                $table->char('public_id', 26)->nullable()->unique('document_request_public_id_unique')->after('uuid');
            });
        }

        DB::table('document_request')
            ->whereNull('public_id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('document_request')
                        ->where('request_id', $row->request_id)
                        ->whereNull('public_id')
                        ->update(['public_id' => strtolower((string) Str::ulid())]);
                }
            }, 'request_id');
    }

    public function down(): void
    {
        Schema::table('document_request', function (Blueprint $table) {
            $table->dropUnique('document_request_public_id_unique');
            $table->dropColumn('public_id');
        });
    }
};
