<?php

namespace App\Mail;

use App\Models\UndergradRequestorProfile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Undergrad Requestor Registration — Phase 2 (D10).
 *
 * Sent immediately after a successful public onboarding submission
 * (UndergradRequestorRegistrationService::register()). Implements
 * ShouldQueue so a slow/unreachable mail transport can never hold up
 * the registration HTTP response — Laravel automatically defers
 * delivery to the queue worker for any Mailable implementing this
 * interface, same QUEUE_CONNECTION=database worker this codebase
 * already runs for its other background jobs (see
 * EnrichCashierFailureJob).
 *
 * Carries the plaintext token and the fully-built frontend link — never
 * the profile's persisted email_verification_token_hash. See the
 * add_email_verification_columns_to_undergrad_requestor_profiles
 * migration's docblock for why only the hash is ever stored.
 */
class UndergradRequestorEmailVerificationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public UndergradRequestorProfile $profile,
        public string $verificationUrl,
    ) {}

    public function build(): self
    {
        return $this
            ->subject('Confirm your email — PUPT Registrar Undergrad Requestor Registration')
            ->markdown('mail.undergrad-requestor.verify-email', [
                'firstName'       => $this->profile->first_name,
                'verificationUrl' => $this->verificationUrl,
            ]);
    }
}
