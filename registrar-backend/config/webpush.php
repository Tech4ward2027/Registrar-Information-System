<?php

/*
|--------------------------------------------------------------------------
| Web Push (background notifications)
|--------------------------------------------------------------------------
| Configuration for delivering urgent notifications to a user's devices
| when PUPT-Registrar is closed. In-app notifications (DB row + Reverb
| broadcast) are unaffected — Web Push is an additional, best-effort channel.
|
| Generate a VAPID key pair ONCE per environment and keep it stable:
|
|     php artisan webpush:vapid
|
| Rotating the keys invalidates every existing subscription.
|--------------------------------------------------------------------------
*/

return [

    // Master switch. When false: no push jobs are queued and the public-key
    // endpoint returns 503, so the frontend hides the opt-in card.
    'enabled' => (bool) env('WEBPUSH_ENABLED', false),

    'vapid' => [
        // Must be a mailto: address or an https:// URL you control.
        // Apple's push service rejects http:// and localhost subjects.
        'subject'     => env('VAPID_SUBJECT'),
        'public_key'  => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
    ],

    // Seconds the push service should keep an undelivered message
    // (device offline) before discarding it. Status updates go stale, so 1 day.
    'ttl' => (int) env('WEBPUSH_TTL', 86400),

    // Seconds to wait on the push service before giving up on one request.
    // Devices are sent sequentially, so (timeout x max_subscriptions_per_user)
    // must stay below the broadcast worker's --timeout=30 (and the job's 25s).
    'timeout' => (int) env('WEBPUSH_TIMEOUT', 4),

    // Hard cap on registered devices per user. Oldest are evicted first.
    'max_subscriptions_per_user' => (int) env('WEBPUSH_MAX_PER_USER', 5),

    // Push payloads transit third-party servers (Google / Apple / Mozilla /
    // Microsoft). false = generic body, no free-text from the notification.
    // true = the rendered notification message is sent as the body.
    'include_message' => (bool) env('WEBPUSH_INCLUDE_MESSAGE', false),

    // SSRF guard. The server POSTs to whatever endpoint a client registers,
    // so only genuine browser push services are accepted. An entry matches
    // the exact host or any subdomain of it.
    'allowed_endpoint_hosts' => [
        'fcm.googleapis.com',               // Chrome, Edge, Brave, Opera, Android
        'updates.push.services.mozilla.com',// Firefox
        'push.services.mozilla.com',
        'notify.windows.com',               // legacy Edge / Windows
        'push.apple.com',                   // Safari (macOS, iOS, iPadOS)
    ],

    // Where a tapped notification should land, keyed by users.role_id.
    // Falls back to '/' for unknown roles. Mirrors the frontend route tree.
    'inbox_paths' => [
        1 => '/student/inbox',   // ROLE_STUDENT
        2 => '/alumni/inbox',    // ROLE_ALUMNI
        3 => '/staff/inbox',     // ROLE_ADMIN
        4 => '/super-admin',     // ROLE_SUPER_ADMIN
        5 => '/undergrad/inbox', // ROLE_UNDERGRAD_REQUESTOR
    ],

    // Served from the frontend's public/ directory (small file: the OS
    // re-downloads it for every notification, so never point this at a large asset).
    'icon' => '/icons/icon-192.png',
];