<?php

use App\Services\Tickets\TicketNumberGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

uses(RefreshDatabase::class);

test('numbers follow TKT-YYYY-XXXXX and count up from 00001', function () {
    $generator = app(TicketNumberGenerator::class);
    $at = CarbonImmutable::parse('2026-10-28 02:00:00', 'UTC');

    expect($generator->next($at))->toBe('TKT-2026-00001')
        ->and($generator->next($at))->toBe('TKT-2026-00002')
        ->and($generator->next($at))->toBe('TKT-2026-00003');
});

test('the sequence restarts each year, using Manila time to decide the year', function () {
    $generator = app(TicketNumberGenerator::class);

    // 15:59:59 UTC on 31 Dec is 23:59:59 in Manila: still 2026.
    $generator->next(CarbonImmutable::parse('2026-12-31 15:59:59', 'UTC'));
    $generator->next(CarbonImmutable::parse('2026-12-31 15:59:59', 'UTC'));

    // 16:00:00 UTC is already 00:00 on 1 January in Manila: 2027, back to 1.
    expect($generator->next(CarbonImmutable::parse('2026-12-31 16:00:00', 'UTC')))->toBe('TKT-2027-00001')
        ->and($generator->next(CarbonImmutable::parse('2026-12-31 15:00:00', 'UTC')))->toBe('TKT-2026-00003');
});

test('a rolled-back transaction does not consume a number, so numbers have no gaps', function () {
    $generator = app(TicketNumberGenerator::class);
    $at = CarbonImmutable::parse('2026-10-28 02:00:00', 'UTC');

    expect($generator->next($at))->toBe('TKT-2026-00001');

    try {
        DB::transaction(function () use ($generator, $at) {
            $generator->next($at); // would be 00002
            throw new RuntimeException('ticket insert failed');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect($generator->next($at))->toBe('TKT-2026-00002');
});

test('the sequence widens past five digits instead of wrapping', function () {
    $generator = app(TicketNumberGenerator::class);
    DB::table('ticket_number_sequences')->insert(['year' => 2026, 'last_number' => 99999, 'updated_at' => now()]);

    expect($generator->next(CarbonImmutable::parse('2026-10-28 02:00:00', 'UTC')))->toBe('TKT-2026-100000');
});

/*
 * Real concurrency. A single-process test cannot race itself, so this starts
 * several OS processes, each with its own database connection, against one
 * SQLite file and has them all draw numbers at once. Every number must be
 * unique, and together they must be exactly 1..N with no gap.
 */
test('concurrent processes never receive a duplicate number', function () {
    $processes = 6;
    $perProcess = 25;

    $dir = sys_get_temp_dir() . '/ticket-numbers-' . bin2hex(random_bytes(4));
    mkdir($dir);
    $dbFile = $dir . '/concurrency.sqlite';
    touch($dbFile);

    $worker = base_path('tests/Support/ticket_number_worker.php');
    $env = [
        'APP_ENV'       => 'testing',
        'APP_KEY'       => config('app.key'),
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE'   => $dbFile,
        'DB_URL'        => '',
        'CACHE_STORE'   => 'array',
        'SESSION_DRIVER' => 'array',
    ];

    try {
        $setup = new Process([PHP_BINARY, $worker, 'setup'], base_path(), $env);
        $setup->mustRun();

        /** @var list<Process> $running */
        $running = [];
        for ($i = 0; $i < $processes; $i++) {
            $p = new Process([PHP_BINARY, $worker, 'run', (string) $perProcess], base_path(), $env);
            $p->setTimeout(120);
            $p->start();
            $running[] = $p;
        }

        $numbers = [];
        foreach ($running as $p) {
            $p->wait();
            expect($p->getExitCode())->toBe(0, $p->getErrorOutput());
            foreach (array_filter(explode("\n", trim($p->getOutput()))) as $line) {
                $numbers[] = $line;
            }
        }
    } finally {
        @unlink($dbFile);
        @unlink($dbFile . '-wal');
        @unlink($dbFile . '-shm');
        @rmdir($dir);
    }

    $total = $processes * $perProcess;
    $year  = now(config('app.display_timezone'))->year;

    expect($numbers)->toHaveCount($total)
        ->and(array_unique($numbers))->toHaveCount($total); // no duplicates

    $expected = array_map(fn (int $n) => sprintf('TKT-%d-%05d', $year, $n), range(1, $total));
    sort($numbers);
    sort($expected);

    expect($numbers)->toBe($expected); // and no gaps
})->group('concurrency');
