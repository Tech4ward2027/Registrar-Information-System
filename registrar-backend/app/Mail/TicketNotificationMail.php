<?php

namespace App\Mail;

use App\Enums\TicketNotificationKindEnum;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;

/**
 * Support Ticketing — Phase 6. The one email a requester gets when something
 * happens on their ticket. Wording varies by kind; the layout does not.
 *
 * Deliberately carries NO message text: it says that something happened and
 * links to where it can be read (the sign-in page for an account holder, the
 * magic link for a public submitter). Email is not a private channel.
 */
class TicketNotificationMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $kind,
        public string $ticketNumber,
        public string $url,
        public bool $isPublic,
        public ?string $closeDate = null,
    ) {
    }

    public function build(): self
    {
        [$subject, $headline, $lines] = $this->copy();

        return $this
            ->subject($subject)
            ->markdown('mail.tickets.notification', [
                'headline'     => $headline,
                'lines'        => $lines,
                'url'          => $this->url,
                'isPublic'     => $this->isPublic,
                'ticketNumber' => $this->ticketNumber,
            ]);
    }

    /** @return array{0:string,1:string,2:list<string>} subject, headline, body lines */
    private function copy(): array
    {
        $n = $this->ticketNumber;

        return match (TicketNotificationKindEnum::tryFrom($this->kind)) {
            TicketNotificationKindEnum::Question => [
                "We need more information on ticket {$n} — PUPT Registrar",
                "We need more information on {$n}",
                ['The Registrar\'s Office asked you a question and is waiting for your answer before it can continue.'],
            ],
            TicketNotificationKindEnum::Resolved => [
                "Ticket {$n} was marked resolved — PUPT Registrar",
                "{$n} was marked resolved",
                [
                    'Please check the answer and confirm it, or tell us it isn\'t resolved.',
                    $this->closeDate !== null
                        ? "If we don't hear from you, the ticket closes automatically on {$this->closeDate}."
                        : 'If we don\'t hear from you, the ticket closes automatically.',
                ],
            ],
            TicketNotificationKindEnum::ResolutionReminder => [
                "Ticket {$n} closes soon — PUPT Registrar",
                "{$n} closes soon",
                [
                    $this->closeDate !== null
                        ? "Your resolved ticket closes automatically on {$this->closeDate}."
                        : 'Your resolved ticket closes automatically soon.',
                    'If the problem is not solved, reply or tell us it isn\'t resolved before then.',
                ],
            ],
            TicketNotificationKindEnum::Closed => [
                "Ticket {$n} was closed — PUPT Registrar",
                "{$n} was closed",
                ['Your ticket is now closed. If you have a new problem, please file a new ticket.'],
            ],
            default => [ // StaffReply, and any future requester kind
                "New reply on ticket {$n} — PUPT Registrar",
                "New reply on {$n}",
                ['The Registrar\'s Office replied to your ticket.'],
            ],
        };
    }
}
