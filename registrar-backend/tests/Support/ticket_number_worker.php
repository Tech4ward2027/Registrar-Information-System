<?php

/**
 * Worker for TicketNumberGeneratorTest's multi-process concurrency test.
 * Not a test itself: run as a separate PHP process, so that several real OS
 * processes (each with its own database connection) hit one SQLite file at the
 * same time, which a single-process test can never reproduce.
 *
 *   php ticket_number_worker.php setup
 *   php ticket_number_worker.php run <count>      prints one number per line
 *
 * DB_DATABASE (the shared SQLite file) comes from the environment.
 */

use App\Services\Tickets\TicketNumberGenerator;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../../vendor/autoload.php';

$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Concurrent writers on SQLite wait for the lock instead of failing at once.
config(['database.connections.sqlite.busy_timeout' => 30000]);

$mode = $argv[1] ?? '';

if ($mode === 'setup') {
    DB::statement('PRAGMA journal_mode = WAL');
    (require __DIR__ . '/../../database/migrations/2026_10_28_000005_create_ticket_number_sequences_table.php')->up();
    exit(0);
}

if ($mode === 'run') {
    $generator = $app->make(TicketNumberGenerator::class);

    for ($i = 0, $n = (int) ($argv[2] ?? 0); $i < $n; $i++) {
        echo $generator->next(), PHP_EOL;
    }
    exit(0);
}

fwrite(STDERR, "unknown mode\n");
exit(2);
