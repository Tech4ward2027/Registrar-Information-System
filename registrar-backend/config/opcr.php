<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Working day length (minutes)
    |--------------------------------------------------------------------------
    |
    | Citizen's Charter targets are typed as free text such as
    | "2 working day/s, 23 minute/s". To compare an office-hours average with
    | such a target, one working day has to be converted to minutes.
    |
    | Leave OPCR_WORKING_DAY_MINUTES unset (null) to derive it from the default
    | business calendar's weekly hours — the same hours the processing-time
    | clock counts — so the target and the measured minutes use one unit
    | (08:00-20:00 = 720). Set it only to force a different figure, for example
    | 480 for a flat 8-hour day.
    |
    */
    'working_day_minutes' => env('OPCR_WORKING_DAY_MINUTES') !== null
        ? (int) env('OPCR_WORKING_DAY_MINUTES')
        : null,

    /** Used only when no calendar hours can be read and nothing is configured. */
    'fallback_working_day_minutes' => 480,

    /*
    |--------------------------------------------------------------------------
    | Report guard rails
    |--------------------------------------------------------------------------
    |
    | max_range_days  Longest date range one report request may cover
    |                 (a rating year is 366 days; two years of headroom).
    | chunk_size      Requests loaded per database round trip while
    |                 aggregating, which bounds memory use.
    |
    */
    'max_range_days' => 732,
    'chunk_size'     => 250,
];
