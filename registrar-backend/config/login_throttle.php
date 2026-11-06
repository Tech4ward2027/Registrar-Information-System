<?php

/*
|--------------------------------------------------------------------------
| Login brute-force limits (BACKEND ACTION ITEM 9.10)
|--------------------------------------------------------------------------
| Consumed by App\Providers\AuthRateLimitServiceProvider, which registers the
| named limiters `login` (POST /login) and `local-login` (POST /auth/local-login).
|
| Each limiter stacks THREE independent buckets, and a request is rejected
| (HTTP 429) when ANY of them is exhausted:
|
|   per_ip_per_minute       Caps one source address. Unchanged from the previous
|                           flat `throttle:60,1`, so shared campus NAT behaviour
|                           and the load-test baseline stay comparable.
|   per_account_per_minute  Caps attempts against ONE account (keyed on the
|                           submitted e-mail) no matter how many IPs they come
|                           from. This is what stops password guessing from a
|                           botnet, and it also makes a forged X-Forwarded-For
|                           header useless for evading the limiter (9.11).
|   per_account_per_hour    Slower ceiling for patient, low-rate guessing.
|
| Trade-off to be aware of: an account bucket can be used to briefly lock a
| known e-mail out (a nuisance attack). The per-minute window keeps that lock
| short, and the values are deliberately well above what a person typing their
| own password can reach. Tune per environment through the env vars below; no
| code change or deploy is needed beyond restarting the containers.
*/

return [

    'login' => [
        'per_ip_per_minute'      => (int) env('LOGIN_THROTTLE_IP_PER_MINUTE', 60),
        'per_account_per_minute' => (int) env('LOGIN_THROTTLE_ACCOUNT_PER_MINUTE', 10),
        'per_account_per_hour'   => (int) env('LOGIN_THROTTLE_ACCOUNT_PER_HOUR', 50),
    ],

    // Break-glass local password path. Same shape, independently tunable.
    'local_login' => [
        'per_ip_per_minute'      => (int) env('LOCAL_LOGIN_THROTTLE_IP_PER_MINUTE', 60),
        'per_account_per_minute' => (int) env('LOCAL_LOGIN_THROTTLE_ACCOUNT_PER_MINUTE', 10),
        'per_account_per_hour'   => (int) env('LOCAL_LOGIN_THROTTLE_ACCOUNT_PER_HOUR', 50),
    ],

];
