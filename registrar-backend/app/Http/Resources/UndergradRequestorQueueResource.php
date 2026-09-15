<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Undergrad Requestor Registration — Phase 4.
 *
 * One row of the Admin verification queue. Wraps a SystemUser with its
 * loaded undergradRequestorProfile / undergradRequestorVerification.
 *
 * Deliberately LEAN compared with UndergradRequestorVerificationDetailResource:
 * a list view needs enough to triage and sort, not the full PII record.
 * Date of birth, present address, phone, and the reason for
 * non-enrollment are intentionally absent here — they appear only when a
 * reviewer actually opens a specific submission, which is an action the
 * audit trail can attribute to a person. Paginating an entire cohort's
 * home addresses into one response is a data-exposure surface with no
 * corresponding review benefit.
 *
 * waiting_days is computed rather than left to the client: the queue's
 * whole purpose is SLA visibility, and two clients computing "how long
 * has this person been waiting" from raw timestamps is two chances to
 * get the timezone wrong.
 */
class UndergradRequestorQueueResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $profile      = $this->undergradRequestorProfile;
        $verification = $this->undergradRequestorVerification;

        return [
            'user_id'        => $this->user_id,
            'email'          => $this->email,
            'account_status' => $this->status,

            'full_name'      => $profile?->full_name,
            'student_number' => $profile?->student_number,
            'program'        => $profile?->program,
            'last_school_year_attended' => $profile?->last_school_year_attended,

            'submitted_at'      => $profile?->created_at?->toIso8601String(),
            'email_verified_at' => $profile?->email_verified_at?->toIso8601String(),
            'waiting_days'      => $profile?->created_at?->diffInDays(now()),

            // Present on decided rows only; null while pending. The
            // abandonment deadline is surfaced so a reviewer can see
            // which pending submissions are about to age out (D9) rather
            // than discovering it after the nightly sweep has run.
            'expires_at' => $this->pending_expires_at?->toIso8601String(),

            'verification' => $verification ? [
                'status'       => $verification->status?->value,
                'status_label' => $verification->status?->label(),
                'reviewed_at'  => $verification->reviewed_at?->toIso8601String(),
                'reviewed_by'  => $verification->reviewer?->email,
                // Both flags are advisory (D6) and named as such here so
                // no client can render them as a verdict. Full
                // interpretation text lives on the detail endpoint.
                'advisory_local_match_found' => (bool) $verification->local_match_found,
                'advisory_ogos_match_found'  => $verification->ogos_match_found,
            ] : null,
        ];
    }
}
