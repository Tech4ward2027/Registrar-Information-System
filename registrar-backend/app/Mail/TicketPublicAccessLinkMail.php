<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;

/**
 * Support Ticketing — Phase 4. The magic link to follow a public ticket: sent
 * when the ticket is created and again whenever "resend link" is used.
 */
class TicketPublicAccessLinkMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $ticketNumber,
        public string $trackUrl,
        public int $graceDays,
    ) {}

    public function build(): self
    {
        return $this
            ->subject("Your support ticket {$this->ticketNumber} — PUPT Registrar")
            ->markdown('mail.tickets.public-access-link', [
                'ticketNumber' => $this->ticketNumber,
                'trackUrl'     => $this->trackUrl,
                'graceDays'    => $this->graceDays,
            ]);
    }
}
