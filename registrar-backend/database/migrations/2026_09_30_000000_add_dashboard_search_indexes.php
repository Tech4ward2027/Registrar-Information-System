<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supports the staff dashboard's server-side search
 * (DocumentRequest::scopeSearch(), GET /document-requests?search=).
 *
 * Already indexed, so NOT touched here:
 *   student_profile (last_name, first_name)          idx_student_profile_name_search
 *   alumni_profile  (last_name, first_name)          idx_alumni_profile_name_search
 *   student_academic_record.student_number           unique uq_student_number
 *   undergrad_requestor_profiles.student_number      undergrad_requestor_profiles_student_number_idx
 *   document_request.claim_code                      unique
 *   document_request.status_id / requested_at / status_updated_at
 *
 * Missing, added here:
 *   alumni_academic_record.student_number   (student-number prefix search)
 *   undergrad_requestor_profiles (last_name, first_name)   (name search)
 *
 * The name searches match word prefixes, so on the profile tables they
 * may scan those (small) tables rather than seek. The indexes above
 * still serve the whole-column prefix branch. Confirm with EXPLAIN on
 * production-sized data before adding anything further.
 *
 * Idempotent: each index is created only if absent, so a re-run (or an
 * index someone already added by hand under the same name) is safe.
 */
return new class extends Migration
{
    private const INDEXES = [
        ['alumni_academic_record',         'idx_alumni_academic_student_number', ['student_number']],
        ['undergrad_requestor_profiles',   'urp_name_search_idx',                ['last_name', 'first_name']],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as [$table, $name, $columns]) {
            if (!Schema::hasTable($table) || $this->hasIndex($table, $name)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($columns, $name) {
                $blueprint->index($columns, $name);
            });
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as [$table, $name]) {
            if (Schema::hasTable($table) && $this->hasIndex($table, $name)) {
                Schema::table($table, function (Blueprint $blueprint) use ($name) {
                    $blueprint->dropIndex($name);
                });
            }
        }
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() === 'sqlite') {
            foreach ($connection->select("PRAGMA index_list($table)") as $index) {
                if ($index->name === $indexName) {
                    return true;
                }
            }

            return false;
        }

        $row = $connection->selectOne(
            'SELECT COUNT(*) AS count
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = ?
               AND TABLE_NAME = ?
               AND INDEX_NAME = ?',
            [$connection->getDatabaseName(), $table, $indexName]
        );

        return (int) ($row->count ?? 0) > 0;
    }
};
