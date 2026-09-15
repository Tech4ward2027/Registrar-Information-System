<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certificate_type', function (Blueprint $table) {
            if (!Schema::hasColumn('certificate_type', 'layout_header_lines')) {
                $table->json('layout_header_lines')->nullable()->after('layout_footer_logo_size');
            }
            if (!Schema::hasColumn('certificate_type', 'layout_header_font_size')) {
                $table->smallInteger('layout_header_font_size')->unsigned()->nullable()->after('layout_header_lines');
            }
        });
    }

    public function down(): void
    {
        Schema::table('certificate_type', function (Blueprint $table) {
            if (Schema::hasColumn('certificate_type', 'layout_header_font_size')) {
                $table->dropColumn('layout_header_font_size');
            }
            if (Schema::hasColumn('certificate_type', 'layout_header_lines')) {
                $table->dropColumn('layout_header_lines');
            }
        });
    }
};
