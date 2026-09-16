<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Undergrad Requestor Registration — Phase 4.
 *
 * The Registrar's decision on an Undergrad Requestor submission,
 * delivered by email.
 *
 * ── Why email, when an in-app notification already fires ──────────────
 * A decided requestor may have no way to read an in-app notification.
 * An Approved one has not logged into RIS yet by definition (approval is
 * what unlocks their first login), and a Rejected one now never can —
 * UserProvisioningService raises AccountRejectedException and revokes
 * the IdP token before a session is ever issued (D8). Email is the only
 * channel that reaches both.
 *
 * ── Why one Mailable with a flag, not two classes ─────────────────────
 * Approval and rejection are two outcomes of one event, sent to the same
 * recipient, from the same trigger, with the same envelope and footer.
 * Splitting them would duplicate everything except a heading and one
 * paragraph, and would make it possible for the two to drift in tone or
 * accuracy independently.
 *
 * ── What is deliberately NOT in here ──────────────────────────────────
 * No student number, date of birth, address, or phone number. The
 * recipient already knows their own submitted data, and an email is an
 * unencrypted channel that may sit in a mailbox indefinitely — echoing
 * PII back adds disclosure risk with no benefit. The rejection reason IS
 * included, since the person cannot act on a decision they cannot see
 * the grounds for, and it is authored by a Registrar Admin for exactly
 * this audience.
 *
 * ShouldQueue for the same reason as UndergradRequestorEmailVerificationMail:
 * a slow or unreachable mail transport must never hold up — or fail — an
 * already-committed, already-audited Registrar decision.
 */
class UndergradRequestorDecisionMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string  $firstName,
        public bool    $approved,
        public ?string $rejectionReason = null,
    ) {}

    public function build(): self
    {
        return $this
            ->subject($this->approved
                ? 'Your Undergrad Requestor registration has been approved'
                : 'Update on your Undergrad Requestor registration')
            ->markdown('mail.undergrad-requestor.decision', [
                'firstName'       => $this->firstName,
                'approved'        => $this->approved,
                'rejectionReason' => $this->rejectionReason,
                // config('sso.base_url') is the IdP RIS already
                // authenticates against — reused here rather than a
                // second, separately-configurable "where do I sign up"
                // URL that could silently drift from it. Empty in
                // environments where it is unset; the view degrades to
                // prose with no link rather than rendering a broken one.
                'idpUrl'          => rtrim((string) config('sso.base_url', ''), '/'),
            ]);
    }
}
