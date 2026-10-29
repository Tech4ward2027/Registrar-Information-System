<?php

use Symfony\Component\Process\Process;

/*
 * The two-staff claim race, for real: several OS processes, each with its own
 * database connection, all try to claim the same tickets at the same instant.
 * For every ticket exactly one must win, everyone else must be refused with the
 * winner's id, and the ticket must end with exactly one `claimed` event and an
 * intact hash chain.
 *
 * Runs on a SQLite file, where the database serialises writers; the claim is a
 * single conditional UPDATE that never reads first, so the same guarantee holds
 * on MySQL, where the loser waits on the row lock and then matches nothing.
 */
test('when several staff claim the same tickets at once, exactly one wins each', function () {
    $staffCount  = 4;
    $ticketCount = 12;

    $dir = sys_get_temp_dir() . '/ticket-claims-' . bin2hex(random_bytes(4));
    mkdir($dir);
    $dbFile = $dir . '/race.sqlite';
    touch($dbFile);

    $worker = base_path('tests/Support/ticket_claim_worker.php');
    $env = [
        'APP_ENV'        => 'testing',
        'APP_KEY'        => config('app.key'),
        'DB_CONNECTION'  => 'sqlite',
        'DB_DATABASE'    => $dbFile,
        'DB_URL'         => '',
        'CACHE_STORE'    => 'array',
        'SESSION_DRIVER' => 'array',
    ];

    $run = function (array $args, int $timeout = 300) use ($worker, $env): Process {
        $p = new Process([PHP_BINARY, $worker, ...$args], base_path(), $env);
        $p->setTimeout($timeout);

        return $p;
    };

    $lastJson = function (string $output): array {
        $lines = array_values(array_filter(explode("\n", trim($output))));

        return json_decode(end($lines), true, 512, JSON_THROW_ON_ERROR);
    };

    try {
        $setup = $run(['setup', (string) $ticketCount, (string) $staffCount]);
        $setup->mustRun();
        $data = $lastJson($setup->getOutput());

        $ids = implode(',', $data['tickets']);
        $startAt = microtime(true) + 6; // generous: workers boot at different speeds

        /** @var array<int,Process> $racers staff id => process */
        $racers = [];
        foreach ($data['staff'] as $staffId) {
            $racers[$staffId] = $run(['run', (string) $staffId, (string) $startAt, $ids], 120);
            $racers[$staffId]->start();
        }

        $results = []; // ticket id => list of [staff id, outcome]
        foreach ($racers as $staffId => $p) {
            $p->wait();
            expect($p->getExitCode())->toBe(0, $p->getErrorOutput() . $p->getOutput());

            foreach (array_filter(explode("\n", trim($p->getOutput()))) as $line) {
                [$ticketId, $outcome, $claimer] = array_pad(explode(':', $line), 3, null);
                $results[(int) $ticketId][] = ['staff' => $staffId, 'outcome' => $outcome, 'claimer' => $claimer];
            }
        }

        $inspect = $run(['inspect', $ids]);
        $inspect->mustRun();
        $final = $lastJson($inspect->getOutput());
    } finally {
        foreach (['', '-wal', '-shm'] as $suffix) {
            @unlink($dbFile . $suffix);
        }
        @rmdir($dir);
    }

    expect($results)->toHaveCount($ticketCount);

    foreach ($data['tickets'] as $ticketId) {
        $attempts = $results[$ticketId];
        $winners  = array_values(array_filter($attempts, fn ($a) => $a['outcome'] === 'WON'));

        expect($attempts)->toHaveCount($staffCount)
            ->and($winners)->toHaveCount(1, "ticket {$ticketId} must have exactly one winner");

        $winner = $winners[0]['staff'];

        foreach ($attempts as $a) {
            if ($a['outcome'] === 'LOST') {
                expect((int) $a['claimer'])->toBe($winner, 'a loser must be told who won');
            }
        }

        expect($final[$ticketId]['claimed_by'])->toBe($winner)
            ->and($final[$ticketId]['status'])->toBe('in_progress')
            ->and($final[$ticketId]['events'])->toBe(['claimed'])
            ->and($final[$ticketId]['chain_ok'])->toBeTrue();
    }
})->group('concurrency');
