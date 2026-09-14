<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Undergrad Requestor Registration — Phase 1 (D5).
 *
 * undergrad_requestor_profiles holds the self-declared identity/contact
 * data collected on the public onboarding form (Phase 2). Deliberately
 * its own table rather than reusing student_profile/student_academic_
 * record: those are OGOS-owned mirrors, overwritten wholesale on every
 * Student SSO login (see OgosStudentService::upsertLocalRecords()).
 * Undergrad Requestor data is self-declared and UNVERIFIED until an
 * Admin approves it — it must never risk being silently overwritten by
 * an unrelated OGOS sync, so it needs its own provenance and lifecycle.
 *
 * student_number is indexed but deliberately NOT unique — per the
 * implementation plan, a duplicate is a review flag for the Admin
 * verification queue (Phase 4), not something the database should
 * reject outright (multiple people may mistype, or multiple genuine
 * requestors may — rarely — share a data-entry error at the source).
 *
 * program — free-text "program/course" as self-declared by the
 * requestor, NOT a foreign key into `courses`. This population may
 * describe programs inconsistently (renamed, discontinued, or simply
 * misremembered), and no verification against the course catalog is
 * part of this feature's scope — see D6, which limits verification to
 * the two advisory checks (local historical mirror + live OGOS lookup)
 * and treats everything else as reviewed by a human, not validated by
 * schema.
 *
 * email_verified_at — nullable timestamp set once the requestor
 * confirms the confirmation-link email sent immediately after
 * submission (Phase 2/D10). The Admin verification queue (Phase 4)
 * only ever surfaces rows where this is set — see
 * UndergradRequestorRegistrationService.
 *
 * No file/image/ID upload column exists on this table by design (D7,
 * explicit in the policy doc §3.4 note) — mirrors graduate_verifications'
 * precedent of recording that a check happened and who did it, never
 * storing the identity document itself.
 *
 * user_id is unique — exactly one profile per Undergrad Requestor
 * account, same convention as student_profile.user_id.
 *
 * created_at/updated_at are real, persisted timestamps (unlike
 * student_profile, which predates this convention and uses
 * $timestamps = false) — the Admin verification queue (Phase 4) needs
 * to sort/filter submissions by recency, and the 14-day abandonment
 * sweep (D9, Phase 4) needs a reliable "submitted at" anchor.
 *
 * IDEMPOTENT: guarded by Schema::hasTable(), matching every other
 * create-table migration in this set (see graduate_verifications,
 * request_remarks, job_run_logs).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('undergrad_requestor_profiles')) {
            return;
        }

        Schema::create('undergrad_requestor_profiles', function (Blueprint $table) {
            // Plain integer autoincrement, matching this schema's PK
            // convention (see job_run_logs, security_events,
            // graduate_verifications, request_remarks) rather than
            // Laravel's default bigIncrements.
            $table->integer('undergrad_requestor_profile_id')->autoIncrement();

            $table->integer('user_id')->unique('uq_undergrad_requestor_profiles_user_id');

            $table->string('first_name', 100);
            $table->string('middle_name', 100)->nullable();
            $table->string('last_name', 100);
            $table->string('suffix', 20)->nullable();

            $table->string('student_number', 50);
            $table->string('program', 255);
            $table->string('last_school_year_attended', 20);

            $table->date('date_of_birth');
            $table->text('present_address');
            $table->text('reason_for_non_enrollment')->nullable();
            $table->string('phone', 20);

            $table->timestamp('email_verified_at')->nullable();

            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->timestamp('updated_at')->nullable()->useCurrent()->useCurrentOnUpdate();

            $table->index('student_number', 'undergrad_requestor_profiles_student_number_idx');

            $table->foreign('user_id', 'undergrad_requestor_profiles_user_fk')
                ->references('user_id')->on('users')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('undergrad_requestor_profiles');
    }
};
