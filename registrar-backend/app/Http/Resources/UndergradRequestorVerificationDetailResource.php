<?php

namespace App\Http\Resources;

use App\Models\SystemUser;
use App\Models\UndergradRequestorVerification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Undergrad Requestor Registration — Phase 4.
 *
 * The full review record for one submission: the self-declared profile,
 * the verification record, and both advisory checks with their
 * plain-language interpretations.
 *
 * Constructed from the array UndergradRequestorVerificationService::
 * reviewDetail() returns, rather than wrapping a single model, because
 * the hints are computed at review time and have no model of their own.
 *
 * ── The advisory framing is part of the contract ──────────────────────
 * Every hint arrives pre-labelled with what it does and does not mean
 * (see the service's buildHints()). That wording is carried through to
 * the client verbatim and is NOT the UI's to invent: the single most
 * likely failure mode of this whole feature is a reviewer reading "no
 * OGOS match" as a clearance rather than as "OGOS does not track this
 * population at all." Phase 8's training material covers the same point
 * for humans; shipping it in the payload means a UI rewrite cannot
 * silently drop it.
 */
class UndergradRequestorVerificationDetailResource extends JsonResource
{
    /**
     * BUG FIX: without this, Laravel's default JsonResource behavior
     * wraps toArray()'s output under a top-level "data" key, so the
     * real response shape was:
     *   { "data": { "advisory_checks": { "local_records_check": {...} } } }
     * while every consumer of this resource (the controller's tests,
     * and any future client) expects the fields at the response root,
     * e.g. "advisory_checks.local_records_check.match_found".
     *
     * Contrast with UndergradRequestorQueueResource::collection(...),
     * which IS meant to be wrapped under "data" (it's a list) and
     * whose tests correctly assert against "data.0.user_id" — this
     * resource is a single-record detail view and was never meant to
     * carry that wrapper.
     */
    public static $wrap = null;

    /**
     * @param  array{user: SystemUser, verification: UndergradRequestorVerification, hints: array<string, mixed>}  $resource
     */
    public function __construct($resource)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        /** @var SystemUser $user */
        $user = $this->resource['user'];
        /** @var UndergradRequestorVerification $verification */
        $verification = $this->resource['verification'];
        $profile      = $user->undergradRequestorProfile;

        return [
            'account' => [
                'user_id'            => $user->user_id,
                'email'              => $user->email,
                'status'             => $user->status,
                // NULL once a decision has been made — see the service's
                // approve()/reject(), which clear the abandonment window
                // the moment a submission is actioned.
                'expires_at'         => $user->pending_expires_at?->toIso8601String(),
                // Confirms D3/D4 at a glance for anyone auditing the UI:
                // this account has no local password and is not linked to
                // an IdP identity until its first real SSO login.
                'idp_linked'         => $user->idp_user_id !== null,
            ],

            // Self-declared and unverified by definition (D5). Labelled
            // as such in the payload so the UI has no excuse to present
            // it with the same visual weight as an OGOS-sourced record.
            'declared_profile' => [
                'source'    => 'self-declared, unverified',
                'full_name' => $profile?->full_name,
                'first_name'  => $profile?->first_name,
                'middle_name' => $profile?->middle_name,
                'last_name'   => $profile?->last_name,
                'suffix'      => $profile?->suffix,
                'student_number' => $profile?->student_number,
                'program'        => $profile?->program,
                'last_school_year_attended' => $profile?->last_school_year_attended,
                'date_of_birth'  => $profile?->date_of_birth?->toDateString(),
                'present_address' => $profile?->present_address,
                'reason_for_non_enrollment' => $profile?->reason_for_non_enrollment,
                'phone'             => $profile?->phone,
                'submitted_at'      => $profile?->created_at?->toIso8601String(),
                'email_verified_at' => $profile?->email_verified_at?->toIso8601String(),
            ],

            // Phase 6 (RA 10173). Shown to the reviewer, not buried in
            // the database, for one reason: 'recorded' => false means we
            // have no evidence this person was ever shown the privacy
            // notice, and that is something a human should see BEFORE
            // approving an account built on data we may not have been
            // entitled to collect. It can only be false for submissions
            // predating the consent columns — the onboarding form has
            // required consent since — which is exactly the population
            // worth flagging rather than defaulting away.
            //
            // 'version' is the notice text the person actually agreed
            // to, which is not necessarily the one currently in config.
            'data_privacy_consent' => [
                'recorded'   => (bool) $profile?->hasRecordedDataPrivacyConsent(),
                'consent_at' => $profile?->data_privacy_consent_at?->toIso8601String(),
                'version'    => $profile?->data_privacy_consent_version,
                'ip_address' => $profile?->data_privacy_consent_ip,
            ],

            'verification' => [
                'id'               => $verification->undergrad_requestor_verification_id,
                'status'           => $verification->status?->value,
                'status_label'     => $verification->status?->label(),
                'reviewed_at'      => $verification->reviewed_at?->toIso8601String(),
                'reviewed_by'      => $verification->reviewer?->email,
                'rejection_reason' => $verification->rejection_reason,
                // Non-null only after the 90-day purge has disposed of
                // this record's personal data (D9). Present so an
                // auditor can see disposal happened without inferring it
                // from missing fields.
                'pii_purged_at'    => $verification->pii_purged_at?->toIso8601String(),
            ],

            // Advisory only — never a recommendation, never a score.
            'advisory_checks' => $this->resource['hints'],

            'decision_guidance' => 'No automated check approves or rejects this submission. The reviewing '
                . 'Admin\'s decision is final (D6). Rejection requires a reason.',
        ];
    }
}