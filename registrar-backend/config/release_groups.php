<?php

/*
|--------------------------------------------------------------------------
| Release groups (legacy per-track claim tickets)
|--------------------------------------------------------------------------
|
| Every document / certificate now has its own uuid + claim_code and is
| claimed per item (ItemClaimService). New requests therefore no longer
| need a release-group ticket, so creation is OFF by default.
|
| Groups that already exist keep working: lookup, claim and every hold /
| terminal-status guard are untouched, so printed tickets are still
| honoured until the last open group finishes and the table is dropped.
|
| Rollback: set RELEASE_GROUPS_CREATE_ENABLED=true (then
| `php artisan config:clear`, or redeploy if config is cached).
*/
return [
    'create_enabled' => (bool) env('RELEASE_GROUPS_CREATE_ENABLED', false),
];
