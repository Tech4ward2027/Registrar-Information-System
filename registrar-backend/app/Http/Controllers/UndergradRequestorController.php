<?php

namespace App\Http\Controllers;

use App\Contracts\UndergradRequestorRegistrationServiceInterface;
use App\Http\Requests\UndergradRequestor\ConfirmUndergradRequestorEmailRequest;
use App\Http\Requests\UndergradRequestor\StoreUndergradRequestorRegistrationRequest;
use App\Http\Resources\UndergradRequestorRegistrationResource;

/**
 * Undergrad Requestor Registration — Phase 2.
 *
 * Both actions are public/unauthenticated (see routes/api.php) — no
 * $this->authorize() calls, no policy class. Throttled at the route
 * level; Phase 6 will layer additional per-email abuse prevention.
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
}
