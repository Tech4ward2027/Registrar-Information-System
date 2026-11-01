<?php

/*
 * Phase 9: N+1 guard. Each endpoint is measured with a small and a larger data
 * set; the query count must not grow with the number of rows. A one-query
 * tolerance absorbs cursor/limit differences.
 */

use App\Enums\TicketStatusEnum;
use App\Models\SystemUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;

require_once __DIR__ . '/../../Support/TicketApiTestHelpers.php';

uses(RefreshDatabase::class);

function tkQueryCount(Closure $request): array
{
    DB::flushQueryListeners();
    DB::connection()->flushQueryLog();
    DB::connection()->enableQueryLog();

    $request();

    $log = DB::connection()->getQueryLog();
    DB::connection()->disableQueryLog();

    return [count($log), collect($log)->pluck('query')->all()];
}

function tkAssertFlat(int $small, int $large, array $largeQueries, string $what): void
{
    Assert::assertLessThanOrEqual(
        $small + 1,
        $large,
        "{$what}: {$small} queries with few rows but {$large} with many (N+1?).\n" . implode("\n", $largeQueries),
    );
}

test('the staff queue does not run more queries as tickets grow', function () {
    tkApiOn();
    tkApiAs(tkApiStaff(['View']));
    $codes = ['CAT-01', 'CAT-02', 'CAT-03', 'CAT-04', 'CAT-05'];

    foreach (range(0, 2) as $i) {
        tkOpenTicket(null, $codes[$i]);
    }
    test()->getJson('/api/v1/admin/tickets')->assertOk(); // warm per-process caches
    [$small] = tkQueryCount(fn () => test()->getJson('/api/v1/admin/tickets?per_page=50')->assertOk());

    foreach (range(0, 14) as $i) {
        tkOpenTicket(null, $codes[$i % 5]);
    }
    [$large, $queries] = tkQueryCount(fn () => test()->getJson('/api/v1/admin/tickets?per_page=50')->assertOk());

    tkAssertFlat($small, $large, $queries, 'GET /admin/tickets');

    [$filteredSmall] = tkQueryCount(fn () => test()->getJson('/api/v1/admin/tickets?search=Receipt&status=open&priority=high')->assertOk());
    Assert::assertLessThanOrEqual($large + 2, $filteredSmall, 'filters must not add per-row queries');
});

test('the requester ticket list does not run more queries as tickets grow', function () {
    tkApiOn();
    $me = tkApiAs(tkApiRequester());

    tkOpenTicket($me);
    test()->getJson('/api/v1/tickets')->assertOk();
    [$small] = tkQueryCount(fn () => test()->getJson('/api/v1/tickets?per_page=50')->assertOk());

    foreach (range(1, 14) as $i) {
        tkOpenTicket($me);
    }
    [$large, $queries] = tkQueryCount(fn () => test()->getJson('/api/v1/tickets?per_page=50')->assertOk());

    tkAssertFlat($small, $large, $queries, 'GET /tickets');
});

test('ticket detail views do not run more queries as messages and events grow', function () {
    tkApiOn();
    $requester = tkApiRequester();
    $staff     = tkApiStaff(['View', 'Claim', 'Reply', 'Resolve']);
    $ticket    = tkOpenTicket($requester);
    tkService()->claim($ticket, $staff);

    $staffUrl = "/api/v1/admin/tickets/{$ticket->public_id}";
    $ownUrl   = "/api/v1/tickets/{$ticket->public_id}";

    $measure = function () use ($staff, $requester, $staffUrl, $ownUrl) {
        tkApiAs($staff);
        [$s] = tkQueryCount(fn () => test()->getJson($staffUrl)->assertOk());
        app('auth')->forgetGuards();
        tkApiAs($requester);
        [$r, $q] = tkQueryCount(fn () => test()->getJson($ownUrl)->assertOk());

        return [$s, $r, $q];
    };

    $measure(); // warm
    [$staffSmall, $ownSmall] = $measure();

    foreach (range(1, 12) as $i) {
        tkService()->replyAsStaff($ticket->fresh(), $staff, "Staff reply {$i}");
        tkService()->replyAsRequester($ticket->fresh(), $requester, "Requester reply {$i}");
    }

    [$staffLarge, $ownLarge, $ownQueries] = $measure();

    tkAssertFlat($staffSmall, $staffLarge, [], 'GET /admin/tickets/{id}');
    tkAssertFlat($ownSmall, $ownLarge, $ownQueries, 'GET /tickets/{id}');
});

test('the summary report does not run more queries as tickets grow', function () {
    tkApiOn();
    tkApiAs(tkApiStaff(['View', 'Manage']));
    $codes = ['CAT-01', 'CAT-02', 'CAT-03', 'CAT-04', 'CAT-05'];

    foreach (range(0, 4) as $i) {
        tkOpenTicket(null, $codes[$i]);
    }
    test()->getJson('/api/v1/admin/tickets/summary')->assertOk();
    [$small] = tkQueryCount(fn () => test()->getJson('/api/v1/admin/tickets/summary')->assertOk());

    foreach (range(0, 24) as $i) {
        tkOpenTicket(null, $codes[$i % 5]);
    }
    [$large, $queries] = tkQueryCount(fn () => test()->getJson('/api/v1/admin/tickets/summary')->assertOk());

    tkAssertFlat($small, $large, $queries, 'GET /admin/tickets/summary');
});
