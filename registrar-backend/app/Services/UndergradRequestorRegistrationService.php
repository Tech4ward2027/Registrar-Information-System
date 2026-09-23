<?php

namespace App\Services;

use App\Contracts\UndergradRequestorRegistrationServiceInterface;
use App\Mail\UndergradRequestorEmailVerificationMail;
use App\Models\AuditLog;
use App\Models\SecurityEvent;
use App\Models\SystemUser;
use App\Models\UndergradRequestorProfile;
use App\Models\UndergradRequestorVerification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Undergrad Requestor Registration — Phase 2, hardened in Phase 6.
 *
 * See UndergradRequestorRegistrationServiceInterface for the contract
 * this implements. Kept as a single service (rather than split
 * register()/confirmEmail() across two classes) because both operate
 * on the same three-row cluster (users / undergrad_requestor_profiles /
 * undergrad_requestor_verifications) and the plan explicitly names one
 * service for this responsibility.
 *
 * Phase 6 additions:
 *  - Data Privacy Act consent (RA 10173) is recorded against the
 *    submission and carried into the audit entry.
 *  - Every failed email-confirmation attempt writes a security_events
 *    row. The user-facing message stays deliberately vague (it must, or
 *    the endpoint becomes an account-enumeration oracle) — the specific
 *    reason goes somewhere only we can read it, which is the whole
 *    point of having a security-event table separate from the response.
 */
class UndergradRequestorRegistrationService implements UndergradRequestorRegistrationServiceInterface
{
    /**
     * How long an unconfirmed onboarding email-confirmation link stays
     * valid. Deliberately much shorter than D9's 14-day abandonment
     * sweep (Phase 4) — that sweep cleans up a whole stale PII row;
     * this only bounds how long a single emailed link can be replayed
     * before the person would need to resubmit.
     */
    private const EMAIL_VERIFICATION_TTL_HOURS = 24;

    public function __construct(
        private AuditLogger         $auditLogger,
        private SecurityEventLogger $securityEvents,
    ) {}

    /**
     * @throws ValidationException
     */
    public function register(array $validated, Request $request): UndergradRequestorProfile
    {
        // Defense-in-depth on top of StoreUndergradRequestorRegistrationRequest's
        // unique:users,email rule — closes the narrow window between that
        // check running and this transaction's INSERT, and gives a
        // friendlier message than a raw QueryException if a race is ever
        // hit. Same reasoning/shape as AccessRequestService::store()'s
        // target_email check.
        if (SystemUser::where('email', $validated['email'])->exists()) {
            throw ValidationException::withMessages([
                'email' => 'This email is already associated with an account.',
            ]);
        }

        [$plainToken, $tokenHash] = $this->generateVerificationToken();

        // RA 10173 (Phase 6). Every element of the consent record is
        // decided HERE, server-side. The form sends one boolean, which
        // the FormRequest has already validated as 'accepted'; it does
        // not get to tell us when it consented, to which version of the
        // notice, or from where. See config('undergrad_requestor.data_privacy')
        // for why the version matters.
        $consentVersion = (string) config('undergrad_requestor.data_privacy.consent_version', '1.0');
        $consentAt      = now();
        $consentIp      = $request->ip();

        $profile = DB::transaction(function () use ($validated, $tokenHash, $consentVersion, $consentAt, $consentIp, $request) {
            $user = SystemUser::create([
                'email'              => $validated['email'],
                'password'           => null,
                'role_id'            => SystemUser::ROLE_UNDERGRAD_REQUESTOR,
                'status'             => 'Pending Verification',
                'idp_user_id'        => null,
                'local_auth_enabled' => 0,
                // D9/Phase 3 — reuses the exact same column and 14-day
                // window AdminUserService::create() already established
                // for 'Pending Activation' invites (see that method's
                // docblock), so the existing ExpireStaleProvisioning
                // sweep and UserProvisioningService::provision()'s
                // live past-due check (QA #11) both cover this status
                // for free, widened rather than duplicated — see
                // Phase 4's "extend/clone ExpireStaleProvisioning."
                //
                // Phase 6: the window itself now comes from config
                // rather than being hardcoded here, so this write and
                // the sweep that acts on it read the same number (see
                // config/undergrad_requestor.php, 'abandonment_days').
                'pending_expires_at' => now()->addDays((int) config('undergrad_requestor.abandonment_days', 14)),
            ]);

            $profile = UndergradRequestorProfile::create([
                'user_id'                        => $user->user_id,
                'first_name'                     => $validated['first_name'],
                'middle_name'                    => $validated['middle_name'] ?? null,
                'last_name'                      => $validated['last_name'],
                'suffix'                         => $validated['suffix'] ?? null,
                'student_number'                 => $validated['student_number'],
                'program'                        => $validated['program'],
                'last_school_year_attended'      => $validated['last_school_year_attended'],
                'date_of_birth'                  => $validated['date_of_birth'],
                'present_address'                => $validated['present_address'],
                'reason_for_non_enrollment'      => $validated['reason_for_non_enrollment'] ?? null,
                'phone'                          => $validated['phone'],
                'email_verified_at'              => null,
                'email_verification_token_hash'  => $tokenHash,
                'email_verification_expires_at'  => now()->addHours(self::EMAIL_VERIFICATION_TTL_HOURS),

                // Phase 6 (RA 10173).
                'data_privacy_consent_at'        => $consentAt,
                'data_privacy_consent_version'   => $consentVersion,
                'data_privacy_consent_ip'        => $consentIp,
            ]);

            UndergradRequestorVerification::create([
                'user_id' => $user->user_id,
                'status'  => 'Pending',
            ]);

            // D6/Phase 4 review hint — duplicate student numbers are a
            // review flag, never a submission-blocking error (the DB
            // column is deliberately non-unique, see the migration
            // docblock). Surfaced here via the audit trail rather than
            // a persisted column on this row: Phase 4's Admin queue can
            // always re-derive "is this student_number shared by other
            // submissions?" at review time with a single indexed query
            // against student_number, so there is nothing to keep in
            // sync if a colliding submission is later approved/rejected/
            // purged.
            $duplicateCount = UndergradRequestorProfile::query()
                ->where('student_number', $validated['student_number'])
                ->where('user_id', '!=', $user->user_id)
                ->count();

            // The consent facts are copied into the audit entry as well
            // as stored on the profile row on purpose. The D9 90-day
            // purge DELETES the profile row outright — after that, the
            // audit log is the only surviving evidence that consent was
            // obtained before the data was collected, and audit_logs is
            // hash-chained and never pruned. Note this records the
            // consent's METADATA (version, timestamp), not the person's
            // personal data, so it does not undermine the purge.
            $this->auditLogger->log($request, $user, AuditLog::ACTION_UNDERGRAD_REQUESTOR_REGISTERED, [
                'target_user_id'                 => $user->user_id,
                'target_email'                   => $user->email,
                'student_number'                 => $validated['student_number'],
                'duplicate_student_number_count' => $duplicateCount,
                'data_privacy_consent_version'   => $consentVersion,
                'data_privacy_consent_at'        => $consentAt->toIso8601String(),
            ]);

            return $profile;
        });

        // Sent AFTER the transaction commits — see the interface
        // docblock for why. ShouldQueue on the Mailable means this call
        // itself returns immediately (the actual SMTP conversation
        // happens on the queue worker), so this line is never the slow
        // part of the request either way.
        Mail::to($profile->user->email)->send(
            new UndergradRequestorEmailVerificationMail($profile, $this->buildVerificationUrl($profile->user->email, $plainToken))
        );

        return $profile;
    }

    /**
     * @throws ValidationException
     */
    public function confirmEmail(string $email, string $token, Request $request): UndergradRequestorProfile
    {
        $email = Str::lower(trim($email));

        $user = SystemUser::where('email', $email)
            ->where('role_id', SystemUser::ROLE_UNDERGRAD_REQUESTOR)
            ->first();

        $profile = $user?->undergradRequestorProfile;

        // Same generic message for "no such account", "token mismatch",
        // and "expired" — deliberately not distinguished in the
        // response, so this endpoint can never be used to enumerate
        // which onboarding emails exist or probe token guesses with more
        // specific feedback than a real one would get.
        //
        // Phase 6: the reason we refuse to tell the CALLER is exactly
        // the reason we must tell OURSELVES. Each branch records a
        // security_events row carrying the specific cause, so "someone
        // is grinding tokens against one address" is visible to the RIS
        // team in a queryable table rather than inferable only from a
        // pile of identical 422s.
        $fail = function (string $reason) use ($email, $request) {
            $this->securityEvents->recordOnboardingEmailConfirmationFailure($email, $reason, $request);

            return ValidationException::withMessages([
                'token' => 'This confirmation link is invalid or has expired. Please submit the onboarding form again.',
            ]);
        };

        if (!$user || !$profile) {
            throw $fail(SecurityEvent::REASON_UNKNOWN_ACCOUNT);
        }

        if ($profile->isEmailVerified()) {
            // The one branch with its own message. It leaks nothing an
            // attacker could not already infer from having a valid link
            // in hand, and telling the honest double-clicker "this is
            // already done" instead of "start over" is the difference
            // between a support ticket and none.
            $this->securityEvents->recordOnboardingEmailConfirmationFailure(
                $email,
                SecurityEvent::REASON_ALREADY_VERIFIED,
                $request,
            );

            throw ValidationException::withMessages([
                'token' => 'This email address has already been confirmed.',
            ]);
        }

        if (!$profile->email_verification_token_hash || !$profile->email_verification_expires_at) {
            throw $fail(SecurityEvent::REASON_TOKEN_INVALID);
        }

        if ($profile->email_verification_expires_at->isPast()) {
            throw $fail(SecurityEvent::REASON_TOKEN_EXPIRED);
        }

        // hash_equals — constant-time, so a near-miss token cannot be
        // walked closer by timing the response.
        if (!hash_equals($profile->email_verification_token_hash, hash('sha256', $token))) {
            throw $fail(SecurityEvent::REASON_TOKEN_INVALID);
        }

        $profile->update([
            'email_verified_at'              => now(),
            'email_verification_token_hash' => null,
            'email_verification_expires_at' => null,
        ]);

        $this->auditLogger->log($request, $user, AuditLog::ACTION_UNDERGRAD_REQUESTOR_EMAIL_VERIFIED, [
            'target_user_id' => $user->user_id,
            'target_email'   => $user->email,
        ]);

        return $profile->fresh();
    }

    /**
     * Random 40-char plaintext token (emailed) + its SHA-256 hash
     * (persisted) — see the add_email_verification_columns migration's
     * docblock for why only the hash is ever stored. Str::random(40)
     * matches the length Laravel's own password-reset tokens use, and
     * is drawn from a cryptographically secure source (random_bytes
     * under the hood), not mt_rand.
     *
     * @return array{0: string, 1: string} [$plainToken, $tokenHash]
     */
    private function generateVerificationToken(): array
    {
        $plainToken = Str::random(40);

        return [$plainToken, hash('sha256', $plainToken)];
    }

    private function buildVerificationUrl(string $email, string $plainToken): string
    {
        return rtrim(config('app.frontend_url'), '/') . '/undergrad-requestor/verify-email?' . http_build_query([
            'email' => $email,
            'token' => $plainToken,
        ]);
    }
}