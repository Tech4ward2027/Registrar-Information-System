<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;

/**
 * Support Ticketing — Phase 4. "Confirm your email" for a public submission.
 * No ticket exists yet. Queued, like the undergrad verification mail, so a
 * slow mail transport never holds up the HTTP response. Carries only scalars
 * (no model), so nothing but what the email shows is serialized to the queue.
 */
class TicketPublicVerificationMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $name,
        public string $confirmUrl,
        public int $ttlMinutes,
    ) {}

    public function build(): self
    {
        return $this
            ->subject('Confirm your email — PUPT Registrar Support')
            ->markdown('mail.tickets.public-verification', [
                'name'       => $this->name,
                'confirmUrl' => $this->confirmUrl,
                'ttlMinutes' => $this->ttlMinutes,
            ]);
    }
}
