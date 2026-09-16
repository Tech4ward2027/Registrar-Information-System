<?php

namespace App\Http\Controllers;

use App\Contracts\UndergradRequestorRegistrationServiceInterface;
use App\Http\Requests\UndergradRequestor\ConfirmUndergradRequestorEmailRequest;
use App\Http\Requests\UndergradRequestor\StoreUndergradRequestorRegistrationRequest;
use App\Http\Resources\UndergradRequestorRegistrationResource;

/**
 * Undergrad Requestor Registration — Phase 2, extended in Phase 6.
 *
 * Every action here is public/unauthenticated (see routes/api.php) — no
 * $this->authorize() calls, no policy class. Abuse prevention is the
 * named rate limiters registered in AppServiceProvider::boot(), which
 * bucket by IP *and* by submitted email and record every tripped limit
 * to security_events.
 */
class UndergradRequestorController extends Controller
{
    public function __construct(
        private UndergradRequestorRegistrationServiceInterface $registrationService,
    ) {}

    /**
     * POST /api/undergrad-requestors/register
     */
    public function register(StoreUndergradRequestorRegistrationRequest $request)
    {
        $profile = $this->registrationService->register($request->validated(), $request);

        return (new UndergradRequestorRegistrationResource($profile->load('user')))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * POST /api/undergrad-requestors/confirm-email
     */
    public function confirmEmail(ConfirmUndergradRequestorEmailRequest $request)
    {
        $profile = $this->registrationService->confirmEmail(
            $request->validated('email'),
            $request->validated('token'),
            $request,
        );

        return new UndergradRequestorRegistrationResource($profile->load('user'));
    }

    /**
     * GET /api/undergrad-requestors/registration-notice
     *
     * Phase 6 (RA 10173, Data Privacy Act of 2012).
     *
     * Serves the privacy notice the onboarding form must display,
     * together with the version identifier that will be recorded against
     * any submission made after it (undergrad_requestor_profiles
     * .data_privacy_consent_version).
     *
     * Why an endpoint rather than a string in the SPA: the value of a
     * consent record is that it points at the exact text the person
     * agreed to. If the wording lives in the frontend and the version
     * lives in backend config, the first copy edit that ships without a
     * matching version bump silently invalidates every consent record
     * taken afterwards — and nobody finds out until somebody asks to see
     * the evidence. One source, one deploy artefact, no drift.
     *
     * No authentication (the form is public), no PII in or out, and
     * nothing here is user-specific, so this is a pure config read.
     */
    public function registrationNotice()
    {
        return response()->json([
            'data' => [
                'consent_version' => (string) config('undergrad_requestor.data_privacy.consent_version'),
                'notice'          => (string) config('undergrad_requestor.data_privacy.notice'),
                // Optional — set UNDERGRAD_REQUESTOR_PRIVACY_NOTICE_URL
                // when the University publishes the full privacy policy
                // separately, so the form can link out to it alongside
                // the inline summary. Null is a valid, expected state.
                'notice_url'      => config('undergrad_requestor.data_privacy.notice_url'),

                // Surfaced so the form can tell people, in the same
                // breath as asking for consent, how long their data is
                // kept — which is one of the disclosures the Act
                // actually requires, and which would otherwise sit only
                // in config where no requestor can see it. Read from the
                // same keys the two retention sweeps read, so the
                // promise made on the form and the behaviour of the jobs
                // cannot diverge.
                'retention' => [
                    'unverified_submission_days' => (int) config('undergrad_requestor.abandonment_days'),
                    'rejected_record_days'       => (int) config('undergrad_requestor.rejected_retention_days'),
                ],
            ],
        ]);
    }
}