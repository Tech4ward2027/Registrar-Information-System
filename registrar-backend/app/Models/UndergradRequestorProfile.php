<?php

namespace App\Models;

use App\Casts\EncryptedDate;
use App\Casts\EncryptedText;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Undergrad Requestor Registration — Phase 1 (D5).
 *
 * Self-declared, unverified identity/contact data collected on the
 * public onboarding form (Phase 2). See the
 * create_undergrad_requestor_profiles_table migration's docblock for
 * the full rationale — this is deliberately NOT a mirror of
 * StudentProfile/StudentAcademicRecord (those are OGOS-owned and
 * overwritten on every Student SSO sync).
 *
 * Phase 6 additions:
 *  - Four columns are encrypted at rest (see $casts below).
 *  - Data Privacy Act consent is recorded as data, not just displayed
 *    as a notice (see the add_data_privacy_consent migration).
 */
class UndergradRequestorProfile extends Model
{
    use HasFactory;

    protected $table      = 'undergrad_requestor_profiles';
    protected $primaryKey = 'undergrad_requestor_profile_id';

    protected $fillable = [
        'user_id',
        'first_name',
        'middle_name',
        'last_name',
        'suffix',
        'student_number',
        'program',
        'last_school_year_attended',
        'date_of_birth',
        'present_address',
        'reason_for_non_enrollment',
        'phone',
        // Set once by the email-verification confirmation flow
        // (Phase 2/D10), not accepted as direct client input on the
        // onboarding form itself.
        'email_verified_at',
        // Phase 2/D10 — set by UndergradRequestorRegistrationService at
        // submission time, cleared on successful confirmation. Never
        // accepted as client input; see the migration docblock for why
        // only the hash is ever persisted.
        'email_verification_token_hash',
        'email_verification_expires_at',
        // Phase 6 (RA 10173). Derived server-side at submission time —
        // the client sends only a boolean "I agree"; the timestamp,
        // the notice version and the IP are all decided here. Fillable
        // because the registration service sets them in the same
        // create() call as the rest of the row, not because any of the
        // three is ever accepted from request input.
        'data_privacy_consent_at',
        'data_privacy_consent_version',
        'data_privacy_consent_ip',
    ];

    /**
     * email_verification_token_hash never leaves the server — even a
     * successful confirmation should not echo it back in any API
     * response (UndergradRequestorRegistrationResource never selects
     * it either, but this is defense-in-depth against a future
     * ->toArray()/debug dump doing so accidentally).
     */
    protected $hidden = [
        'email_verification_token_hash',
    ];

    /**
     * Phase 6 — four columns are encrypted at rest.
     *
     * EncryptedText/EncryptedDate rather than Laravel's built-in
     * 'encrypted' cast: the built-in throws on any value written before
     * encryption was enabled, which would break every pre-Phase-6 row
     * and make the config flag one-way. See those cast classes and
     * App\Support\EncryptedPayload for the reasoning, the APP_KEY
     * warning, and why student_number / names / email stay plaintext
     * (they are indexed, searched, or the basis of the D4 email match).
     *
     * date_of_birth still reads back as a Carbon instance, so callers
     * such as UndergradRequestorVerificationDetailResource's
     * `->date_of_birth?->toDateString()` are unaffected by the change.
     */
    protected $casts = [
        'date_of_birth'                  => EncryptedDate::class,
        'present_address'                => EncryptedText::class,
        'reason_for_non_enrollment'      => EncryptedText::class,
        'phone'                          => EncryptedText::class,

        'email_verified_at'              => 'datetime',
        'email_verification_expires_at'  => 'datetime',
        'data_privacy_consent_at'        => 'datetime',
        'created_at'                     => 'datetime',
        'updated_at'                     => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(SystemUser::class, 'user_id', 'user_id');
    }

    public function verification()
    {
        return $this->hasOne(UndergradRequestorVerification::class, 'user_id', 'user_id');
    }

    /**
     * Full display name, same shape as other profile types in this
     * codebase build up for UserResource-style display strings —
     * suffix appended only when present.
     */
    public function getFullNameAttribute(): string
    {
        $parts = array_filter([$this->first_name, $this->middle_name, $this->last_name, $this->suffix]);

        return implode(' ', $parts);
    }

    /**
     * Phase 2/D10 — whether this submission's email has been confirmed.
     * The Admin verification queue (Phase 4) only ever surfaces rows
     * where this is true.
     */
    public function isEmailVerified(): bool
    {
        return $this->email_verified_at !== null;
    }

    /**
     * Phase 6 — whether this submission carries recorded Data Privacy
     * Act consent.
     *
     * False only for rows submitted before the consent columns existed
     * (the form request has made consent mandatory since). Surfaced to
     * the reviewer rather than hidden: "we have no evidence this person
     * was shown the notice" is exactly the sort of thing a reviewer
     * should see before approving, and exactly the sort of thing that
     * disappears if a nullable column is quietly defaulted.
     */
    public function hasRecordedDataPrivacyConsent(): bool
    {
        return $this->data_privacy_consent_at !== null;
    }

    /**
     * Phase 4 — the exact predicate the Admin verification queue reads
     * submissions through. Defined once here, as a query scope, rather
     * than duplicated as a ->whereNotNull('email_verified_at') inline
     * in that controller — see isEmailVerified() above for the same
     * check on an already-loaded model instance.
     */
    public function scopeEmailVerified($query)
    {
        return $query->whereNotNull('email_verified_at');
    }

    protected static function newFactory()
    {
        return \Database\Factories\UndergradRequestorProfileFactory::new();
    }
}