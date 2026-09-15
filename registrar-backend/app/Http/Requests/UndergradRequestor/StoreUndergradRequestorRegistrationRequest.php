<?php

namespace App\Http\Requests\UndergradRequestor;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Undergrad Requestor Registration — Phase 2, hardened in Phase 6.
 *
 * Public, unauthenticated endpoint — shape-only validation (format,
 * length, plausible DOB), no external verification at this stage. See
 * the implementation plan's Phase 2 goal: "no external verification at
 * this stage" — the actual local/OGOS advisory checks happen later, at
 * Admin review time (Phase 4).
 *
 * Phase 6 changes, all of them about this being the ONE unauthenticated
 * write endpoint in RIS:
 *
 *  - Data Privacy Act consent is now required and recorded (RA 10173).
 *  - Input is normalised before validation, not after, so the unique
 *    check, the per-email rate limiter and the eventual D4 email match
 *    all operate on the same canonical string.
 *  - Free-text fields reject control characters, and phone/student
 *    number are constrained to a character set rather than only a
 *    length. "max:2000 string" accepts 2000 bytes of anything; these
 *    values are rendered back into an Admin review screen and into
 *    emails, so the cheapest place to keep junk out is here.
 */
class StoreUndergradRequestorRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        // No prior authentication exists for this endpoint by design —
        // this IS the entry point that creates the account. Abuse
        // prevention is the named 'undergrad-requestor-register' rate
        // limiter (three buckets: per-IP/minute, per-IP/day and
        // per-email/day — see AppServiceProvider::boot()), plus the
        // email-confirmation gate (D10) that stops an unconfirmed
        // submission from ever reaching a reviewer.
        return true;
    }

    /**
     * Canonicalise before the rules run.
     *
     * Email lowercasing matters more than it looks: the per-email rate
     * limiter, the unique:users,email check here, the service's
     * defense-in-depth re-check, and — much later — the D4 match between
     * this row and the person's first IDP login all key on this string.
     * Normalising once, at the boundary, is what stops
     * "Juan@example.com" and "juan@example.com" from becoming two
     * accounts that can never be reconciled.
     */
    protected function prepareForValidation(): void
    {
        $trim = fn ($value) => is_string($value) ? trim($value) : $value;

        $this->merge(array_filter([
            'email'                      => is_string($this->input('email')) ? Str::lower(trim($this->input('email'))) : $this->input('email'),
            'first_name'                 => $trim($this->input('first_name')),
            'middle_name'                => $trim($this->input('middle_name')),
            'last_name'                  => $trim($this->input('last_name')),
            'suffix'                     => $trim($this->input('suffix')),
            'student_number'             => $trim($this->input('student_number')),
            'program'                    => $trim($this->input('program')),
            'last_school_year_attended'  => $trim($this->input('last_school_year_attended')),
            'present_address'            => $trim($this->input('present_address')),
            'reason_for_non_enrollment'  => $trim($this->input('reason_for_non_enrollment')),
            'phone'                      => $trim($this->input('phone')),
        ], fn ($value) => $value !== null));
    }

    public function rules(): array
    {
        // Rejects ASCII control characters (other than none at all) in
        // single-line fields. Newlines in a "first name" are never a
        // legitimate submission; they ARE a cheap way to try to break a
        // downstream renderer or an email header.
        $singleLine = 'regex:/^[^\x00-\x1F\x7F]+$/u';

        // Multi-line fields may contain newlines and tabs, nothing else
        // from the control range.
        $multiLine = 'regex:/^[^\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+$/u';

        return [
            // users.email is varchar(100) — see create_base_schema.
            // unique:users,email gives a fast, field-specific 422 for
            // the overwhelmingly common case; UndergradRequestorRegistrationService
            // ::register() re-checks this immediately before its own
            // INSERT as a defense-in-depth backstop against the narrow
            // TOCTOU race between this validation pass and that write.
            //
            // 'email:rfc', deliberately NOT 'email:rfc,dns'. The dns
            // variant issues a live DNS lookup during validation, which
            // on a public, unauthenticated endpoint means an attacker
            // chooses which domains our server resolves, and a slow or
            // unreachable resolver becomes a queue of held-open PHP
            // workers. The real deliverability check is the confirmation
            // email itself (D10) — an address that does not exist never
            // reaches the Admin queue, which is a stronger guarantee than
            // "its domain has an MX record" anyway.
            'email' => ['required', 'email:rfc', 'max:100', Rule::unique('users', 'email')],

            'first_name'  => ['required', 'string', 'max:100', $singleLine],
            'middle_name' => ['nullable', 'string', 'max:100', $singleLine],
            'last_name'   => ['required', 'string', 'max:100', $singleLine],
            'suffix'      => ['nullable', 'string', 'max:20', $singleLine],

            // Deliberately NOT unique — see the
            // create_undergrad_requestor_profiles_table migration's
            // docblock: a duplicate student number is a Phase 4 Admin
            // review flag, never a submission-blocking error. The
            // character class is intentionally permissive (PUP student
            // numbers are digits and hyphens, but this population spans
            // decades of formats) while still excluding whitespace and
            // punctuation that would only ever be a paste artefact.
            //
            // Case is NOT normalised here on purpose: users.email is the
            // identifier everything keys on, and MySQL's default
            // collation already makes the duplicate-student-number check
            // case-insensitive. Upper-casing would only matter on SQLite
            // (tests), where it would change stored values for no
            // production benefit.
            'student_number' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9\-]+$/'],

            'program'                    => ['required', 'string', 'max:255', $singleLine],
            'last_school_year_attended'  => ['required', 'string', 'max:20', $singleLine],

            // "Plausible DOB" (Phase 2 goal) — bounds only, no identity
            // verification at this stage. before:-14 years rules out an
            // implausibly recent birthdate for anyone claiming
            // undergraduate history; after:1900-01-01 rules out garbage
            // input at the other end. Registrar leadership should
            // confirm/adjust the exact lower-bound age if 14 doesn't
            // match policy.
            'date_of_birth' => ['required', 'date', 'after:1900-01-01', 'before:-14 years'],

            'present_address'            => ['required', 'string', 'max:2000', $multiLine],
            'reason_for_non_enrollment'  => ['nullable', 'string', 'max:2000', $multiLine],

            // Digits plus the punctuation real phone numbers are written
            // with (+, spaces, hyphens, parentheses). Note the column is
            // 20 chars, so this is a shape check, not a dialability one —
            // the Registrar contacts people by the email address they
            // confirmed, not by this number.
            'phone' => ['required', 'string', 'max:20', 'regex:/^[0-9+\-\s()]{7,20}$/'],

            // Phase 6 (RA 10173). 'accepted' means the value must be
            // present AND truthy ("yes"/"on"/1/true) — an unchecked box
            // that submits nothing, or a literal false, both fail. The
            // timestamp, notice version and IP actually stored are all
            // derived server-side in the registration service; the
            // client is trusted for exactly one bit of information here,
            // which is the only part it can legitimately know.
            'data_privacy_consent' => ['required', 'accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique'         => 'This email is already associated with an account.',
            'email.email'          => 'Please enter a valid email address.',
            'date_of_birth.before' => 'Please enter a valid date of birth.',
            'student_number.regex' => 'Please enter your student number using letters, numbers and hyphens only.',
            'phone.regex'          => 'Please enter a valid contact number.',

            'data_privacy_consent.required' => 'You must agree to the Data Privacy Notice before submitting this form.',
            'data_privacy_consent.accepted' => 'You must agree to the Data Privacy Notice before submitting this form.',

            'first_name.regex'      => 'Please remove any line breaks or special characters from this field.',
            'middle_name.regex'     => 'Please remove any line breaks or special characters from this field.',
            'last_name.regex'       => 'Please remove any line breaks or special characters from this field.',
            'suffix.regex'          => 'Please remove any line breaks or special characters from this field.',
            'program.regex'         => 'Please remove any line breaks or special characters from this field.',
            'last_school_year_attended.regex' => 'Please remove any line breaks or special characters from this field.',
            'present_address.regex' => 'Please remove any special characters from this field.',
            'reason_for_non_enrollment.regex' => 'Please remove any special characters from this field.',
        ];
    }
}