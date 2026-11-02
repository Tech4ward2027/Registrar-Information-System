<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    | When false no System Mailer email is attempted for the lifecycle triggers
    | or the approved exceptions. In-app and push are unaffected. Each skipped
    | attempt is still written to notification_dispatch_logs.
    */
    'enabled' => (bool) env('NOTIFICATION_MAIL_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Quota (Notification Policy: Super Admin monitors mailer quota)
    |--------------------------------------------------------------------------
    | daily_limit  Messages per calendar day (app timezone). The default
    |              matches a consumer Gmail SMTP account (about 500/day); raise
    |              it for Google Workspace (2000) or a transactional provider.
    | warn_ratio   Super Admins are alerted once per day when usage reaches
    |              this fraction of the limit, and once more at 100%.
    | enforce      true  = lifecycle emails over the limit are skipped (and
    |                      logged) so the account is not suspended by the
    |                      provider. Approved transactional exceptions
    |                      (verification and access links) are never dropped
    |                      by the quota, only counted.
    |              false = monitor only.
    */
    'quota' => [
        'daily_limit' => (int) env('MAIL_DAILY_QUOTA', 500),
        'warn_ratio'  => (float) env('MAIL_QUOTA_WARN_RATIO', 0.8),
        'enforce'     => (bool) env('MAIL_QUOTA_ENFORCE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Throttling and retries
    |--------------------------------------------------------------------------
    | rate_per_minute  Ceiling for lifecycle emails leaving the queue worker.
    |                  Excess jobs are released back to the queue, not dropped.
    | backoff          Seconds between retries after a transport error.
    | retry_hours      Total window a throttled or failing job may keep retrying.
    */
    'rate_per_minute' => (int) env('MAIL_RATE_PER_MINUTE', 20),
    'backoff'         => [60, 300, 900],
    'retry_hours'     => 6,

    /*
    | Several code paths can announce the same status change (single update,
    | bulk update, release group). An identical message to the same person for
    | the same request inside this window is sent once.
    */
    'duplicate_window_minutes' => (int) env('MAIL_DUPLICATE_WINDOW_MINUTES', 15),

];
