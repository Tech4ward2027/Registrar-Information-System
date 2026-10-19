<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Database-driven certificate templates (Phase 2).
 *
 * One row per template VERSION. Rows are immutable once written (only the
 * active flag flips), so "keep the previous version" is simply "keep the row".
 * The template body is NOT in MySQL: `storage_path` points at the object in
 * the private bucket and `content_sha256` lets a read prove it got the same
 * bytes that were saved.
 *
 * `active_certificate_type_id` is set to certificate_type_id while a row is
 * active and NULL otherwise. Its UNIQUE index is what guarantees at most one
 * active version per type at the database level (MySQL has no partial unique
 * index; multiple NULLs are allowed in a unique index).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('certificate_template')) {
            return;
        }

        Schema::create('certificate_template', function (Blueprint $table) {
            $table->bigIncrements('certificate_template_id');

            // certificate_type.certificate_type_id is a signed INT.
            $table->integer('certificate_type_id');
            $table->unsignedInteger('version');
            $table->string('template_slug', 60);
            $table->string('storage_path', 255);
            $table->char('content_sha256', 64);

            $table->string('paper_size', 10);
            $table->string('orientation', 10);
            $table->decimal('margin_top', 5, 1);
            $table->decimal('margin_right', 5, 1);
            $table->decimal('margin_bottom', 5, 1);
            $table->decimal('margin_left', 5, 1);
            $table->json('required_fields');

            $table->boolean('is_active')->default(false);
            $table->integer('active_certificate_type_id')->nullable();

            $table->string('change_note', 255)->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['certificate_type_id', 'version'], 'uq_cert_template_type_version');
            $table->unique('active_certificate_type_id', 'uq_cert_template_one_active');
            $table->unique(['template_slug', 'version'], 'uq_cert_template_slug_version');
            $table->index('template_slug', 'idx_cert_template_slug');

            $table->foreign('certificate_type_id', 'fk_cert_template_type')
                ->references('certificate_type_id')->on('certificate_type')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificate_template');
    }
};
