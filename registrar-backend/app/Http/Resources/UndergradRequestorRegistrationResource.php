<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Undergrad Requestor Registration — Phase 2.
 *
 * Response shape for both the public register() and confirmEmail()
 * endpoints. This is a public, unauthenticated surface — deliberately
 * echoes back only the caller's own just-submitted data (never another
 * account's), and never includes email_verification_token_hash (hidden
 * on the model regardless — see UndergradRequestorProfile::$hidden —
 * this is a second, explicit layer since resources don't inherit
 * $hidden automatically).
 */
class UndergradRequestorRegistrationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'full_name'          => $this->full_name,
            'email'               => $this->user?->email,
            'student_number'      => $this->student_number,
            'status'              => $this->user?->status,
            'email_verified'      => $this->isEmailVerified(),
            'submitted_at'        => $this->created_at?->toIso8601String(),
        ];
    }
}
