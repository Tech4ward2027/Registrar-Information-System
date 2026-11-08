<?php

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Health routes (mounted at /api by bootstrap/app.php)
|--------------------------------------------------------------------------
| Deliberately NOT part of routes/api.php:
|   - api.php is mounted under /api/v1 and sits behind the Sanctum cookie
|     bridge and the shared auth groups. The DR runbook, uptime monitors and
|     DNS cut-over checks need an UNVERSIONED, unauthenticated path that keeps
|     working when sessions, tokens or the schema are what is broken.
|   - No middleware at all: no throttle (the throttle store is Redis, one of
|     the things being checked) and no session. Load is bounded inside the
|     controller instead (short result cache).
*/
Route::get('health', HealthController::class)->name('health');
