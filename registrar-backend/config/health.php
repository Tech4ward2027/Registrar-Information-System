<?php

/**
 * Settings for GET /api/health (App\Http\Controllers\HealthController).
 *
 * Every value is read here, with env(), so `php artisan optimize` (config
 * caching, run on every container boot) keeps working. The controller reads
 * config() only.
 */
return [

    /*
    |--------------------------------------------------------------------
    | Detailed-view token
    |--------------------------------------------------------------------
    | Sent by monitors / the Dev team as the "X-Health-Token" header to get
    | the per-check breakdown. Unset or shorter than 32 characters = the
    | detailed view is OFF (fail closed); the public probe still works.
    | Generate one with:  openssl rand -hex 32
    */
    'token' => env('HEALTH_CHECK_TOKEN'),

    /*
    |--------------------------------------------------------------------
    | Public probe result cache (seconds). 0 disables caching.
    |--------------------------------------------------------------------
    */
    'cache_ttl' => (int) env('HEALTH_CACHE_TTL', 5),

    /* A queued job unreserved for this long counts as stuck (minutes). */
    'queue_stuck_minutes' => (int) env('HEALTH_QUEUE_STUCK_MINUTES', 10),

    /* No successful scheduled command for this long = scheduler suspect (minutes). */
    'scheduler_max_age_minutes' => (int) env('HEALTH_SCHEDULER_MAX_AGE_MINUTES', 150),

    /* The daily audit:verify should have finished within this many hours. */
    'audit_verify_max_age_hours' => (int) env('HEALTH_AUDIT_VERIFY_MAX_AGE_HOURS', 36),

    /* Warn when free space on the storage volume drops below this percentage. */
    'disk_min_free_percent' => (float) env('HEALTH_DISK_MIN_FREE_PERCENT', 10),
];
