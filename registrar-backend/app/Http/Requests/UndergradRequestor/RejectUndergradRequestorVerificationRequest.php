<?php

namespace App\Http\Requests\UndergradRequestor;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Undergrad Requestor Registration — Phase 4.
 *
 * A rejection reason is MANDATORY (not merely encouraged). Three things
 * depend on it and none of them can be reconstructed later:
 *
 *   - The requestor. A rejection they cannot see the grounds for is a
 *     dead end — they cannot correct a mistake or decide whether to
 *     contest it. It is included verbatim in UndergradRequestorDecisionMail.
 *   - The next reviewer. Phase 8's runbook covers support cases where a
 *     rejected person contacts the Registrar; "Rejected, no reason
 *     recorded" makes that conversation unanswerable.
 *   - The audit trail. The reason is written into the tamper-evident
 *     audit_logs entry, which survives the 90-day PII purge that later
 *     clears the reason from undergrad_requestor_verifications (D9).
 *
 * min:10 rather than a bare `required`: a single character satisfies
 * "required" and defeats all three purposes above. Deliberately a floor
 * on effort, not a quality check — nothing here can tell a good reason
 * from a bad one.
 */
class RejectUndergradRequestorVerificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route middleware ('role:3,4' + 'module:undergrad_verification,Reject')
        // already gates this — see routes/api.php.
        return true;
    }

    public function rules(): array
    {
        return [
            // max:1000 matches RejectAccessRequestRequest's ceiling and
            // fits comfortably inside the column's TEXT type.
            'rejection_reason' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'rejection_reason.required' => 'A rejection reason is required — it is sent to the requestor and '
                . 'recorded in the audit trail.',
            'rejection_reason.min'      => 'Please give a reason the requestor can actually act on '
                . '(at least 10 characters).',
        ];
    }
}
