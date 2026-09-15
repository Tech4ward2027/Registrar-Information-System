<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Undergrad Requestor Registration — Phase 2 (D10).
 *
 * Adds the two columns UndergradRequestorRegistrationService needs to
 * verify the onboarding form's confirmation-link email before a
 * submission becomes visible to the Admin verification queue (Phase 4).
 *
 * This app is an SPA (see SsoCallbackController — the frontend exchanges
 * the IDP's authorization code via an API call rather than the backend
 * issuing a browser redirect), so the confirmation link points at a
 * FRONTEND route carrying a token, which the frontend then POSTs to the
 * backend. That rules out Laravel's built-in signed-route email
 * verification (which assumes the link is visited directly on the
 * backend); instead this follows the exact same shape as Laravel's own
 * password-reset tokens: a random token is emailed in plaintext, and
 * only its HASH is ever persisted — a leaked database row can never be
 * replayed as a valid confirmation.
 *
 * email_verification_token_hash — nullable because it's cleared the
 * moment confirmation succeeds (UndergradRequestorRegistrationService::
 * confirmEmail()); a non-null value means "confirmation still pending."
 *
 * email_verification_expires_at — a stale, unconfirmed link must
 * eventually stop working rather than remain valid forever. Distinct
 * from D9's 14-day abandonment sweep (Phase 4), which removes the whole
 * PII row — this is a much shorter, single-purpose window on the
 * confirmation link itself (see UndergradRequestorRegistrationService
 * for the exact value and rationale).
 *
 * IDEMPOTENT: guarded by Schema::hasColumn(), matching this codebase's
 * additive-column-migration convention (see e.g.
 * add_pending_expires_at_to_users.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('undergrad_requestor_profiles', function (Blueprint $table) {
            if (!Schema::hasColumn('undergrad_requestor_profiles', 'email_verification_token_hash')) {
                $table->string('email_verification_token_hash', 255)->nullable()->after('email_verified_at');
            }

            if (!Schema::hasColumn('undergrad_requestor_profiles', 'email_verification_expires_at')) {
                $table->timestamp('email_verification_expires_at')->nullable()->after('email_verification_token_hash');
            }
        });
    }

    public function down(): void
    {
        Schema::table('undergrad_requestor_profiles', function (Blueprint $table) {
            if (Schema::hasColumn('undergrad_requestor_profiles', 'email_verification_expires_at')) {
                $table->dropColumn('email_verification_expires_at');
            }

            if (Schema::hasColumn('undergrad_requestor_profiles', 'email_verification_token_hash')) {
                $table->dropColumn('email_verification_token_hash');
            }
        });
    }
};
