<?php

/*
 * Phase 9: data-volume smoke test. Skipped unless RUN_LOAD_TESTS=1, so the
 * normal suite stays fast:
 *
 *   RUN_LOAD_TESTS=1 php artisan test --filter=TicketScaleSmokeTest
 *
 * It proves the queue pages through thousands of rows with a constant query
 * count per page, no gaps and no repeats. It does NOT prove production speed:
 * that needs a staging run on MySQL (see docs/tickets-rollout.md and
 * load/tickets.k6.js).
 */

use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;

require_once __DIR__ . '/../../Support/TicketApiTestHelpers.php';

uses(RefreshDatabase::class);

test('the queue pages through 2,000 tickets with a flat query count, no gaps, no repeats', function () {
    tkApiOn();
    tkApiAs(tkApiStaff(['View']));
    $requester = tkApiRequester();

    $priorities = ['high', 'medium', 'low'];
    foreach (range(1, 4) as $batch) {
        Ticket::factory()->count(500)->create([
            'requester_user_id' => $requester->user_id,
            'priority'          => $priorities[$batch % 3],
        ]);
    }

    $seen   = [];
    $counts = [];
    $url    = '/api/v1/admin/tickets?per_page=50';
    $pages  = 0;

    while ($url !== null && $pages < 60) {
        DB::connection()->flushQueryLog();
        DB::connection()->enableQueryLog();
        $page = test()->getJson($url)->assertOk();
        $counts[] = count(DB::connection()->getQueryLog());
        DB::connection()->disableQueryLog();

        $seen = array_merge($seen, $page->json('data.*.id'));
        $url  = $page->json('links.next');
        $pages++;
    }

    Assert::assertCount(2000, $seen, 'every ticket is returned exactly once');
    Assert::assertCount(2000, array_unique($seen), 'no ticket is repeated across pages');
    Assert::assertLessThanOrEqual(min($counts) + 1, max($counts), 'query count per page must not depend on how deep the page is: ' . json_encode($counts));
})->skip(fn () => ! getenv('RUN_LOAD_TESTS'), 'Set RUN_LOAD_TESTS=1 to run');
