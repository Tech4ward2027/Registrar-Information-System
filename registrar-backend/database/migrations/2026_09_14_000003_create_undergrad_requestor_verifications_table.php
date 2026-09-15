<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Undergrad Requestor Registration — Phase 1 (D5, D6, D8).
 *
 * undergrad_requestor_verifications mirrors graduate_verifications'
 * shape deliberately (see that migration's docblock): a dedicated
 * record of a human decision, not an automated one. One row per
 * Undergrad Requestor account (unique on user_id), created at onboarding
 * submission time alongside the users and undergrad_requestor_profiles
 * rows (Phase 2), and later read by:
 *   - Phase 3's SSO provisioning (UserProvisioningService::provision()),
 *     which auto-activates on status = Approved and raises
 *     AccountRejectedException on status = Rejected;
 *   - Phase 4's Admin verification queue/decision endpoints;
 *   - Phase 5's request-flow gate, which blocks document-request
 *     endpoints unless status = Approved.
 *
 * status — plain string column ('Pending' | 'Approved' | 'Rejected'),
 * matching this schema's established "no DB enum column" convention
 * (see request_remarks.status and WithdrawalReasonEnum's docblock for
 * the full reasoning) — a future status should never need a migration
 * to add. Enforced at the application layer via
 * App\Enums\UndergradRequestorVerificationStatusEnum, cast on the
 * UndergradRequestorVerification model.
 *
 * local_match_found / matched_student_profile_id — the local historical-
 * mirror advisory check (D6): does this requestor's declared student
 * number match an existing student_academic_record? matched_student_
 * profile_id is nullOnDelete (not restrict) — this FK is purely an
 * advisory pointer for the Admin UI to jump into the matched profile,
 * never itself evidence of the decision (that's local_match_found,
 * which stays true even if the matched profile is later removed).
 *
 * ogos_lookup_performed_at / ogos_match_found — the live OGOS advisory
 * check (D6). ogos_match_found is nullable on purpose: NULL means the
 * lookup was never attempted or OGOS was unreachable at review time
 * (same never-throws tryX() pattern as AlumniSystemClientInterface,
 * Phase 4) — a meaningfully different state from "lookup ran and found
 * nothing," which is `false`. OGOS only indexes currently-enrolled
 * students, so a match here is a misclassification/fraud signal worth
 * surfacing, not "no match = expected, meaningless."
 *
 * reviewed_by / reviewed_at / rejection_reason — set once by the Admin
 * decision (Phase 4's approve/reject actions). reviewed_by uses
 * restrictOnDelete, same reasoning as graduate_verifications.
 * credentials_verified_by / records_checked_by: a verifying admin's
 * account being later deleted must not silently erase who made a
 * fraud-relevant decision.
 *
 * user_id uses cascadeOnDelete (unlike the reviewer/matched-profile
 * FKs above) — this row is a child of the Undergrad Requestor account
 * itself, not an independent audit record referencing it; if the
 * account row is ever removed, its verification record has no
 * remaining subject. The permanent record that a decision occurred
 * lives in audit_logs regardless (see D9's PII-purge job, Phase 4/6),
 * which is never affected by this cascade.
 *
 * IDEMPOTENT: guarded by Schema::hasTable(), matching every other
 * create-table migration in this set.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('undergrad_requestor_verifications')) {
            return;
        }

        Schema::create('undergrad_requestor_verifications', function (Blueprint $table) {
            // Plain integer autoincrement, matching this schema's PK
            // convention (see job_run_logs, security_events,
            // graduate_verifications, request_remarks) rather than
            // Laravel's default bigIncrements.
            $table->integer('undergrad_requestor_verification_id')->autoIncrement();

            $table->integer('user_id');

            $table->string('status', 20)->default('Pending');

            $table->boolean('local_match_found')->default(false);
            $table->integer('matched_student_profile_id')->nullable();

            $table->timestamp('ogos_lookup_performed_at')->nullable();
            $table->boolean('ogos_match_found')->nullable();

            $table->integer('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->timestamp('updated_at')->nullable()->useCurrent()->useCurrentOnUpdate();

            // One verification record per Undergrad Requestor account —
            // a second onboarding attempt for the same account is
            // meaningless (the account is matched/created once at
            // submission time), same reasoning as graduate_verifications'
            // unique-on-document_request_id.
            $table->unique('user_id', 'undergrad_requestor_verifications_user_unique');

            // The single query the Admin verification queue (Phase 4)
            // exists to serve — "show me every Pending row" — always
            // filters on status.
            $table->index('status', 'undergrad_requestor_verifications_status_idx');

            $table->foreign('user_id', 'undergrad_requestor_verifications_user_fk')
                ->references('user_id')->on('users')
                ->cascadeOnDelete();

            $table->foreign('matched_student_profile_id', 'undergrad_requestor_verifications_matched_profile_fk')
                ->references('student_profile_id')->on('student_profile')
                ->nullOnDelete();

            $table->foreign('reviewed_by', 'undergrad_requestor_verifications_reviewer_fk')
                ->references('user_id')->on('users')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('undergrad_requestor_verifications');
    }
};
