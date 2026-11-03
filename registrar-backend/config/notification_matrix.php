<?php

/*
|--------------------------------------------------------------------------
| Notification Matrix — Policy §3.4 (v1.2.0)
|--------------------------------------------------------------------------
| The single source of truth for which channels each notification uses and
| what it says. NotificationMatrixSync applies it to notification_types
| (from a migration and from the seeders, so fresh and existing databases
| end up identical) and tests/Feature/NotificationMatrixTest fails if the
| database ever drifts from it.
|
| To change policy: edit this file, ship a migration that calls
| NotificationMatrixSync::apply(), and update the policy document.
|
| Keys are notification_types.trigger_event.
|   email     System Mailer. Must agree with MailPolicy::LIFECYCLE_TRIGGERS;
|             the test enforces it, so email can never be widened by editing
|             this file alone.
|   push      Web push. The in-app bell and inbox is YES for every row, so it
|             is not repeated here. Lock-screen push text is generic by
|             default (WEBPUSH_INCLUDE_MESSAGE=false): notification previews
|             are visible to anyone nearby, so the full wording stays in the
|             inbox and the email.
|   template  Standard message. :tokens are filled by NotificationService
|             from the caller's data, plus NotificationPlaceholders for
|             :document_name, :or_number and :announcement_details.
|
| Placeholder names map to the policy's bracketed names as follows:
|   :withdrawal_reason  = [reason] (Request Withdrawn)
|   :closure_reason     = [reason] (Request Closed)
|   :announcement_details = [announcement_details]
|--------------------------------------------------------------------------
*/

return [

    'placeholders' => [
        'request_id', 'document_name', 'document_noun', 'or_number', 'item_label',
        'withdrawal_reason', 'closure_reason', 'void_reason',
        'announcement_title', 'announcement_details', 'email', 'ip',
    ],

    'types' => [
        // Lifecycle milestones
        'ready_to_claim' => [
            'email' => true, 'push' => true,
            'template' => 'Your document :document_name is ready to claim! Present your QR/claim code with your physical OR at the counter.',
        ],
        'reminder_claim' => [
            'email' => true, 'push' => true,
            'template' => "Reminder: Your document :document_name has been ready to claim for 7 days. Please claim it at the Registrar's Office.",
        ],
        'reminder_final_warning' => [
            'email' => true, 'push' => true,
            // Policy text says "today"; the Day 89 / Day 90 decision makes it "tomorrow".
            'template' => 'Final Notice: Your :document_noun :document_name will be forfeited and shredded tomorrow per the 90-day unclaimed policy.',
        ],

        // Requirements
        'awaiting_submission' => [
            'email' => true, 'push' => true,
            'template' => "Your request for :document_name requires additional source requirements. Please visit the Registrar's Office to submit them.",
        ],

        // Deficiency
        'deficiency_notice_issued' => [
            'email' => true, 'push' => true,
            'template' => 'A Deficiency Notice has been issued on your request for :document_name: :item_label. Please review and submit this item.',
        ],
        'deficiency_notice_cleared' => [
            'email' => false, 'push' => true,
            'template' => 'Submitted deficiency item for :document_name verified. Your request has resumed processing.',
        ],
        'deficiency_notice_voided' => [
            'email' => false, 'push' => true,
            'template' => "Deficiency notice voided: :void_reason. Please check with the Registrar's Office.",
        ],

        // Closure
        'request_withdrawn' => [
            'email' => true, 'push' => true,
            'template' => "Your request for :document_name has been withdrawn: :withdrawal_reason. Contact the Registrar's Office if you have questions.",
        ],
        'request_closed_unable_to_process' => [
            'email' => true, 'push' => true,
            'template' => 'We regret to inform you that the request for :document_name could not be completed: :closure_reason.',
        ],

        // Submission & tracking
        'request_submitted' => [
            'email' => false, 'push' => true,
            'template' => 'Request for :document_name validated (OR #: :or_number). Track progress in your dashboard.',
        ],
        'request_processing' => [
            'email' => false, 'push' => true,
            'template' => 'Your request for :document_name is now being processed by Registrar Staff.',
        ],

        // General
        'announcement_published' => [
            'email' => false, 'push' => true,
            'template' => 'Important Notice: :announcement_title - :announcement_details',
        ],

        // Administrative
        'deficiency_notice_escalated' => [
            'email' => false, 'push' => true,
            'template' => 'Deficiency Notice on Request #:request_id has reached 30 days without compliance. Review for closure.',
        ],
        'local_auth_login_used' => [
            'email' => false, 'push' => true,
            'template' => 'Break-glass login used for :email from :ip — verify this was expected.',
        ],
        'access_request_submitted' => [
            'email' => false, 'push' => true,
            'template' => 'New administrative access/module permission request is pending your approval.',
        ],
    ],

    /*
    | Matrix rows whose notification does not exist yet. Phase 5 builds them
    | and moves each into 'types' above.
    */
    'pending' => [
        'business_calendar_modified',
        'account_verification_confirmed',
        'account_verification_incomplete',
        'free_request_filed',
        'mismatched_receipt_flagged',
    ],
];
