<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

/**
 * The seven System Mailer emails of Notification Policy Section 3.2.
 *
 * One class, one template: the emails share a layout and differ only in
 * wording, so the wording lives in a single match() and cannot drift between
 * files. Not ShouldQueue on purpose: it is rendered and sent inside
 * SendLifecycleMailJob, which already owns queueing, throttling and retries.
 *
 * Carries only what the policy wording names (document name, item label,
 * reason). No request id, OR number or personal data: email is not a private
 * channel, and the requestor signs in to RIS for the full record.
 */
class RequestLifecycleMail extends Mailable
{
    public function __construct(
        public readonly string $trigger,
        public readonly string $documentName,
        public readonly bool $plural = false,
        public readonly ?string $itemLabel = null,
        public readonly ?string $reason = null,
        // Policy §3.6: set only when the email goes to a verified
        // representative of a deceased or incapacitated requestor.
        public readonly ?string $studentName = null,
    ) {}

    public function build(): self
    {
        [$subject, $headline, $lines] = $this->copy();

        return $this->subject($subject)->markdown('mail.lifecycle.notice', [
            'headline' => $headline,
            'lines'    => $lines,
            // A representative has no RIS account to sign in to.
            'showSignIn' => $this->studentName === null,
        ]);
    }

    /** @return array{0:string,1:string,2:list<string>} */
    private function copy(): array
    {
        $doc    = $this->documentName;
        $your = $this->plural ? 'Your documents' : 'Your document';
        $reason = rtrim((string) $this->reason, ". \t\n\r") ?: 'no reason was recorded';
        $item   = rtrim((string) $this->itemLabel, ". \t\n\r");

        return match ($this->trigger) {
            'ready_to_claim' => [
                "{$your} " . ($this->plural ? 'are' : 'is') . ' ready to claim — PUPT Registrar',
                'Ready to claim',
                ["{$your} {$doc} " . ($this->plural ? 'are' : 'is') . ' ready to claim! Present your QR/claim code with your physical OR at the counter.'],
            ],
            'reminder_claim' => [
                'Reminder: unclaimed document — PUPT Registrar',
                'Reminder: ready to claim for 7 days',
                ["Reminder: {$your} {$doc} " . ($this->plural ? 'have' : 'has') . ' been ready to claim for 7 days. Please claim ' . ($this->plural ? 'them' : 'it') . " at the Registrar's Office."],
            ],
            'reminder_final_warning' => [
                'Final notice: forfeiture tomorrow — PUPT Registrar',
                'Final notice',
                ["Final Notice: {$your} {$doc} will be forfeited and shredded tomorrow per the 90-day unclaimed policy."],
            ],
            'awaiting_submission' => [
                'Additional requirements needed — PUPT Registrar',
                'Additional requirements needed',
                ["Your request for {$doc} requires additional source requirements. Please visit the Registrar's Office to submit them."],
            ],
            'deficiency_notice_issued' => [
                'Deficiency notice on your request — PUPT Registrar',
                'Deficiency notice issued',
                ["A Deficiency Notice has been issued on your request for {$doc}: {$item}. Please review and submit this item."],
            ],
            'request_withdrawn' => [
                'Your request was withdrawn — PUPT Registrar',
                'Request withdrawn',
                ["Your request for {$doc} has been withdrawn: {$reason}. Contact the Registrar's Office if you have questions."],
            ],
            'request_closed_unable_to_process' => $this->studentName !== null ? [
                // Policy §3.6 wording, for the representative.
                'A request could not be completed — PUPT Registrar',
                'Request could not be completed',
                ["We regret to inform you that the request filed for {$this->studentName} could not be completed: {$reason}. Please contact the Registrar's Office for assistance regarding records and accounts."],
            ] : [
                'Your request could not be completed — PUPT Registrar',
                'Request could not be completed',
                ["We regret to inform you that the request for {$doc} could not be completed: {$reason}."],
            ],
            default => throw new \InvalidArgumentException("No email copy for trigger '{$this->trigger}'."),
        };
    }
}