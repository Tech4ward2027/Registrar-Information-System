<?php

namespace App\Services\Mail;

/**
 * The only emails the System Mailer may ever send (Notification Policy v1.2.0).
 *
 * This is the code-level allow-list. notification_types.send_email is the
 * operational switch, but a type can only be switched on in practice if it is
 * listed here too, so a stray database edit cannot widen what the system
 * emails. Adding an email means adding a trigger here, in a reviewed commit.
 */
final class MailPolicy
{
    /** The seven requestor emails in Section 3.2 of the policy. */
    public const LIFECYCLE_TRIGGERS = [
        'ready_to_claim',
        'reminder_claim',
        'reminder_final_warning',
        'awaiting_submission',
        'deficiency_notice_issued',
        'request_withdrawn',
        'request_closed_unable_to_process',
    ];

    /**
     * Approved policy exceptions. These carry a verification or access link
     * and are sent directly by their own services, not by NotificationService.
     */
    public const APPROVED_EXCEPTIONS = [
        'undergrad_email_verification',
        'undergrad_requestor_decision',
        'ticket_notification',
        'ticket_public_verification',
        'ticket_public_access_link',
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return [...self::LIFECYCLE_TRIGGERS, ...self::APPROVED_EXCEPTIONS];
    }

    public static function isApproved(string $trigger): bool
    {
        return in_array($trigger, self::all(), true);
    }

    public static function isLifecycle(string $trigger): bool
    {
        return in_array($trigger, self::LIFECYCLE_TRIGGERS, true);
    }
}
