<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Undergrad Requestor Registration — retention & review windows (D9)
    |--------------------------------------------------------------------------
    |
    | Both defaults below are deliberately NOT new numbers: they are the two
    | windows this codebase has already established as its own standard, made
    | configurable here rather than hardcoded in the two sweep jobs that read
    | them (see D9).
    |
    |   abandonment_days        — mirrors AdminUserService::create()'s existing
    |                             14-day pending_expires_at invite window, which
    |                             UndergradRequestorRegistrationService::register()
    |                             already writes onto the users row at submission
    |                             time. Kept here so ExpireStaleProvisioning and
    |                             the onboarding service can never drift apart;
    |                             the sweep itself reads pending_expires_at, so
    |                             changing this only affects NEW submissions.
    |
    |   rejected_retention_days — mirrors SECURITY_EVENTS_RETENTION_DAYS=90 (see
    |                             config/security_events.php). After this many
    |                             days, a Rejected requestor's self-declared PII
    |                             is purged by undergrad-requestors:purge-rejected-pii.
    |                             audit_logs is NEVER touched by that job — the
    |                             permanent record that a rejection occurred, and
    |                             who made it, lives there.
    |
    | purge_chunk_size — how many rejected records the purge job processes per
    | chunk. The job is idempotent and resumable, so a smaller chunk simply
    | means more, shorter transactions rather than a different outcome.
    |
    */

    'abandonment_days'        => (int) env('UNDERGRAD_REQUESTOR_ABANDONMENT_DAYS', 14),
    'rejected_retention_days' => (int) env('UNDERGRAD_REQUESTOR_REJECTED_RETENTION_DAYS', 90),
    'purge_chunk_size'        => (int) env('UNDERGRAD_REQUESTOR_PURGE_CHUNK_SIZE', 200),

    /*
    |--------------------------------------------------------------------------
    | Advisory OGOS lookup (D6)
    |--------------------------------------------------------------------------
    |
    | The live OGOS check performed when an Admin opens or decides a pending
    | submission is ADVISORY ONLY and must never block the review — OGOS being
    | unreachable is an expected, non-exceptional state (see
    | UndergradEnrollmentLookupClientInterface), which is why it degrades to
    | "not performed" rather than raising. The underlying HTTP timeout lives in
    | OgosClient (CURLOPT_TIMEOUT), shared with every other OGOS call, and is
    | deliberately not re-declared here.
    |
    | cache_ttl_seconds — memoizes a single (student number|email) lookup result
    | for this long, so opening a record and then immediately approving it does
    | not call OGOS twice for the same person. Deliberately short: the point of
    | this check is that it reflects CURRENT enrollment.
    |
    */

    'ogos_lookup' => [
        'cache_ttl_seconds' => (int) env('UNDERGRAD_REQUESTOR_OGOS_CACHE_TTL', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | PII encryption at rest (Phase 6)
    |--------------------------------------------------------------------------
    |
    | Applies to the four display-only self-declared columns on
    | undergrad_requestor_profiles: date_of_birth, present_address,
    | reason_for_non_enrollment, phone. Columns that are searched, indexed or
    | matched on (email, student_number, first_name, last_name) stay plaintext
    | by necessity — see App\Support\EncryptedPayload and the Phase 6
    | migration for the full reasoning.
    |
    | ⚠ These values are recoverable only with APP_KEY. Rotating APP_KEY
    |   without re-encrypting first makes them permanently unreadable. Treat
    |   APP_KEY as a backed-up production secret, not a per-deploy value.
    |
    | enabled — leave true. It exists so encryption can be switched off for a
    | local debugging session or an emergency read without a code change; reads
    | tolerate a mix of encrypted and plaintext rows in both directions, so
    | flipping it is safe, but leaving it off in any environment holding real
    | submissions is not.
    |
    */

    'pii_encryption' => [
        'enabled' => filter_var(env('UNDERGRAD_REQUESTOR_ENCRYPT_PII', true), FILTER_VALIDATE_BOOL),
    ],

    /*
    |--------------------------------------------------------------------------
    | Data Privacy Act consent (Phase 6)
    |--------------------------------------------------------------------------
    |
    | RA 10173 (Data Privacy Act of 2012). The onboarding form is the only
    | place RIS collects personal data from an unauthenticated member of the
    | public, which makes it the one place explicit, recorded consent is not
    | optional.
    |
    | consent_version — bump this string whenever the notice's WORDING changes
    | in a way that alters what a requestor is agreeing to. Every submission
    | stores the version it was shown (undergrad_requestor_profiles
    | .data_privacy_consent_version), so historical consents keep pointing at
    | the text that was actually displayed rather than silently re-pointing at
    | whatever is current. Never re-use a version number for different text.
    |
    | notice / notice_url — served to the frontend by
    | GET /api/undergrad-requestors/registration-notice, so the form's wording
    | and the version recorded against it come from ONE source. A notice
    | hardcoded in the SPA would drift from consent_version on the very first
    | copy edit, and nobody would notice until an audit.
    |
    | Registrar leadership and the University Data Protection Officer own this
    | text. The default below is a working placeholder that covers the
    | statutory elements (what, why, how long, who, and the data subject's
    | rights) — it is NOT a substitute for DPO sign-off before go-live.
    |
    */

    'data_privacy' => [
        'consent_version' => env('UNDERGRAD_REQUESTOR_CONSENT_VERSION', '1.0'),

        'notice_url' => env('UNDERGRAD_REQUESTOR_PRIVACY_NOTICE_URL'),

        'notice' => env('UNDERGRAD_REQUESTOR_PRIVACY_NOTICE', implode(' ', [
            'By submitting this form you consent to the PUP Taguig Office of the University Registrar',
            'collecting and processing the personal information you provide — your name, student number,',
            'program, date of birth, address, contact number and email address — for the sole purpose of',
            'verifying your prior enrolment and creating your document-request account.',
            'Your information is processed under the Data Privacy Act of 2012 (RA 10173).',
            'It is not shared with third parties outside the University.',
            'Submissions left unverified are removed after 14 days; if your registration is not approved,',
            'your personal information is disposed of 90 days after the decision, and only the record that',
            'a decision was made is retained.',
            'You may request access to, correction of, or erasure of your information by contacting the',
            "Office of the University Registrar.",
        ])),
    ],

    /*
    |--------------------------------------------------------------------------
    | Public endpoint rate limits (Phase 6)
    |--------------------------------------------------------------------------
    |
    | Read by the named rate limiters registered in AppServiceProvider::boot()
    | and applied in routes/api.php. Three buckets rather than one, because
    | they defend against three different things:
    |
    |   per-IP / minute   — crude scripted flooding.
    |   per-IP / day      — slow, patient enumeration that stays under the
    |                       per-minute bar. Set generously enough that a shared
    |                       campus NAT address (a computer lab where a whole
    |                       class registers in one sitting) is not locked out;
    |                       raise it, don't lower it, if that ever bites.
    |   per-EMAIL / day   — mail-bombing one person by repeatedly submitting
    |                       their address. This is the bucket the per-IP limits
    |                       cannot provide: an attacker with a pool of IPs
    |                       defeats those, but every attempt still names the
    |                       same victim address.
    |
    | Every tripped limit is written to security_events (deduplicated), so
    | throttling is observable rather than a silent 429 nobody ever sees.
    |
    */

    'rate_limits' => [
        'register_per_ip_per_minute'  => (int) env('UNDERGRAD_REQUESTOR_REGISTER_IP_PER_MIN', 5),
        'register_per_ip_per_day'     => (int) env('UNDERGRAD_REQUESTOR_REGISTER_IP_PER_DAY', 60),
        'register_per_email_per_day'  => (int) env('UNDERGRAD_REQUESTOR_REGISTER_EMAIL_PER_DAY', 5),

        'confirm_per_ip_per_minute'   => (int) env('UNDERGRAD_REQUESTOR_CONFIRM_IP_PER_MIN', 10),
        'confirm_per_email_per_hour'  => (int) env('UNDERGRAD_REQUESTOR_CONFIRM_EMAIL_PER_HOUR', 20),

        'notice_per_ip_per_minute'    => (int) env('UNDERGRAD_REQUESTOR_NOTICE_IP_PER_MIN', 30),

        /*
        | How long one tripped-limit security_events row suppresses further
        | rows for the same bucket. Without this, a sustained flood would
        | turn the rate limiter — a defence — into a write amplifier against
        | our own database.
        */
        'throttle_event_dedupe_minutes' => (int) env('UNDERGRAD_REQUESTOR_THROTTLE_EVENT_DEDUPE_MINUTES', 10),
    ],
];