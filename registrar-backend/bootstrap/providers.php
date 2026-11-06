<?php

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\TicketServiceProvider::class,
    // Named limiters for POST /login and POST /auth/local-login (item 9.10).
    App\Providers\AuthRateLimitServiceProvider::class,
];