<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FESPEC-0008 — Free Document/Certificate Request (Phase 1a).
 *
 * Adds two columns to document_type and certificate_type:
 *
 * - is_free_eligible (boolean, default false): whether this type may ever
 *   be filed through the Free Request page at all. Kept as an explicit
 *   opt-in flag rather than inferring eligibility from free_issuance_limit
 *   being non-null, so a type can be temporarily disabled for free
 *   issuance (e.g. a policy suspension) without losing its configured
 *   limit — see FreeRequestEligibilityService.
 *
 * - free_issuance_limit (nullable unsigned int, default null): the number
 *   of times a single user may receive this type for free through the
 *   admin_filed_free channel. NULL means unlimited — the deliberate
 *   representation chosen for Leave of Absence, which the Free
 *   Documents/Certificates Request Policy does not cap (confirmed with
 *   Registrar leadership: LOA is legitimately requested more than once
 *   per student across different semesters/reasons — medical, financial,
 *   personal — and every instance is free). Certificate of Graduation and
 *   Transcript of Records (Graduates) instead get free_issuance_limit = 1,
 *   per the First Copy Free Issuance for Graduates Policy §3.1 ("1st
 *   Request FREE, 2nd Request Onwards PAID").
 *
 *   A plain nullable int (rather than a separate "is_unlimited" boolean)
 *   keeps FreeRequestEligibilityService's limit check a single comparison
 *   with one branch for NULL, and avoids two columns that could
 *   contradict each other (e.g. is_unlimited = true but limit = 3).
 *
 * Added to BOTH tables for the same "schema symmetry" reasoning already
 * established by 2026_08_29_000001_add_logbook_and_submission_fields_to_
 * document_and_certificate_types.php (access_id, cashier_document_patterns,
 * logbook_category_id, requires_source_submission all already mirror
 * across both tables).
 *
 * Does NOT seed actual values for TOR/COG/LOA — that is a data change,
 * not a schema change, and belongs in a dedicated seeder/data migration
 * once the Free Request feature is ready to go live behind its feature
 * flag (see Phase 9 — Rollout). Every other type defaults to
 * is_free_eligible = false, free_issuance_limit = NULL, which is the
 * correct "not part of this feature" state.
 *
 * Written the same idempotent, re-runnable way as the rest of this
 * migration set (see 2026_08_29_000001, 2026_08_29_000009).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_type', function (Blueprint $table) {
            if (!Schema::hasColumn('document_type', 'is_free_eligible')) {
                $table->boolean('is_free_eligible')->default(false)->after('requires_source_submission');
            }
            if (!Schema::hasColumn('document_type', 'free_issuance_limit')) {
                $table->unsignedInteger('free_issuance_limit')->nullable()->after('is_free_eligible');
            }
        });

        Schema::table('certificate_type', function (Blueprint $table) {
            if (!Schema::hasColumn('certificate_type', 'is_free_eligible')) {
                $table->boolean('is_free_eligible')->default(false)->after('requires_source_submission');
            }
            if (!Schema::hasColumn('certificate_type', 'free_issuance_limit')) {
                $table->unsignedInteger('free_issuance_limit')->nullable()->after('is_free_eligible');
            }
        });
    }

    public function down(): void
    {
        Schema::table('document_type', function (Blueprint $table) {
            if (Schema::hasColumn('document_type', 'free_issuance_limit')) {
                $table->dropColumn('free_issuance_limit');
            }
            if (Schema::hasColumn('document_type', 'is_free_eligible')) {
                $table->dropColumn('is_free_eligible');
            }
        });

        Schema::table('certificate_type', function (Blueprint $table) {
            if (Schema::hasColumn('certificate_type', 'free_issuance_limit')) {
                $table->dropColumn('free_issuance_limit');
            }
            if (Schema::hasColumn('certificate_type', 'is_free_eligible')) {
                $table->dropColumn('is_free_eligible');
            }
        });
    }
};
