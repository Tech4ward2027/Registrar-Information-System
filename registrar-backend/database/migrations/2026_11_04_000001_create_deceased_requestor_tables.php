<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notification Policy §3.6: deceased or permanently incapacitated requestor.
 *
 * Two tables:
 *
 *   requestor_status_flags   one row per requestor account marked deceased or
 *                            incapacitated. While active (lifted_at IS NULL)
 *                            the System Mailer never emails the account's own
 *                            address. History lives in audit_logs, so a
 *                            re-flag after a lift reuses the same row.
 *
 *   request_representatives  the authorised representative or next of kin for
 *                            one request. Name and email are encrypted at
 *                            rest; proof documents are stored privately and
 *                            only their references live here.
 *
 * request_representatives.active_request_id is request_id while the row is
 * pending or verified and NULL once revoked. The unique index on it gives
 * "at most one live representative per request" as a database guarantee
 * (NULLs do not collide), which MySQL cannot express as a partial index.
 *
 * Actor columns (created_by, verified_by, ...) carry no foreign key on
 * purpose: deleting an admin account must not be blocked by, or erase, the
 * record of what that admin did.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('requestor_status_flags', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id')->unique();          // signed INT like users.user_id
            $table->string('status', 16);                  // deceased | incapacitated
            $table->string('note', 500)->nullable();
            $table->integer('flagged_by');
            $table->timestamp('lifted_at')->nullable();
            $table->integer('lifted_by')->nullable();
            $table->string('lift_reason', 500)->nullable();
            $table->timestamps();

            $table->foreign('user_id', 'fk_rsf_user')
                ->references('user_id')->on('users')
                ->onDelete('cascade');
        });

        Schema::create('request_representatives', function (Blueprint $table) {
            $table->id();
            $table->integer('request_id');                 // signed INT like document_request.request_id
            $table->text('full_name');                     // encrypted cast
            $table->text('email');                         // encrypted cast
            $table->string('relationship', 32);
            $table->json('proofs');                        // [{kind, disk, path, mime, size, sha256}]
            $table->string('status', 16)->default('pending');   // pending | verified | revoked
            $table->integer('active_request_id')->nullable()->unique('uq_rr_active_request');
            $table->integer('created_by');
            $table->integer('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->integer('revoked_by')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoke_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at'], 'idx_rr_status_created');
            $table->index('request_id', 'idx_rr_request');

            $table->foreign('request_id', 'fk_rr_request')
                ->references('request_id')->on('document_request');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_representatives');
        Schema::dropIfExists('requestor_status_flags');
    }
};
