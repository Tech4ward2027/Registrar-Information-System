<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-item Withdraw and Close (Phase 4): where the reason for one
 * document/certificate leaving its request is stored.
 *
 * One row per item (unique on each item FK). kind is 'withdrawn' or
 * 'closed'; reason/detail hold the WithdrawalReasonEnum or
 * ClosureReasonEnum value plus staff free text; proof_reference is
 * required for 'closed' only and is enforced by the service, not the
 * database. cascaded = true when the row was written by a whole-request
 * withdraw/close rather than by staff acting on this item alone.
 *
 * Expand-only and idempotent: nothing existing is changed. The OR number
 * and fee columns on document_request are not touched.
 *
 * Rollback: down() drops the table (and with it the per-item reasons).
 * Back up first if it already holds data.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('request_item_terminations')) {
            return;
        }

        Schema::create('request_item_terminations', function (Blueprint $table) {
            // Plain integer PKs/FKs, matching this schema's convention.
            $table->integer('termination_id')->autoIncrement();

            $table->integer('request_id');
            $table->integer('request_document_id')->nullable();
            $table->integer('request_certificate_id')->nullable();

            $table->string('kind', 20);
            $table->string('reason', 50);
            $table->text('detail')->nullable();
            $table->string('proof_reference', 500)->nullable();
            $table->boolean('cascaded')->default(false);

            $table->integer('acted_by');
            $table->timestamp('acted_at')->useCurrent();

            // One termination per item. Two nullable uniques: a NULL never
            // collides, so a document row and a certificate row coexist.
            $table->unique('request_document_id', 'rit_document_uq');
            $table->unique('request_certificate_id', 'rit_certificate_uq');
            $table->index(['request_id', 'kind'], 'rit_request_kind_idx');

            $table->foreign('request_id', 'rit_request_fk')
                ->references('request_id')->on('document_request')
                ->cascadeOnDelete();
            $table->foreign('request_document_id', 'rit_document_fk')
                ->references('request_document_id')->on('request_document')
                ->cascadeOnDelete();
            $table->foreign('request_certificate_id', 'rit_certificate_fk')
                ->references('request_certificate_id')->on('request_certificate')
                ->cascadeOnDelete();
            $table->foreign('acted_by', 'rit_actor_fk')
                ->references('user_id')->on('users')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_item_terminations');
    }
};
