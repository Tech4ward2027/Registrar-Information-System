<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FESPEC-0008 — Free Document/Certificate Request (Phase 1c).
 *
 * graduate_verifications records the in-person verification a Registrar
 * Admin performs before filing a first-copy free request for a graduate,
 * per the First Copy Free Issuance for Graduates Policy §3.3–3.4:
 *
 *   1. A 2x2 Toga Picture — physically presented and visually confirmed
 *      by staff at the Registrar's Office. NOT uploaded to or stored by
 *      this system: there is no file/image column on this table. Earlier
 *      drafts of this feature spec'd a toga_picture_path + secure file
 *      handling phase; both were dropped once Registrar leadership
 *      clarified the requirement is in-person visual verification, not a
 *      system upload. This table exists to record THAT the verification
 *      happened and WHO performed it, not to store the photo itself.
 *   2. Valid credentials for identity verification.
 *   3. Admin Records Verification — staff confirm the graduate's records
 *      are correct (cross-checked against the office's own graduate
 *      records) before proceeding.
 *
 * SCOPE — COG/TOR ONLY: this table is only ever populated for free
 * requests covering Certificate of Graduation or Transcript of Records
 * (Graduates) line items. The First Copy policy's scope (§2) is
 * explicitly limited to those two types; Leave of Absence is governed by
 * the base Free Documents/Certificates Request Policy alone, which has
 * no toga-picture/credentials verification step. FreeRequestService only
 * creates a row here when the request being filed includes a COG and/or
 * TOR line item — confirmed with Registrar leadership 2026-09.
 *
 * One row per document_request (not per line item): a single admin
 * verification visit covers whichever of COG/TOR the graduate is
 * claiming in that filing, matching the First Copy policy's single
 * verification step before "Step 3. Select and request the applicable
 * document (TOR and/or Certificate of Graduation)" (§3.5).
 *
 * credentials_verified_by / records_checked_by are separate columns
 * (rather than a single "verified_by") because the policy names them as
 * two distinct checks (§3.3 credential/toga verification vs. §3.4 records
 * correctness) — kept separate here so a future policy revision requiring
 * two different staff members for each check (maker-checker) doesn't need
 * a schema change, even though today one staff member performs both (see
 * Phase 2/3's policy-configurable verifier authorization).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('graduate_verifications')) {
            return;
        }

        Schema::create('graduate_verifications', function (Blueprint $table) {
            // Plain integer autoincrement, matching this schema's PK
            // convention (see job_run_logs, security_events,
            // unmatched_cashier_items) rather than Laravel's default
            // bigIncrements.
            $table->integer('graduate_verification_id')->autoIncrement();

            $table->integer('document_request_id');

            $table->integer('credentials_verified_by');
            $table->timestamp('credentials_verified_at')->nullable();

            $table->integer('records_checked_by');
            $table->timestamp('records_checked_at')->nullable();

            $table->timestamp('created_at')->nullable()->useCurrent();

            // One verification record per free request — a request
            // cannot be filed with the free channel twice, and a
            // verification is meaningless without the request it backs.
            $table->unique('document_request_id', 'graduate_verifications_request_unique');

            $table->foreign('document_request_id', 'graduate_verifications_request_fk')
                ->references('request_id')->on('document_request')
                ->cascadeOnDelete();

            // restrict (not cascade/null) — a verifying admin's account
            // being later deleted should not silently erase the record of
            // who performed a fraud-relevant verification. Same
            // reasoning as document_request.archived_by / restored_by,
            // which have no ON DELETE action either.
            $table->foreign('credentials_verified_by', 'graduate_verifications_cred_verifier_fk')
                ->references('user_id')->on('users')
                ->restrictOnDelete();

            $table->foreign('records_checked_by', 'graduate_verifications_records_checker_fk')
                ->references('user_id')->on('users')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('graduate_verifications');
    }
};
