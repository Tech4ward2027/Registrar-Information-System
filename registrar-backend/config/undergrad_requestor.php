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
];
