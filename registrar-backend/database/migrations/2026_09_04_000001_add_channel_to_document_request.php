<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Enums\RequestChannelEnum;

/**
 * FESPEC-0008 — Free Document/Certificate Request (Phase 1b).
 *
 * Adds document_request.channel: how this request entered the system.
 *
 * - 'self_service'      (default): the requestor filed it themselves via
 *   the Student/Alumni Request page — DocumentRequestController::store(),
 *   role:1,2 in routes/api.php. Every existing row backfills to this
 *   value, which is correct — self-service is the only channel that has
 *   ever existed until this feature.
 * - 'admin_filed_free': filed BY a Registrar Admin ON BEHALF OF the
 *   requestor via the Free Request page, per the Free Documents/
 *   Certificates Request Policy §3.2 ("free documents/certificates are
 *   not tied to a Cashier transaction... the Registrar Admin files the
 *   request on their behalf"). Never has an or_number.
 *
 * Plain string column with an app-level enum (RequestChannelEnum) as the
 * single source of truth for valid values — same "no DB enum column"
 * convention already used for request_status.status_name and
 * security_events.event_type (see job_run_logs migration docblock,
 * which cites the same reasoning: a future channel should never need a
 * migration to add).
 *
 * A single flat channel column, rather than a boolean per free-request
 * flag or a separate free_requests table, is deliberate: a document_
 * request row is already the correct unit of "one filing event", and
 * every downstream consumer (dashboard visibility, notifications,
 * claim_code/uuid generation, audit logging) already operates on
 * DocumentRequest — reusing that same row via DocumentRequestService
 * keeps the Free Request flow additive rather than a parallel pipeline
 * (see FreeRequestService, Phase 2).
 *
 * NOTE ON UNIQUENESS ENFORCEMENT: this migration deliberately does NOT
 * add a database-level unique/partial index tying (user_id, type,
 * channel) together. Production runs MySQL (config/database.php,
 * DB_CONNECTION=mysql), which does not support partial/filtered unique
 * indexes (`UNIQUE ... WHERE`) the way Postgres/SQLite do — and even a
 * full (non-partial) unique index isn't viable here, because the
 * document/certificate TYPE being requested lives on request_document /
 * request_certificate (child tables, one-to-many per document_request),
 * not on this table. The one-free-copy-per-graduate guarantee is instead
 * enforced by FreeRequestEligibilityService inside a DB transaction that
 * row-locks the requesting user (see Phase 2 docblock for the full
 * reasoning) — the same "transactional row lock over a partial DB
 * constraint" pattern DocumentRequestService::claimRequest() already
 * relies on for its own concurrency guarantees.
 *
 * Written the same idempotent, re-runnable way as the rest of this
 * migration set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_request', function (Blueprint $table) {
            if (!Schema::hasColumn('document_request', 'channel')) {
                $table->string('channel', 30)
                    ->default(RequestChannelEnum::SelfService->value)
                    ->after('or_number');
            }
        });

        if (!$this->hasIndex('document_request', 'dr_channel_user_idx')) {
            Schema::table('document_request', function (Blueprint $table) {
                // Supports FreeRequestEligibilityService's lookup: "every
                // admin_filed_free request this user has ever had,
                // regardless of item type" — narrowed further by joining
                // request_document/request_certificate for the specific
                // type being checked. Composite (channel, user_id) rather
                // than user_id alone, since self_service rows (the vast
                // majority of the table) never need to be scanned by this
                // query at all.
                $table->index(['channel', 'user_id'], 'dr_channel_user_idx');
            });
        }
    }

    public function down(): void
    {
        if ($this->hasIndex('document_request', 'dr_channel_user_idx')) {
            Schema::table('document_request', function (Blueprint $table) {
                $table->dropIndex('dr_channel_user_idx');
            });
        }

        Schema::table('document_request', function (Blueprint $table) {
            if (Schema::hasColumn('document_request', 'channel')) {
                $table->dropColumn('channel');
            }
        });
    }

    /**
     * Portable "does this index already exist" check — same approach as
     * 2026_08_29_000009_add_unique_index_to_document_request_or_number.php.
     */
    private function hasIndex(string $table, string $indexName): bool
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() === 'sqlite') {
            $indexes = $connection->select("PRAGMA index_list($table)");
            foreach ($indexes as $index) {
                if ($index->name === $indexName) {
                    return true;
                }
            }
            return false;
        }

        $database = $connection->getDatabaseName();

        $result = $connection->selectOne(
            'SELECT COUNT(*) AS count
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = ?
               AND TABLE_NAME = ?
               AND INDEX_NAME = ?',
            [$database, $table, $indexName]
        );

        return $result && $result->count > 0;
    }
};
