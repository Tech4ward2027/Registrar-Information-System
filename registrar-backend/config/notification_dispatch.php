<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Dispatch log retention
    |--------------------------------------------------------------------------
    | How long notification_dispatch_logs rows are kept before
    | notifications:prune-dispatch-logs deletes them (Notification Policy §4).
    | Operational signal, not a compliance ledger — audit_logs is the
    | permanent record of user actions.
    */
    'retention_days' => (int) env('NOTIFICATION_DISPATCH_LOG_RETENTION_DAYS', 180),

];
