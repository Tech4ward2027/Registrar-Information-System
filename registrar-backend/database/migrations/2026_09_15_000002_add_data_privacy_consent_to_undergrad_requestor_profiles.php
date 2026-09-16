<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Undergrad Requestor Registration — Phase 6.
 *
 * Records the requestor's Data Privacy Act consent as DATA, not just as
 * a sentence on a web page.
 *
 * The plan's Phase 6 line item is "explicit Data Privacy Act consent
 * notice on the onboarding form." A notice alone is a frontend string —
 * it satisfies the letter of the requirement and none of its purpose.
 * RA 10173 makes the Registrar the personal information controller for
 * this data; the question the University will actually be asked, if it
 * is ever asked, is "prove this person consented, to what text, and
 * when." Three columns is the whole cost of being able to answer.
 *
 *   data_privacy_consent_at       WHEN. Server-side timestamp, never
 *                                 client-supplied.
 *   data_privacy_consent_version  TO WHAT. The notice is going to be
 *                                 reworded eventually; without a version
 *                                 stamp, every historical consent record
 *                                 silently re-points at whatever text is
 *                                 current, which is worth nothing as
 *                                 evidence. Sourced from
 *                                 config('undergrad_requestor.data_privacy.consent_version')
 *                                 so the backend — not the browser —
 *                                 decides what was agreed to.
 *   data_privacy_consent_ip       FROM WHERE. Same evidentiary role the
 *                                 ip_address column already plays on
 *                                 audit_logs and security_events, and
 *                                 stored the same way (plaintext, 45
 *                                 chars for IPv6) for consistency with
 *                                 those tables.
 *
 * All three are nullable — not because consent is optional (the form
 * request makes it 'accepted', i.e. required), but because this column
 * is being added to a table that may already hold rows submitted before
 * it existed. A NOT NULL column with a backfilled default would be
 * worse than useless here: it would manufacture consent records for
 * people who were never shown the notice. A NULL means exactly what it
 * should mean — "no evidence of consent for this submission" — and the
 * Admin detail view surfaces it as such.
 *
 * Consent is never purged by the D9 90-day sweep. That sweep disposes of
 * the personal data; the record that disposal was lawful has to outlive
 * the data itself. (The profile row is deleted wholesale by the purge,
 * so the enduring evidence is the audit_logs entry written at
 * submission time — see UndergradRequestorRegistrationService.)
 *
 * IDEMPOTENT: guarded by Schema::hasColumn(), matching this codebase's
 * additive-column-migration convention.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('undergrad_requestor_profiles')) {
            return;
        }

        Schema::table('undergrad_requestor_profiles', function (Blueprint $table) {
            if (!Schema::hasColumn('undergrad_requestor_profiles', 'data_privacy_consent_at')) {
                $table->timestamp('data_privacy_consent_at')->nullable()->after('phone');
            }

            if (!Schema::hasColumn('undergrad_requestor_profiles', 'data_privacy_consent_version')) {
                $table->string('data_privacy_consent_version', 20)->nullable()->after('data_privacy_consent_at');
            }

            if (!Schema::hasColumn('undergrad_requestor_profiles', 'data_privacy_consent_ip')) {
                $table->string('data_privacy_consent_ip', 45)->nullable()->after('data_privacy_consent_version');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('undergrad_requestor_profiles')) {
            return;
        }

        Schema::table('undergrad_requestor_profiles', function (Blueprint $table) {
            foreach (['data_privacy_consent_ip', 'data_privacy_consent_version', 'data_privacy_consent_at'] as $column) {
                if (Schema::hasColumn('undergrad_requestor_profiles', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
