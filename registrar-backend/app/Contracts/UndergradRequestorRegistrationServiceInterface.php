<?php

namespace App\Contracts;

use App\Models\SystemUser;
use App\Models\UndergradRequestorProfile;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Undergrad Requestor Registration — Phase 2.
 *
 * Contract for the public self-service onboarding flow. Bound in
 * AppServiceProvider — same "callers depend on the interface, not the
 * concrete class" convention as DocumentRequestServiceInterface.
 */
interface UndergradRequestorRegistrationServiceInterface
{
    /**
     * Public, unauthenticated onboarding submission (D4). In a single
     * transaction: creates the users row (role_id =
     * ROLE_UNDERGRAD_REQUESTOR, status = 'Pending Verification',
     * idp_user_id = NULL, no password), the
     * undergrad_requestor_profiles row, and the
     * undergrad_requestor_verifications row (status = Pending).
     *
     * Sends the email-confirmation link AFTER the transaction commits
     * (never inside it — a slow/unreachable mail transport must not
     * hold the row lock or roll back an otherwise-successful
     * submission; same placement rule AdminUserService/
     * AccessRequestService already follow for their own
     * outside-the-transaction side effects).
     *
     * @param array{
     *     email: string,
     *     first_name: string,
     *     middle_name?: string|null,
     *     last_name: string,
     *     suffix?: string|null,
     *     student_number: string,
     *     program: string,
     *     last_school_year_attended: string,
     *     date_of_birth: string,
     *     present_address: string,
     *     reason_for_non_enrollment?: string|null,
     *     phone: string,
     * } $validated
     * @throws ValidationException if the email is already associated
     *         with any SystemUser account (defense-in-depth on top of
     *         the request-level unique:users,email rule — see
     *         StoreUndergradRequestorRegistrationRequest).
     */
    public function register(array $validated, Request $request): UndergradRequestorProfile;

    /**
     * Confirms the onboarding email via the token emailed by
     * register() (D10). Sets email_verified_at, clears the token/expiry
     * pair, and is the sole gate that makes a submission visible to the
     * Admin verification queue (Phase 4) — see
     * UndergradRequestorProfile::scopeEmailVerified().
     *
     * @throws ValidationException if no Pending-Verification Undergrad
     *         Requestor account matches $email, the token doesn't
     *         match, the token has expired, or the email was already
     *         confirmed.
     */
    public function confirmEmail(string $email, string $token, Request $request): UndergradRequestorProfile;
}
