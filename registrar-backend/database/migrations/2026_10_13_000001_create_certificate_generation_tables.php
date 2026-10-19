<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Server-side certificate generation (Phase 3).
 *
 * certificate_control_sequence
 *   One row per calendar year holding the last control-number sequence handed
 *   out. The generator increments it under a row lock inside a transaction, so
 *   two concurrent generations can never receive the same number.
 *
 * certificate_generation
 *   One row per generation ATTEMPT. The control number is reserved (status
 *   `pending`) before the PDF is built, then the row becomes `generated` or
 *   `failed`. A failed attempt therefore leaves a visible, explained gap in
 *   the numbering instead of a silent one.
 *
 *   NO PDF path is stored: generated PDFs are streamed to the caller and never
 *   archived. `template_version` is denormalised on purpose so the record keeps
 *   saying which wording produced the certificate even if the template row is
 *   ever changed. Request references are plain indexed columns (no FK) so the
 *   record survives disposal of the request under the retention policy.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('certificate_control_sequence')) {
            Schema::create('certificate_control_sequence', function (Blueprint $table) {
                $table->unsignedSmallInteger('year')->primary();
                $table->unsignedInteger('last_number')->default(0);
                $table->timestamp('updated_at')->nullable();
            });
        }

        if (Schema::hasTable('certificate_generation')) {
            return;
        }

        Schema::create('certificate_generation', function (Blueprint $table) {
            $table->bigIncrements('certificate_generation_id');

            $table->string('control_number', 32);

            // certificate_type.certificate_type_id is a signed INT;
            // certificate_template.certificate_template_id is an unsigned BIGINT.
            $table->integer('certificate_type_id');
            $table->unsignedBigInteger('certificate_template_id');
            $table->unsignedInteger('template_version');

            $table->integer('requester_user_id')->nullable();
            $table->string('student_number', 50);
            $table->integer('request_id')->nullable();
            $table->integer('request_certificate_id')->nullable();

            $table->string('status', 12)->default('pending');
            $table->string('failure_code', 40)->nullable();
            $table->json('payload_snapshot')->nullable();

            $table->integer('generated_by')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('generated_at')->nullable();

            $table->unique('control_number', 'uq_cert_generation_control_number');
            $table->index('request_id', 'idx_cert_generation_request');
            $table->index('requester_user_id', 'idx_cert_generation_requester');
            $table->index(['certificate_type_id', 'created_at'], 'idx_cert_generation_type_created');
            $table->index(['status', 'created_at'], 'idx_cert_generation_status_created');

            $table->foreign('certificate_type_id', 'fk_cert_generation_type')
                ->references('certificate_type_id')->on('certificate_type')
                ->restrictOnDelete();
            $table->foreign('certificate_template_id', 'fk_cert_generation_template')
                ->references('certificate_template_id')->on('certificate_template')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificate_generation');
        Schema::dropIfExists('certificate_control_sequence');
    }
};
