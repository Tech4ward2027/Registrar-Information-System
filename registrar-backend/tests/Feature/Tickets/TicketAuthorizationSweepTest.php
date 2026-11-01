<?php

/*
 * Phase 9: authorization sweep.
 *
 * One catalogue lists every ticket route and what it requires. The tests then
 * call each route as each kind of caller and check the exact status for every
 * DENIED combination, and that every ALLOWED combination gets past the
 * authorization gates (anything except 401/403, and 404 where the route is
 * documented to hide existence). A route added later without a catalogue
 * entry fails 'the catalogue covers every registered ticket route', so a new
 * endpoint cannot ship unreviewed.
 */

use App\Models\SystemUser;
use App\Models\TicketCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Assert;

require_once __DIR__ . '/../../Support/TicketApiTestHelpers.php';

uses(RefreshDatabase::class);

// kind: requester | requester_any | staff | public | any_auth
// need: any-of ticket actions required ON TOP of View (staff kind only)
function tkSweepCatalog(): array
{
    return [
        ['GET',    'tickets/categories',                        'any_auth', []],
        ['GET',    'tickets',                                   'requester_any', []],
        ['POST',   'tickets',                                   'requester_any', []],
        ['GET',    'tickets/{ticket}',                          'requester', []],
        ['POST',   'tickets/{ticket}/replies',                  'requester', []],
        ['POST',   'tickets/{ticket}/confirm',                  'requester', []],
        ['POST',   'tickets/{ticket}/return',                   'requester', []],

        ['GET',    'admin/tickets',                             'staff', []],
        ['GET',    'admin/tickets/{ticket}',                    'staff', []],
        ['POST',   'admin/tickets/{ticket}/claim',              'staff', ['Claim']],
        ['POST',   'admin/tickets/{ticket}/release',            'staff', ['Claim', 'Manage']],
        ['POST',   'admin/tickets/{ticket}/replies',            'staff', ['Reply']],
        ['POST',   'admin/tickets/{ticket}/resolve',            'staff', ['Resolve']],
        ['GET',    'admin/tickets/categories',                  'staff', ['Manage']],
        ['POST',   'admin/tickets/categories',                  'staff', ['Manage']],
        ['PATCH',  'admin/tickets/categories/{category}',       'staff', ['Manage']],
        ['GET',    'admin/tickets/blocks',                      'staff', ['Manage']],
        ['POST',   'admin/tickets/blocks',                      'staff', ['Manage']],
        ['DELETE', 'admin/tickets/blocks/{block}',              'staff', ['Manage']],
        ['GET',    'admin/tickets/summary',                     'staff', ['Manage']],
        ['GET',    'admin/tickets/export',                      'staff', ['Manage']],
        ['POST',   'admin/tickets/{ticket}/spam',               'staff', ['Manage']],

        ['GET',    'public/tickets/form',                       'public', []],
        ['POST',   'public/tickets/start',                      'public', []],
        ['POST',   'public/tickets/confirm-email',              'public', []],
        ['POST',   'public/tickets/resend-link',                'public', []],
        ['GET',    'public/tickets/track',                      'public', []],
        ['POST',   'public/tickets/track/replies',              'public', []],
        ['POST',   'public/tickets/track/confirm',              'public', []],
        ['POST',   'public/tickets/track/return',               'public', []],
    ];
}

/** Routes whose target may legitimately be reported missing (nothing to find with a stand-in id). */
const TK_SWEEP_ALLOW_404 = ['admin/tickets/blocks/{block}'];

function tkSweepActors(): array
{
    $staff = fn (array $a) => ['kind' => 'staff', 'actions' => $a, 'user' => tkApiStaff($a)];

    return [
        'owner'          => ['kind' => 'owner', 'actions' => [], 'user' => tkApiRequester(SystemUser::ROLE_STUDENT)],
        'other student'  => ['kind' => 'requester', 'actions' => [], 'user' => tkApiRequester(SystemUser::ROLE_STUDENT)],
        'alumni'         => ['kind' => 'requester', 'actions' => [], 'user' => tkApiRequester(SystemUser::ROLE_ALUMNI)],
        'undergrad'      => ['kind' => 'requester', 'actions' => [], 'user' => tkApiRequester(SystemUser::ROLE_UNDERGRAD_REQUESTOR)],
        'staff none'     => $staff([]),
        'staff view'     => $staff(['View']),
        'staff claim'    => $staff(['View', 'Claim']),
        'staff reply'    => $staff(['View', 'Reply']),
        'staff resolve'  => $staff(['View', 'Resolve']),
        'staff no-view'  => $staff(['Claim', 'Reply', 'Resolve']),
        'manager'        => $staff(['View', 'Manage']),
        'super admin'    => ['kind' => 'super', 'actions' => [], 'user' => tkStaff([], SystemUser::ROLE_SUPER_ADMIN)],
    ];
}

/** 'allow' or the exact denial status. */
function tkSweepExpected(string $routeKind, array $need, bool $isTicketRoute, string $actorName, array $actor): string
{
    if ($actorName === 'guest') {
        return $routeKind === 'public' ? 'allow' : '401';
    }

    $isRequesterSide = in_array($actor['kind'], ['owner', 'requester'], true);
    $isStaffSide     = in_array($actor['kind'], ['staff', 'super'], true);

    return match ($routeKind) {
        'any_auth'      => 'allow',
        'public'        => 'allow',
        'requester_any' => $isRequesterSide ? 'allow' : '403',
        'requester'     => $actor['kind'] === 'owner' ? 'allow' : ($isRequesterSide ? '404' : '403'),
        'staff'         => ! $isStaffSide ? '403'
            : ($actor['kind'] === 'super' ? 'allow'
            : (! in_array('View', $actor['actions'], true) ? '403'
            : ($need === [] || array_intersect($need, $actor['actions']) !== [] ? 'allow' : '403'))),
    };
}

function tkSweepPayload(string $uri): array
{
    return match (true) {
        str_ends_with($uri, '/replies')  => ['body' => 'Sweep reply text.'],
        str_ends_with($uri, '/resolve')  => ['resolution_note' => 'Sweep resolution note.'],
        default                          => [],
    };
}

/** Fresh ticket per call so one call's state change never decides the next call's status. */
function tkSweepUrl(string $uri, array $actor, SystemUser $owner): string
{
    $ticket = tkOpenTicket($owner);

    // Releasing needs a ticket the caller holds, otherwise the policy (correctly) refuses a non-claimer.
    if (str_ends_with($uri, '/release') && $actor['kind'] === 'staff' && in_array('Claim', $actor['actions'], true)) {
        tkService()->claim($ticket, $actor['user']);
    }

    return '/api/v1/' . str_replace(
        ['{ticket}', '{category}', '{block}'],
        [$ticket->public_id, (string) TicketCategory::query()->value('id'), '999999'],
        $uri,
    );
}

function tkSweepCall(string $method, string $url, array $payload, ?SystemUser $user, array $headers = [])
{
    app('auth')->forgetGuards();
    if ($user) {
        tkApiAs($user);
    }

    return test()->json($method, $url, $payload, $headers);
}

test('authenticated routes: every caller gets exactly the right answer, flag on', function () {
    tkApiOn();
    $actors = ['guest' => ['kind' => 'guest', 'actions' => [], 'user' => null]] + tkSweepActors();
    $owner  = $actors['owner']['user'];

    $failures = [];
    foreach (tkSweepCatalog() as [$method, $uri, $kind, $need]) {
        if ($kind === 'public') {
            continue;
        }

        foreach ($actors as $name => $actor) {
            $expected = tkSweepExpected($kind, $need, str_contains($uri, '{ticket}'), $name, $actor);
            $url      = tkSweepUrl($uri, $actor, $owner);
            $status   = tkSweepCall($method, $url, tkSweepPayload($uri), $actor['user'])->getStatusCode();

            $ok = $expected === 'allow'
                ? ! in_array($status, [401, 403], true) && ($status !== 404 || in_array($uri, TK_SWEEP_ALLOW_404, true))
                : $status === (int) $expected;

            if (! $ok) {
                $failures[] = sprintf('%-6s %-42s as %-14s expected %-5s got %d', $method, $uri, $name, $expected, $status);
            }
        }
    }

    Assert::assertSame([], $failures, "Authorization matrix violations:\n" . implode("\n", $failures));
});

test('every ticket route answers 404 while the flag is off', function () {
    $super = tkStaff([], SystemUser::ROLE_SUPER_ADMIN);
    $id    = str_repeat('a', 26);

    $failures = [];
    foreach (tkSweepCatalog() as [$method, $uri, $kind]) {
        $url    = '/api/v1/' . str_replace(['{ticket}', '{category}', '{block}'], [$id, '1', '1'], $uri);
        $caller = $kind === 'public' ? null : $super;
        $status = tkSweepCall($method, $url, [], $caller, ['X-Ticket-Token' => str_repeat('a', 43)])->getStatusCode();

        if ($status !== 404) {
            $failures[] = "{$method} {$uri} answered {$status} with the flag off";
        }
    }

    Assert::assertSame([], $failures, implode("\n", $failures));
});

test('public routes: no-token, garbage-token and empty-body behaviour', function () {
    tkApiOn();
    $base = '/api/v1/public/tickets';

    tkSweepCall('GET', "{$base}/form", [], null)->assertOk();
    tkSweepCall('POST', "{$base}/start", [], null)->assertStatus(422);
    tkSweepCall('POST', "{$base}/confirm-email", [], null)->assertStatus(422);

    // Same 404 whether the token is missing, malformed or simply unknown.
    foreach (['track' => 'GET', 'track/replies' => 'POST', 'track/confirm' => 'POST', 'track/return' => 'POST'] as $path => $method) {
        $none    = tkSweepCall($method, "{$base}/{$path}", ['body' => 'x'], null);
        $garbage = tkSweepCall($method, "{$base}/{$path}", ['body' => 'x'], null, ['X-Ticket-Token' => str_repeat('z', 43)]);

        Assert::assertSame(404, $none->getStatusCode(), "{$path} without a token");
        Assert::assertSame(404, $garbage->getStatusCode(), "{$path} with an unknown token");
        Assert::assertSame($none->getContent(), $garbage->getContent(), "{$path}: missing and unknown tokens must look identical");
    }
});

test('a signed-in caller cannot use a public magic link as a back door to staff data', function () {
    tkApiOn();
    [$ticket, $token] = tkPubTicketForSweep();

    // A staff session plus a valid public token still gets only the public (requester-safe) view.
    tkApiAs(tkApiStaff(['View', 'Claim', 'Reply', 'Resolve', 'Manage']));
    $body = test()->getJson('/api/v1/public/tickets/track', ['X-Ticket-Token' => $token])->assertOk()->json();

    $flat = json_encode($body);
    foreach (['claimed_by', 'sla', 'resolved_by', 'event_hash', 'requester_ip'] as $leak) {
        Assert::assertStringNotContainsString($leak, $flat, "public view must not expose {$leak}");
    }
});

test('the catalogue covers every registered ticket route', function () {
    $registered = collect(app('router')->getRoutes()->getRoutes())
        ->flatMap(function ($route) {
            $uri = preg_replace('#^api/v1/#', '', $route->uri());

            return preg_match('#(^|/)tickets(/|$)#', $uri)
                ? collect($route->methods())->reject(fn ($m) => in_array($m, ['HEAD', 'OPTIONS'], true))->map(fn ($m) => "{$m} {$uri}")
                : [];
        })
        ->unique()->sort()->values()->all();

    $catalogued = collect(tkSweepCatalog())->map(fn ($r) => "{$r[0]} {$r[1]}")->sort()->values()->all();

    Assert::assertSame([], array_values(array_diff($registered, $catalogued)), 'Ticket routes missing from the authorization catalogue');
    Assert::assertSame([], array_values(array_diff($catalogued, $registered)), 'Catalogue lists routes that no longer exist');
});

function tkPubTicketForSweep(): array
{
    require_once __DIR__ . '/../../Support/TicketPublicTestHelpers.php';

    return tkPubTicket();
}
