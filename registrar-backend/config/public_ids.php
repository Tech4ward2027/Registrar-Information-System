<?php

/**
 * Public identifier policy.
 *
 * Integer primary keys (document_request.request_id, ...) are internal.
 * Clients address a document request by its `public_id` (a ULID), so ids are
 * neither guessable nor sequential. This is defence in depth ONLY:
 * authorization is what protects a record, and every endpoint must still
 * check the caller against it.
 *
 * The request `uuid` is a different thing: it is the claim credential in the
 * QR ticket and is never accepted as a route identifier.
 *
 * accept_legacy_numeric_request_ids
 * ---------------------------------
 * Expand / contract switch for the move to public_id addressing of
 * /document-requests/{documentRequest}/...:
 *
 *   true  - public_id AND the old integer request_id are accepted (default,
 *           so a client that has not been updated keeps working).
 *   false - only the public_id is accepted; an integer id answers 404.
 *
 * Flip to false (PUBLIC_IDS_ACCEPT_LEGACY_NUMERIC=false, then
 * `php artisan config:clear`) once every client sends the public_id.
 *
 * Note: this is intentionally NOT in config/features.php. That file's
 * fail-closed rule is for gating new features; defaulting this switch to
 * false would break every existing client on deploy.
 */

return [
    'accept_legacy_numeric_request_ids' => (bool) env('PUBLIC_IDS_ACCEPT_LEGACY_NUMERIC', true),
];