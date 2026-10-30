<?php

/**
 * Support Ticketing — tunable values.
 *
 * Everything an operator might reasonably retune lives here, so no number is
 * ever hardcoded in a service. Each value reads from a TICKETS_* environment
 * variable with the policy default as fallback.
 *
 * Values marked [DEFAULT] were not fixed by the approved policy and are the
 * implementer's chosen starting point. Confirm or change them before pilot;
 * every one is a single line here.
 *
 * The operating schedule itself (Tue–Fri, 8 AM–8 PM, closed Mondays, weekends
 * and declared holidays/suspensions) is NOT configured here. It is owned by the
 * existing business calendar (BusinessCalendarService), maintained by the same
 * registrar staff, so ticket clocks can never disagree with request clocks.
 * Phase 5 configures that schedule; this file only holds the targets measured
 * against it.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Service levels (measured in operating time only)
    |--------------------------------------------------------------------------
    |
    | Policy: first response within 4–8 working hours, resolution within 1–2
    | operating days, "depending on inquiry complexity".
    |
    | [DEFAULT] Complexity is approximated by the category's priority: High
    | gets the tight end of each range, Medium and Low get the loose end.
    |
    | first_response_hours  — working hours from submission to first staff reply.
    | resolution_days       — operating days from submission to Resolved.
    | at_risk_ratio         — a ticket is "at risk" once this fraction of its
    |                         window has elapsed without the target being met.
    |
    */
    'sla' => [
        // Which business calendar the ticket clocks run on. null = the calendar
        // flagged is_default (the same one request processing uses), which is
        // what the policy wants: one calendar, kept up to date by one team.
        // Set an id only if tickets ever need a calendar of their own, and note
        // holidays are per calendar then.
        'calendar_id' => env('TICKETS_SLA_CALENDAR_ID') !== null ? (int) env('TICKETS_SLA_CALENDAR_ID') : null,

        // [DEFAULT] Length of one "operating day" in working hours, used to turn
        // resolution_days into working minutes. The policy's 8 AM–8 PM schedule
        // is 12 hours. `php artisan tickets:verify-calendar` warns when the
        // calendar's real hours differ.
        'operating_day_hours' => (int) env('TICKETS_SLA_OPERATING_DAY_HOURS', 12),

        'first_response_hours' => [
            'high'   => (int) env('TICKETS_SLA_FIRST_RESPONSE_HOURS_HIGH', 4),
            'medium' => (int) env('TICKETS_SLA_FIRST_RESPONSE_HOURS_MEDIUM', 8),
            'low'    => (int) env('TICKETS_SLA_FIRST_RESPONSE_HOURS_LOW', 8),
        ],
        'resolution_days' => [
            'high'   => (int) env('TICKETS_SLA_RESOLUTION_DAYS_HIGH', 1),
            'medium' => (int) env('TICKETS_SLA_RESOLUTION_DAYS_MEDIUM', 2),
            'low'    => (int) env('TICKETS_SLA_RESOLUTION_DAYS_LOW', 2),
        ],
        'at_risk_ratio' => (float) env('TICKETS_SLA_AT_RISK_RATIO', 0.75), // [DEFAULT]
    ],

    /*
    |--------------------------------------------------------------------------
    | Resolution and closure
    |--------------------------------------------------------------------------
    |
    | A Resolved ticket closes when the requester confirms, or automatically
    | this many CALENDAR days after it was resolved (not working days, per
    | policy). Closed is final: there is no reopening.
    |
    | During the Resolved window a requester reply, or "This isn't resolved",
    | moves the ticket back to In Progress. That is the only backward
    | transition, limited to max_requester_returns per ticket.
    |
    | reminder_after_days sends the "still not resolved? confirm or reply"
    | reminder; the policy asks for it on day 2 of the 3-day window.
    |
    */
    'auto_close_days'       => (int) env('TICKETS_AUTO_CLOSE_DAYS', 3),
    'reminder_after_days'   => (int) env('TICKETS_REMINDER_AFTER_DAYS', 2),
    'max_requester_returns' => (int) env('TICKETS_MAX_REQUESTER_RETURNS', 2),

    /*
    |--------------------------------------------------------------------------
    | Claims
    |--------------------------------------------------------------------------
    |
    | [DEFAULT] A claim that sees no staff activity for this many WORKING hours
    | is released automatically (tickets:release-stale-claims) so an abandoned
    | claim cannot hide a ticket from the rest of the team. Counted on the
    | business calendar, like the SLA. Activity means the CLAIMER replying; it
    | restarts the timeout. While a ticket is Pending Client the claim is not
    | released (the wait is on the requester), and the timeout restarts when the
    | requester answers.
    |
    */
    'claim_timeout_working_hours' => (int) env('TICKETS_CLAIM_TIMEOUT_WORKING_HOURS', 24),
    // Tickets handled per database chunk by the SLA sweep and the stale-claim
    // sweep. Only bounds memory; every ticket is still visited each run.
    'sweep_chunk_size'            => (int) env('TICKETS_SWEEP_CHUNK_SIZE', 100),

    /*
    |--------------------------------------------------------------------------
    | Resolving
    |--------------------------------------------------------------------------
    |
    | [DEFAULT] Whether a ticket may be resolved only by the staff member who
    | claimed it. false (default): any staff holding the tickets "Resolve"
    | action may resolve it. This is the one policy question still open; it is
    | read in exactly one place (TicketPolicy::resolve, Phase 2).
    |
    */
    'resolve_requires_claimer' => (bool) env('TICKETS_RESOLVE_REQUIRES_CLAIMER', false),

    /*
    |--------------------------------------------------------------------------
    | Retention and anonymization
    |--------------------------------------------------------------------------
    |
    | Tickets, messages and the event log are kept for retention_years (3, per
    | the Chat Support & Ticketing policy). [DEFAULT] At that mark the personal
    | identifiers of PUBLIC submitters (email, name, IP) are anonymized and the
    | records then purged, rather than kept indefinitely. Data Privacy Act
    | (RA 10173): keep personal data only as long as needed.
    |
    */
    'retention' => [
        'years'      => (int) env('TICKETS_RETENTION_YEARS', 3),
        'chunk_size' => (int) env('TICKETS_RETENTION_CHUNK_SIZE', 200),
    ],

    /*
    |--------------------------------------------------------------------------
    | Content limits
    |--------------------------------------------------------------------------
    |
    | description_min_length carries over the 20-character minimum from the
    | spam-prevention rules. The caps below are [DEFAULT] values: the policy
    | requires a length cap and a link limit but does not fix the numbers.
    |
    */
    'content' => [
        'subject_max_length'     => (int) env('TICKETS_SUBJECT_MAX_LENGTH', 150),
        'description_min_length' => (int) env('TICKETS_DESCRIPTION_MIN_LENGTH', 20),
        'message_max_length'     => (int) env('TICKETS_MESSAGE_MAX_LENGTH', 5000),
        'max_links_per_message'  => (int) env('TICKETS_MAX_LINKS_PER_MESSAGE', 2),
        // [DEFAULT] A resolution note is what the requester reads when their
        // ticket is marked Resolved, so a bare "done" is not accepted.
        'resolution_note_min_length' => (int) env('TICKETS_RESOLUTION_NOTE_MIN_LENGTH', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | API paging
    |--------------------------------------------------------------------------
    |
    | [DEFAULT] Lists use cursor pagination: cost stays flat however deep the
    | queue grows, and a ticket arriving mid-scroll cannot shift or repeat rows.
    | A client may ask for fewer or more rows with ?per_page=, never more than
    | max_page_size.
    |
    */
    'api' => [
        'page_size'     => (int) env('TICKETS_API_PAGE_SIZE', 20),
        'max_page_size' => (int) env('TICKETS_API_MAX_PAGE_SIZE', 50),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limits
    |--------------------------------------------------------------------------
    |
    | public:        the layered controls for unauthenticated submitters.
    | authenticated: carried over from the spam rules (3 new tickets / 10 min).
    | replies:       applies to both kinds of requester, per ticket.
    |
    | open_tickets_per_email counts tickets still in Open, In Progress or
    | Pending Client; Resolved and Closed do not count.
    |
    */
    'rate_limits' => [
        'public' => [
            'verification_emails_per_email_per_hour' => (int) env('TICKETS_RL_VERIFY_PER_EMAIL_HOUR', 3),
            'verification_emails_per_ip_per_hour'    => (int) env('TICKETS_RL_VERIFY_PER_IP_HOUR', 10),
            'tickets_per_email_per_day'              => (int) env('TICKETS_RL_TICKETS_PER_EMAIL_DAY', 3),
            'tickets_per_ip_per_day'                 => (int) env('TICKETS_RL_TICKETS_PER_IP_DAY', 10),
            'open_tickets_per_email'                 => (int) env('TICKETS_RL_OPEN_PER_EMAIL', 3),
            // [DEFAULT] Not fixed by policy. A short burst ceiling per IP on top of the
            // hourly one, and the ceilings on "resend my link" and on the tracking
            // pages. Resend has its own per-address bucket so that hammering it for
            // someone else's address cannot use up THEIR allowance to file a ticket.
            'start_per_ip_per_minute'                => (int) env('TICKETS_RL_START_PER_IP_MINUTE', 5),
            'resend_per_email_per_hour'              => (int) env('TICKETS_RL_RESEND_PER_EMAIL_HOUR', 3),
            'resend_per_ip_per_hour'                 => (int) env('TICKETS_RL_RESEND_PER_IP_HOUR', 10),
            'confirm_per_ip_per_minute'              => (int) env('TICKETS_RL_CONFIRM_PER_IP_MINUTE', 10),
            'track_per_ip_per_minute'                => (int) env('TICKETS_RL_TRACK_PER_IP_MINUTE', 60),
            'form_per_ip_per_minute'                 => (int) env('TICKETS_RL_FORM_PER_IP_MINUTE', 30),
        ],
        'authenticated' => [
            'tickets_per_window'     => (int) env('TICKETS_RL_AUTH_TICKETS', 3),
            'tickets_window_minutes' => (int) env('TICKETS_RL_AUTH_WINDOW_MINUTES', 10),
        ],
        'replies_per_ticket_per_hour' => (int) env('TICKETS_RL_REPLIES_PER_TICKET_HOUR', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Public submission (unauthenticated requesters)
    |--------------------------------------------------------------------------
    |
    | Email verification happens before any ticket exists. Tracking afterwards
    | uses an emailed magic link: a random token of at least token_bytes bytes
    | whose HASH only is stored, valid until the ticket is Closed plus
    | token_grace_days.
    |
    | [DEFAULT] verification_ttl_minutes, min_fill_seconds and the honeypot
    | field name are not fixed by policy.
    |
    */
    'public' => [
        'token_bytes'              => max(32, (int) env('TICKETS_PUBLIC_TOKEN_BYTES', 32)), // never below 32
        'token_grace_days'         => (int) env('TICKETS_PUBLIC_TOKEN_GRACE_DAYS', 7),
        'verification_ttl_minutes' => (int) env('TICKETS_PUBLIC_VERIFICATION_TTL_MINUTES', 30),
        'min_fill_seconds'         => (int) env('TICKETS_PUBLIC_MIN_FILL_SECONDS', 3),
        'honeypot_field'           => env('TICKETS_PUBLIC_HONEYPOT_FIELD', 'website'),
        'captcha_enabled'          => (bool) env('TICKETS_PUBLIC_CAPTCHA_ENABLED', true),

        // [DEFAULT] How long a form token (issued when the form loads, used to
        // measure fill time) stays usable.
        'form_token_max_age_minutes' => (int) env('TICKETS_PUBLIC_FORM_TOKEN_MAX_AGE_MINUTES', 120),
        // [DEFAULT] Magic links alive at once per ticket; issuing more retires the oldest.
        'max_access_tokens_per_ticket' => (int) env('TICKETS_PUBLIC_MAX_ACCESS_TOKENS', 5),
        // [DEFAULT] Confirmed/expired pending submissions are deleted after this long.
        'pending_retention_days'   => (int) env('TICKETS_PUBLIC_PENDING_RETENTION_DAYS', 7),

        // [DEFAULT] Front-end pages the emailed links open. The token travels in
        // the URL FRAGMENT (#token=...), which browsers never send to a server, so
        // it stays out of access logs and Referer headers; the page reads it and
        // sends it in the X-Ticket-Token header.
        'links' => [
            'confirm_path' => env('TICKETS_PUBLIC_CONFIRM_PATH', '/support/confirm'),
            'track_path'   => env('TICKETS_PUBLIC_TRACK_PATH', '/support/ticket'),
        ],

        // [DEFAULT] CAPTCHA. Cloudflare Turnstile out of the box; hCaptcha and
        // reCAPTCHA accept the same request (secret, response, remoteip), so
        // switching provider is a matter of verify_url, site_key and secret.
        // Fails CLOSED: enabled with no secret, or an unreachable provider, means
        // every public submission is refused, never waved through.
        'captcha' => [
            'site_key'   => env('TICKETS_CAPTCHA_SITE_KEY'),
            'secret'     => env('TICKETS_CAPTCHA_SECRET'),
            'verify_url' => env('TICKETS_CAPTCHA_VERIFY_URL', 'https://challenges.cloudflare.com/turnstile/v0/siteverify'),
            'timeout'    => (int) env('TICKETS_CAPTCHA_TIMEOUT_SECONDS', 5),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Ticket numbers
    |--------------------------------------------------------------------------
    |
    | Format TKT-YYYY-XXXXX. The year is taken in the display timezone
    | (config/app.php 'display_timezone', Asia/Manila), so the counter rolls
    | over at local midnight on 1 January, not at 08:00 local time as UTC would.
    | The sequence is zero-padded to sequence_pad digits and simply widens
    | beyond that rather than wrapping.
    |
    */
    'number' => [
        'prefix'       => 'TKT',
        'sequence_pad' => 5,
    ],

];