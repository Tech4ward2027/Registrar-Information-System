<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the self-declared gender of an Undergrad Requestor so the Logbook can
 * report it (Student/Alumni rows already carry sex_at_birth).
 *
 * Nullable by design: rows submitted before this column existed have no
 * declared gender, and NULL is the honest value for them — backfilling a
 * default would invent data about real people. The Logbook shows NULL as
 * "---".
 *
 * Stored as a short string (validated against App\Enums\GenderEnum) rather
 * than a DB enum, matching the codebase's convention for application-owned
 * value sets. Plaintext, like first_name/last_name/program: it is shown in
 * list rows and the Logbook, so it is not part of the encrypted-at-rest set
 * (phone, present_address, date_of_birth, reason_for_non_enrollment).
 *
 * IDEMPOTENT: guarded by Schema::hasTable()/hasColumn().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('undergrad_requestor_profiles')
            || Schema::hasColumn('undergrad_requestor_profiles', 'gender')) {
            return;
        }

        Schema::table('undergrad_requestor_profiles', function (Blueprint $table) {
            $table->string('gender', 10)->nullable()->after('suffix');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('undergrad_requestor_profiles')
            || !Schema::hasColumn('undergrad_requestor_profiles', 'gender')) {
            return;
        }

        Schema::table('undergrad_requestor_profiles', function (Blueprint $table) {
            $table->dropColumn('gender');
        });
    }
};
