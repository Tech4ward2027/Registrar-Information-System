<?php

namespace App\Enums;

/**
 * Support Ticketing — Phase 6. Everything a ticket can notify someone about.
 *
 * Each case maps to one notification_types row (trigger_event `ticket_<value>`,
 * seeded by TicketNotificationTypeSeeder) and to one audience:
 *
 *   Requester kinds  go to whoever filed the ticket: in-app bell + email for a
 *                    signed-in requester, email only for a public submitter
 *                    (who has no account and so no bell).
 *   Staff kinds      go to staff, in-app bell only (no email: staff work in the
 *                    queue, and a mailbox full of automated mail trains people
 *                    to ignore it).
 */
enum TicketNotificationKindEnum: string
{
    // → the requester
    case StaffReply          = 'staff_reply';          // a staff reply
    case Question            = 'question';             // a staff reply that waits for an answer (Pending Client)
    case Resolved            = 'resolved';             // marked Resolved: confirm, or "this isn't resolved"
    case ResolutionReminder  = 'resolution_reminder';  // day-2 reminder (sent by the Phase 7 auto-close command)
    case Closed              = 'closed';               // closed automatically (Phase 7)

    // → staff
    case RequesterReply = 'requester_reply'; // the requester wrote back
    case Returned       = 'returned';        // "this isn't resolved" (or a reply that did the same)
    case SlaAtRisk      = 'sla_at_risk';
    case SlaBreached    = 'sla_breached';
    case ClaimExpired   = 'claim_expired';   // a claim was released for inactivity

    /** The notification_types.trigger_event this kind sends through NotificationService. */
    public function triggerEvent(): string
    {
        return 'ticket_' . $this->value;
    }

    /** True when the recipient is the person who filed the ticket. */
    public function isForRequester(): bool
    {
        return match ($this) {
            self::StaffReply, self::Question, self::Resolved, self::ResolutionReminder, self::Closed => true,
            default => false,
        };
    }

    /** Only requester-facing kinds are emailed. */
    public function sendsEmail(): bool
    {
        return $this->isForRequester();
    }
}
