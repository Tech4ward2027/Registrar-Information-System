<?php

namespace App\Http\Requests\UndergradRequestor;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Undergrad Requestor Registration — Phase 2.
 *
 * Public, unauthenticated endpoint — shape-only validation (format,
 * length, plausible DOB), no external verification at this stage. See
 * the implementation plan's Phase 2 goal: "no external verification at
 * this stage" — the actual local/OGOS advisory checks happen later, at
 * Admin review time (Phase 4).
 */
class StoreUndergradRequestorRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        // No prior authentication exists for this endpoint by design —
        // this IS the entry point that creates the account. Abuse
        // prevention is handled by the route's throttle middleware (see
        // routes/api.php) and, per Phase 6, will be hardened further
        // with a dedicated per-email bucket.
        return true;
    }

    public function rules(): array
    {
        return [
            // users.email is varchar(100) — see create_base_schema.
            // unique:users,email gives a fast, field-specific 422 for
            // the overwhelmingly common case; UndergradRequestorRegistrationService
            // ::register() re-checks this immediately before its own
            // INSERT as a defense-in-depth backstop against the narrow
            // TOCTOU race between this validation pass and that write.
            'email' => 'required|email|max:100|unique:users,email',

            'first_name'  => 'required|string|max:100',
            'middle_name' => 'nullable|string|max:100',
            'last_name'   => 'required|string|max:100',
            'suffix'      => 'nullable|string|max:20',

            // Deliberately NOT unique — see the
            // create_undergrad_requestor_profiles_table migration's
            // docblock: a duplicate student number is a Phase 4 Admin
            // review flag, never a submission-blocking error.
            'student_number' => 'required|string|max:50',

            'program'                    => 'required|string|max:255',
            'last_school_year_attended'  => 'required|string|max:20',

            // "Plausible DOB" (Phase 2 goal) — bounds only, no identity
            // verification at this stage. before:-14 years rules out an
            // implausibly recent birthdate for anyone claiming
            // undergraduate history; after:1900-01-01 rules out garbage
            // input at the other end. Registrar leadership should
            // confirm/adjust the exact lower-bound age if 14 doesn't
            // match policy.
            'date_of_birth' => 'required|date|after:1900-01-01|before:-14 years',

            'present_address'            => 'required|string|max:2000',
            'reason_for_non_enrollment'  => 'nullable|string|max:2000',
            'phone'                       => 'required|string|max:20',
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique'       => 'This email is already associated with an account.',
            'date_of_birth.before' => 'Please enter a valid date of birth.',
        ];
    }
}
