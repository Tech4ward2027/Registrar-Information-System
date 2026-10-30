<?php

/**
 * Worker for TicketClaimRaceTest. Runs as its own PHP process so that several
 * staff members really do claim the same tickets at the same moment, each over
 * a separate database connection.
 *
 *   setup <tickets> <staff>            migrate a fresh SQLite file, create the
 *                                      data, print JSON {tickets:[ids],staff:[ids]}
 *   run <staffId> <startAt> <ids,...>  wait for the shared start time, then try
 *                                      to claim each ticket; print
 *                                      "RESULT|id|WON|" or "RESULT|id|LOST|<claimerId>"
 *                                      per ticket (other output is ignored by the test)
 *   inspect <ids,...>                  print JSON describing each ticket's final
 *                                      state and event-chain verdict
 *
 * DB_DATABASE (the shared SQLite file) comes from the environment.
 */

use App\Exceptions\Tickets\TicketClaimException;
use App\Models\Policy;
use App\Models\SystemUser;
use App\Models\Ticket;
use App\Services\Tickets\TicketEventRecorder;
use App\Services\Tickets\TicketService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../../vendor/autoload.php';

$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Concurrent writers on SQLite wait for the lock instead of failing at once.
config(['database.connections.sqlite.busy_timeout' => 30000]);

$mode = $argv[1] ?? '';

if ($mode === 'setup') {
    DB::statement('PRAGMA journal_mode = WAL');
    Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);

    $policy = Policy::create([
        'name' => 'Race Claimers', 'is_system' => false,
        'permissions' => ['tickets' => ['View', 'Claim']],
    ]);

    $staff = SystemUser::factory()->count((int) $argv[3])->create([
        'role_id' => SystemUser::ROLE_ADMIN, 'status' => 'Activated', 'policy_id' => $policy->policy_id,
    ])->pluck('user_id')->all();

    $tickets = Ticket::factory()->count((int) $argv[2])->create()->pluck('id')->all();

    echo json_encode(['tickets' => $tickets, 'staff' => $staff]), PHP_EOL;
    exit(0);
}

if ($mode === 'run') {
    $staff   = SystemUser::findOrFail((int) $argv[2]);
    $startAt = (float) $argv[3];
    $service = $app->make(TicketService::class);

    // Everyone boots at a different speed; release them together.
    while (microtime(true) < $startAt) {
        usleep(200);
    }

    foreach (array_map('intval', explode(',', $argv[4])) as $id) {
        try {
            $service->claim(Ticket::findOrFail($id), $staff);
            echo "RESULT|{$id}|WON|", PHP_EOL;
        } catch (TicketClaimException $e) {
            echo "RESULT|{$id}|LOST|{$e->claimerId}", PHP_EOL;
        }
    }
    exit(0);
}

if ($mode === 'inspect') {
    $recorder = $app->make(TicketEventRecorder::class);
    $out = [];

    foreach (array_map('intval', explode(',', $argv[2])) as $id) {
        $ticket = Ticket::findOrFail($id);
        $out[$id] = [
            'claimed_by' => $ticket->claimed_by_user_id,
            'status'     => $ticket->status->value,
            'events'     => $ticket->events()->pluck('event_type')->all(),
            'chain_ok'   => $recorder->verifyChain($ticket)['ok'],
        ];
    }

    echo json_encode($out), PHP_EOL;
    exit(0);
}

fwrite(STDERR, "unknown mode\n");
exit(2);