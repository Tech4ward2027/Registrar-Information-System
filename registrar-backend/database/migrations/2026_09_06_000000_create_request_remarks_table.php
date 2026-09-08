<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deficiency Notice & Withdrawn Status — Phase 3.
 *
 * request_remarks is a named, cleared/voidable HOLD on a request that
 * flags a specific missing item without changing document_request.
 * status_id — see the Deficiency Notice Policy and this feature's
 * implementation plan (Phase 3). Distinct from Withdrawn (Phase 1),
 * which IS a terminal status change: a Deficiency Notice pauses
 * processing on a request that will still be fulfilled once the
 * flagged item is received, whereas Withdrawn closes out a request
 * that never will be.
 *
 * remark_type ('deficiency' by default) exists for extensibility — a
 * future general-purpose Remarks feature (any staff note attached to a
 * request, not only a missing-item hold) can reuse this same table by
 * adding new remark_type values, rather than a parallel table. Nothing
 * in this migration or DeficiencyNoticeService assumes remark_type is
 * always 'deficiency'; the column simply is not yet driven by anything
 * else.
 *
 * item_key / item_label split — mirrors WithdrawalReasonEnum's exact
 * pattern (see WithdrawalReasonEnum's docblock and the add_withdrawn_
 * status migration): item_key is the machine-readable
 * DeficiencyItemEnum value used by application logic (validation,
 * routing "other requires detail"), item_label is the denormalized
 * human-readable text written once at issue() time so every consumer
 * (dashboard badge, detail-view banner, notification template) can
 * display it directly without re-resolving the enum or joining
 * anything. item_key is nullable — not because a 'deficiency'-type
 * remark can omit it (DeficiencyNoticeService::issue() always requires
 * and sets it via IssueDeficiencyNoticeRequest's validation), but
 * because a hypothetical future non-deficiency remark_type may have no
 * machine-readable key at all, only free text. item_label itself is
 * NOT nullable: every remark, of any type, must be human-readable at a
 * glance.
 *
 * status — plain string column ('open' | 'cleared' | 'voided'), same
 * "no DB enum column" convention already used for document_request.
 * channel and request_status.status_name (see that migration's
 * docblock, and the job_run_logs migration it in turn cites) — a
 * future status should never need a migration to add. Enforced at the
 * application layer by DeficiencyNoticeService, not a DB CHECK
 * constraint.
 *
 * ONE-OPEN-NOTICE-PER-REQUEST — deliberately NOT enforced here via a
 * partial/filtered unique index (e.g. `UNIQUE (request_id) WHERE status
 * = 'open'`). Per this feature's Phase 0 pre-flight findings (see
 * app/Console/Commands/PreflightCheckWithdrawnStatus.php's docblock and
 * the add_channel_to_document_request migration's identical note),
 * production runs MySQL (config/database.php, DB_CONNECTION=mysql),
 * which has no support for partial/filtered unique indexes the way
 * Postgres does, and the test suite runs SQLite — a workaround viable
 * on one engine would not be portable to the other. Enforced instead by
 * DeficiencyNoticeService::issue(), which row-locks the parent
 * document_request (the same "transactional row lock over a partial DB
 * constraint" pattern DocumentRequestService::claimRequest()/withdraw()
 * already rely on) before checking for an existing open remark, so two
 * concurrent issue() calls for the same request can never both
 * succeed.
 *
 * Indexed on (request_id, status) rather than request_id alone: the
 * single query this table exists to serve — "does this request have a
 * currently open notice" (DeficiencyNoticeService::issue()'s guard,
 * DocumentRequestController::show()'s eager-loaded openDeficiencyNotice
 * relation) — always filters on both columns together.
 *
 * issued_by / cleared_by / voided_by use restrictOnDelete (not cascade/
 * null) — same reasoning as document_request.archived_by/restored_by
 * and graduate_verifications' verifier columns: a staff account being
 * later deleted must not silently erase who issued, cleared, or voided
 * a hold that may matter for a later dispute or audit.
 *
 * IDEMPOTENT: guarded by Schema::hasTable(), matching every other
 * create-table migration in this set (see graduate_verifications,
 * job_run_logs, security_events).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('request_remarks')) {
            return;
        }

        Schema::create('request_remarks', function (Blueprint $table) {
            // Plain integer autoincrement, matching this schema's PK
            // convention (see job_run_logs, security_events,
            // unmatched_cashier_items, graduate_verifications) rather
            // than Laravel's default bigIncrements.
            $table->integer('remark_id')->autoIncrement();

            $table->integer('request_id');

            $table->string('remark_type', 30)->default('deficiency');

            $table->string('item_key', 50)->nullable();
            $table->string('item_label', 255);
            $table->text('detail')->nullable();

            $table->string('status', 20)->default('open');

            $table->integer('issued_by');
            $table->timestamp('issued_at')->nullable();

            $table->integer('cleared_by')->nullable();
            $table->timestamp('cleared_at')->nullable();

            $table->integer('voided_by')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();

            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->timestamp('updated_at')->nullable()->useCurrent()->useCurrentOnUpdate();

            $table->index(['request_id', 'status'], 'request_remarks_request_status_idx');

            $table->foreign('request_id', 'request_remarks_request_fk')
                ->references('request_id')->on('document_request')
                ->cascadeOnDelete();

            $table->foreign('issued_by', 'request_remarks_issuer_fk')
                ->references('user_id')->on('users')
                ->restrictOnDelete();

            $table->foreign('cleared_by', 'request_remarks_clearer_fk')
                ->references('user_id')->on('users')
                ->restrictOnDelete();

            $table->foreign('voided_by', 'request_remarks_voider_fk')
                ->references('user_id')->on('users')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_remarks');
    }
};
