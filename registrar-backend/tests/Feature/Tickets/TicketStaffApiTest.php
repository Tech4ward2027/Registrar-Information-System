<?php

use App\Models\SystemUser;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/../../Support/TicketApiTestHelpers.php';

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

const TK_QUEUE = '/api/v1/admin/tickets';

// ═════════════════════════════════════════════════════════════════════════════
// Gates: flag, role, module permission
// ═════════════════════════════════════════════════════════════════════════════

test('every staff route answers 404 while the flag is off', function () {
    tkApiAs(tkApiStaff(['View', 'Claim', 'Reply', 'Resolve', 'Manage']));
    $id = str_repeat('a', 26);

    $this->getJson(TK_QUEUE)->assertNotFound();
    $this->getJson(tkApiUrl($id, '', true))->assertNotFound();
    $this->postJson(tkApiUrl($id, '/claim', true))->assertNotFound();
    $this->postJson(tkApiUrl($id, '/release', true))->assertNotFound();
    $this->postJson(tkApiUrl($id, '/replies', true), ['body' => 'x'])->assertNotFound();
    $this->postJson(tkApiUrl($id, '/resolve', true), ['resolution_note' => 'x'])->assertNotFound();
});

test('a student cannot reach any staff route', function () {
    tkApiOn();
    $ticket = tkOpenTicket();
    tkApiAs(tkApiRequester());

    $this->getJson(TK_QUEUE)->assertForbidden();
    $this->getJson(tkApiUrl($ticket->public_id, '', true))->assertForbidden();
    $this->postJson(tkApiUrl($ticket->public_id, '/claim', true))->assertForbidden();
});

test('an admin with no ticket permissions is refused everywhere', function () {
    tkApiOn();
    $ticket = tkOpenTicket();
    tkApiAs(tkApiStaff([]));

    $this->getJson(TK_QUEUE)->assertForbidden();
    $this->getJson(tkApiUrl($ticket->public_id, '', true))->assertForbidden();
    $this->postJson(tkApiUrl($ticket->public_id, '/claim', true))->assertForbidden();
    expect($ticket->fresh()->claimed_by_user_id)->toBeNull();
});

test('each action needs its own permission on top of View', function () {
    tkApiOn();

    $viewOnly = tkApiAs(tkApiStaff(['View']));
    $ticket   = tkOpenTicket();

    $this->getJson(tkApiUrl($ticket->public_id, '', true))->assertOk();
    $this->postJson(tkApiUrl($ticket->public_id, '/claim', true))->assertForbidden();
    $this->postJson(tkApiUrl($ticket->public_id, '/replies', true), ['body' => 'hello'])->assertForbidden();
    $this->postJson(tkApiUrl($ticket->public_id, '/resolve', true), ['resolution_note' => 'All done now.'])->assertForbidden();
    $this->postJson(tkApiUrl($ticket->public_id, '/release', true))->assertForbidden();

    expect($ticket->fresh()->status->value)->toBe('open');
});

test('Reply without View is not a way to read a ticket', function () {
    tkApiOn();
    tkApiAs(tkApiStaff(['Reply']));
    $ticket = tkOpenTicket();

    $this->postJson(tkApiUrl($ticket->public_id, '/replies', true), ['body' => 'hello'])->assertForbidden();
    expect($ticket->messages()->count())->toBe(1);
});

test('a Super Admin may work tickets without any policy', function () {
    tkApiOn();
    tkApiAs(tkStaff([], SystemUser::ROLE_SUPER_ADMIN));
    $ticket = tkOpenTicket();

    $this->getJson(TK_QUEUE)->assertOk();
    $this->postJson(tkApiUrl($ticket->public_id, '/claim', true))->assertOk();
});

// ═════════════════════════════════════════════════════════════════════════════
// Queue
// ═════════════════════════════════════════════════════════════════════════════

test('the queue lists urgent tickets first, then oldest first within a priority', function () {
    tkApiOn();
    tkApiAs(tkApiStaff(['View']));

    Carbon::setTestNow('2026-10-28 01:00:00');
    $low = tkOpenTicket(null, 'CAT-04');
    Carbon::setTestNow('2026-10-28 01:05:00');
    $high1 = tkOpenTicket(null, 'CAT-01');
    Carbon::setTestNow('2026-10-28 01:10:00');
    $medium = tkOpenTicket(null, 'CAT-02');
    Carbon::setTestNow('2026-10-28 01:15:00');
    $high2 = tkOpenTicket(null, 'CAT-01');

    $ids = $this->getJson(TK_QUEUE)->assertOk()->json('data.*.id');

    expect($ids)->toBe([$high1->public_id, $high2->public_id, $medium->public_id, $low->public_id]);
});

test('the queue is cursor paginated in that order without gaps or repeats', function () {
    tkApiOn();
    tkApiAs(tkApiStaff(['View']));

    $codes    = ['CAT-04', 'CAT-01', 'CAT-02', 'CAT-01', 'CAT-04', 'CAT-02', 'CAT-01'];
    $created  = [];
    foreach ($codes as $i => $code) {
        Carbon::setTestNow(Carbon::parse('2026-10-28 01:00:00')->addMinutes($i));
        $created[] = tkOpenTicket(null, $code);
    }

    $expected = collect($created)
        ->sortBy([['priority_weight', 'desc'], ['created_at', 'asc'], ['id', 'asc']])
        ->pluck('public_id')->values()->all();

    $seen = [];
    $url  = TK_QUEUE . '?per_page=3';
    for ($i = 0; $i < 6 && $url !== null; $i++) {
        $page = $this->getJson($url)->assertOk();
        $seen = array_merge($seen, $page->json('data.*.id'));
        $url  = $page->json('links.next');
    }

    expect($seen)->toBe($expected);
});

test('by default the queue shows unclaimed tickets that are still being worked', function () {
    tkApiOn();
    $me = tkApiAs(tkApiStaff(['View', 'Claim', 'Resolve']));

    $open    = tkOpenTicket();
    $claimed = tkOpenTicket();
    $solved  = tkOpenTicket();
    tkService()->claim($claimed, tkApiStaff());
    tkService()->resolve($solved, tkApiStaff(), 'Fixed, please confirm.');

    $ids = $this->getJson(TK_QUEUE)->json('data.*.id');

    expect($ids)->toBe([$open->public_id]);
});

test('scope and status filters widen or narrow the queue', function () {
    tkApiOn();
    $me = tkApiAs(tkApiStaff(['View', 'Claim', 'Resolve']));
    $other = tkApiStaff();

    $unclaimed = tkOpenTicket();
    $mine      = tkOpenTicket();
    $theirs    = tkOpenTicket();
    $solved    = tkOpenTicket();
    tkService()->claim($mine, $me);
    tkService()->claim($theirs, $other);
    tkService()->resolve($solved, $other, 'Fixed, please confirm.');

    $mineIds = $this->getJson(TK_QUEUE . '?scope=mine')->json('data.*.id');
    $allIds  = $this->getJson(TK_QUEUE . '?scope=all')->json('data.*.id');
    $solvedIds = $this->getJson(TK_QUEUE . '?status=resolved')->json('data.*.id');

    expect($mineIds)->toBe([$mine->public_id])
        ->and($allIds)->toEqualCanonicalizing([$unclaimed->public_id, $mine->public_id, $theirs->public_id])
        ->and($solvedIds)->toBe([$solved->public_id]);

    $this->getJson(TK_QUEUE . '?scope=everything')->assertUnprocessable();
});

test('priority, category and search filters', function () {
    tkApiOn();
    tkApiAs(tkApiStaff(['View']));

    Carbon::setTestNow('2026-10-28 01:00:00');
    $payment = tkService()->createForUser(tkApiRequester(), tkCategory('CAT-01'), 'Official receipt missing', 'My official receipt was never recorded.');
    $access  = tkService()->createForUser(tkApiRequester(), tkCategory('CAT-02'), 'Cannot log in to portal', 'I cannot log in to the student portal.');
    $percent = tkService()->createForUser(tkApiRequester(), tkCategory('CAT-04'), 'Refund of 100% requested', 'I would like a refund of the full amount paid.');

    expect($this->getJson(TK_QUEUE . '?priority=high')->json('data.*.id'))->toBe([$payment->public_id])
        ->and($this->getJson(TK_QUEUE . '?category=CAT-02')->json('data.*.id'))->toBe([$access->public_id])
        ->and($this->getJson(TK_QUEUE . '?q=RECEIPT')->json('data.*.id'))->toBe([$payment->public_id])
        ->and($this->getJson('/api/v1/admin/tickets?q=' . urlencode($payment->ticket_number))->json('data.*.id'))->toBe([$payment->public_id]);

    // A literal percent sign is searched for, not treated as a wildcard.
    expect($this->getJson(TK_QUEUE . '?q=' . urlencode('100%'))->json('data.*.id'))->toBe([$percent->public_id])
        ->and($this->getJson(TK_QUEUE . '?q=' . urlencode('%'))->json('data.*.id'))->toBe([$percent->public_id])
        ->and($this->getJson(TK_QUEUE . '?q=' . urlencode('_'))->json('data'))->toBe([]);

    $this->getJson(TK_QUEUE . '?category=CAT-99')->assertUnprocessable();
    $this->getJson(TK_QUEUE . '?priority=urgent')->assertUnprocessable();
    $this->getJson(TK_QUEUE . '?per_page=51')->assertUnprocessable();
});

test('queue rows show who filed and who holds the ticket, and never the IP', function () {
    tkApiOn();
    $viewer = tkApiAs(tkApiStaff(['View', 'Claim']));
    $holder = tkApiStaff(['View', 'Claim'], 'Maria', 'Santos');

    $public  = tkService()->createForPublic('Juan Cruz', 'juan@example.com', tkCategory('CAT-05'), 'Portal bug', 'The portal shows an error page.', '198.51.100.4');
    $account = tkOpenTicket();
    tkService()->claim($account, $holder);

    $rows = collect($this->getJson(TK_QUEUE . '?scope=all')->assertOk()->json('data'))->keyBy('id');

    expect($rows[$public->public_id]['requester'])->toBe(['name' => 'Juan Cruz', 'type' => 'public'])
        ->and($rows[$public->public_id]['is_claimed'])->toBeFalse()
        ->and($rows[$public->public_id]['claimed_by'])->toBeNull()
        ->and($rows[$account->public_id]['requester']['type'])->toBe('account')
        ->and($rows[$account->public_id]['claimed_by'])->toBe(['id' => $holder->user_id, 'name' => 'Maria Santos', 'is_me' => false]);

    expect(json_encode($rows->all()))->not->toContain('198.51.100.4')->not->toContain('requester_ip');
});

test('the queue does not run a query per row', function () {
    tkApiOn();
    tkApiAs(tkApiStaff(['View', 'Claim']));

    $addClaimedTickets = function (int $count): void {
        foreach (range(1, $count) as $_) {
            tkService()->claim(tkOpenTicket(), tkApiStaff(['View', 'Claim']));
        }
    };

    $countQueries = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        test()->getJson(TK_QUEUE . '?scope=all')->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $addClaimedTickets(2);
    $few = $countQueries();

    // Each row has its own claimer and requester; the query count must not grow.
    $addClaimedTickets(6);
    $many = $countQueries();

    expect($many)->toBe($few);
});

// ═════════════════════════════════════════════════════════════════════════════
// Show
// ═════════════════════════════════════════════════════════════════════════════

test('staff see the conversation and the activity log, without hash fields', function () {
    tkApiOn();
    $staff  = tkApiAs(tkApiStaff(['View', 'Claim', 'Reply', 'Resolve']));
    $ticket = tkOpenTicket();
    tkService()->claim($ticket, $staff);
    tkService()->replyAsStaff($ticket, $staff, 'Checking with the cashier.');

    $data = $this->getJson(tkApiUrl($ticket->public_id, '', true))->assertOk()->json('data');

    expect(array_column($data['events'], 'type'))->toBe(['created', 'claimed', 'replied'])
        ->and($data['messages'])->toHaveCount(2)
        ->and($data['messages'][1]['sender_name'])->toBe('Ana Reyes')
        ->and($data['events'][1]['actor_name'])->toBe('Ana Reyes')
        ->and($data['claimed_by']['is_me'])->toBeTrue()
        ->and(json_encode($data))->not->toContain('prev_hash')->not->toContain('"hash"')->not->toContain('requester_ip');
});

test('the actions block reflects permission and state', function () {
    tkApiOn();
    tkApiAs(tkApiStaff(['View', 'Claim']));
    $ticket = tkOpenTicket();

    $actions = $this->getJson(tkApiUrl($ticket->public_id, '', true))->json('data.actions');

    expect($actions)->toBe(['can_claim' => true, 'can_release' => false, 'can_reply' => false, 'can_resolve' => false]);
});

test('a public submitter\'s email is shown to staff for a public ticket only', function () {
    tkApiOn();
    tkApiAs(tkApiStaff(['View']));
    $public  = tkService()->createForPublic('Juan Cruz', 'Juan@Example.com', tkCategory('CAT-05'), 'Portal bug', 'The portal shows an error page.');
    $account = tkOpenTicket();

    expect($this->getJson(tkApiUrl($public->public_id, '', true))->json('data.requester.email'))->toBe('juan@example.com')
        ->and($this->getJson(tkApiUrl($account->public_id, '', true))->json('data.requester.email'))->toBeNull();
});

// ═════════════════════════════════════════════════════════════════════════════
// Claim and release
// ═════════════════════════════════════════════════════════════════════════════

test('claiming moves an Open ticket to In Progress and marks it as mine', function () {
    tkApiOn();
    $staff  = tkApiAs(tkApiStaff());
    $ticket = tkOpenTicket();

    $data = $this->postJson(tkApiUrl($ticket->public_id, '/claim', true))->assertOk()->json('data');

    expect($data['status'])->toBe('in_progress')
        ->and($data['is_claimed'])->toBeTrue()
        ->and($data['claimed_by'])->toBe(['id' => $staff->user_id, 'name' => 'Ana Reyes', 'is_me' => true])
        ->and($data['actions']['can_claim'])->toBeFalse()
        ->and($data['actions']['can_release'])->toBeTrue();
});

test('a second claim loses with a 409 that says who has the ticket', function () {
    tkApiOn();
    $ticket = tkOpenTicket();
    $first  = tkApiStaff(first: 'Maria', last: 'Santos');
    tkService()->claim($ticket, $first);

    tkApiAs(tkApiStaff());
    $res = $this->postJson(tkApiUrl($ticket->public_id, '/claim', true))->assertStatus(409);

    expect($res->json('code'))->toBe('already_claimed')
        ->and($res->json('claimed_by'))->toBe(['id' => $first->user_id, 'name' => 'Maria Santos']);
    expect($ticket->fresh()->claimed_by_user_id)->toBe($first->user_id);
});

test('claiming your own ticket again is a 409, and a Resolved or Closed ticket cannot be claimed', function () {
    tkApiOn();
    $staff  = tkApiAs(tkApiStaff());
    $ticket = tkOpenTicket();

    $this->postJson(tkApiUrl($ticket->public_id, '/claim', true))->assertOk();
    $this->postJson(tkApiUrl($ticket->public_id, '/claim', true))->assertStatus(409)->assertJsonPath('code', 'already_claimed');

    $solved = tkOpenTicket();
    tkService()->resolve($solved, $staff, 'Fixed, please confirm.');
    $this->postJson(tkApiUrl($solved->public_id, '/claim', true))->assertStatus(409)->assertJsonPath('code', 'action_not_allowed');
});

test('the claimer releases a ticket back to the queue', function () {
    tkApiOn();
    $staff  = tkApiAs(tkApiStaff());
    $ticket = tkOpenTicket();
    tkService()->claim($ticket, $staff);

    $data = $this->postJson(tkApiUrl($ticket->public_id, '/release', true))->assertOk()->json('data');

    expect($data['is_claimed'])->toBeFalse()->and($data['claimed_by'])->toBeNull()
        ->and($data['status'])->toBe('in_progress');
});

test('another staff member cannot release it, but one who can Manage can', function () {
    tkApiOn();
    $ticket = tkOpenTicket();
    tkService()->claim($ticket, tkApiStaff());

    tkApiAs(tkApiStaff(['View', 'Claim']));
    $this->postJson(tkApiUrl($ticket->public_id, '/release', true))->assertForbidden();
    expect($ticket->fresh()->claimed_by_user_id)->not->toBeNull();

    tkApiAs(tkApiStaff(['View', 'Manage']));
    $this->postJson(tkApiUrl($ticket->public_id, '/release', true))->assertOk();
    expect($ticket->fresh()->claimed_by_user_id)->toBeNull();
});

test('releasing a ticket nobody holds is a 409', function () {
    tkApiOn();
    tkApiAs(tkApiStaff(['View', 'Manage']));
    $ticket = tkOpenTicket();

    $this->postJson(tkApiUrl($ticket->public_id, '/release', true))->assertStatus(409)->assertJsonPath('code', 'not_claimed');
});

// ═════════════════════════════════════════════════════════════════════════════
// Reply
// ═════════════════════════════════════════════════════════════════════════════

test('a staff reply to an unclaimed ticket claims it for the replier', function () {
    tkApiOn();
    $staff  = tkApiAs(tkApiStaff());
    $ticket = tkOpenTicket();

    $data = $this->postJson(tkApiUrl($ticket->public_id, '/replies', true), ['body' => 'Looking into this now.'])
        ->assertCreated()->json('data');

    expect($data['messages'])->toHaveCount(2)
        ->and($data['status'])->toBe('in_progress')
        ->and($data['claimed_by']['is_me'])->toBeTrue()
        ->and($data['awaiting_first_response'])->toBeFalse();
});

test('replying with await_requester sets Pending Client', function () {
    tkApiOn();
    tkApiAs(tkApiStaff());
    $ticket = tkOpenTicket();

    $data = $this->postJson(tkApiUrl($ticket->public_id, '/replies', true), ['body' => 'Which receipt?', 'await_requester' => true])
        ->assertCreated()->json('data');

    expect($data['status'])->toBe('pending_client');
});

test('staff reply validation and the closed-ticket refusal', function () {
    tkApiOn();
    $staff  = tkApiAs(tkApiStaff());
    $ticket = tkOpenTicket();

    $this->postJson(tkApiUrl($ticket->public_id, '/replies', true), [])->assertUnprocessable()->assertJsonValidationErrors(['body']);
    $this->postJson(tkApiUrl($ticket->public_id, '/replies', true), ['body' => 'ok', 'await_requester' => 'maybe'])
        ->assertUnprocessable()->assertJsonValidationErrors(['await_requester']);

    tkService()->resolve($ticket, $staff, 'Fixed, please confirm.');
    $this->postJson(tkApiUrl($ticket->public_id, '/replies', true), ['body' => 'one more thing'])
        ->assertStatus(409)->assertJsonPath('code', 'action_not_allowed');
});

// ═════════════════════════════════════════════════════════════════════════════
// Resolve
// ═════════════════════════════════════════════════════════════════════════════

test('resolving needs a real note and then marks the ticket Resolved', function () {
    tkApiOn();
    $staff  = tkApiAs(tkApiStaff());
    $ticket = tkOpenTicket();

    $this->postJson(tkApiUrl($ticket->public_id, '/resolve', true), [])->assertUnprocessable()->assertJsonValidationErrors(['resolution_note']);
    $this->postJson(tkApiUrl($ticket->public_id, '/resolve', true), ['resolution_note' => 'done'])->assertUnprocessable();

    $data = $this->postJson(tkApiUrl($ticket->public_id, '/resolve', true), ['resolution_note' => 'Your receipt is now recorded.'])
        ->assertOk()->json('data');

    expect($data['status'])->toBe('resolved')
        ->and($data['resolution_note'])->toBe('Your receipt is now recorded.')
        ->and($data['resolved_by'])->toBe('Ana Reyes')
        ->and($data['is_claimed'])->toBeFalse()
        ->and($data['auto_close_at'])->not->toBeNull()
        ->and($data['actions'])->toBe(['can_claim' => false, 'can_release' => false, 'can_reply' => false, 'can_resolve' => false]);
});

test('resolving an already Resolved ticket is a 409', function () {
    tkApiOn();
    $staff  = tkApiAs(tkApiStaff());
    $ticket = tkOpenTicket();
    tkService()->resolve($ticket, $staff, 'Fixed, please confirm.');

    $this->postJson(tkApiUrl($ticket->public_id, '/resolve', true), ['resolution_note' => 'Fixed again, please confirm.'])
        ->assertStatus(409)->assertJsonPath('code', 'invalid_transition');
});

test('any staff with Resolve may resolve by default, only the claimer when configured', function () {
    tkApiOn();
    $ticket = tkOpenTicket();
    tkService()->claim($ticket, tkApiStaff());

    config(['tickets.resolve_requires_claimer' => true]);
    tkApiAs(tkApiStaff(['View', 'Resolve']));
    $this->postJson(tkApiUrl($ticket->public_id, '/resolve', true), ['resolution_note' => 'Fixed, please confirm.'])->assertForbidden();

    config(['tickets.resolve_requires_claimer' => false]);
    $this->postJson(tkApiUrl($ticket->public_id, '/resolve', true), ['resolution_note' => 'Fixed, please confirm.'])->assertOk();
});

// ═════════════════════════════════════════════════════════════════════════════
// Ticket ids in staff URLs
// ═════════════════════════════════════════════════════════════════════════════

test('staff routes address tickets by public id only', function () {
    tkApiOn();
    tkApiAs(tkApiStaff());
    $ticket = tkOpenTicket();

    $this->getJson('/api/v1/admin/tickets/' . $ticket->getKey())->assertNotFound();
    $this->getJson('/api/v1/admin/tickets/' . strtoupper($ticket->public_id))->assertNotFound();
    $this->getJson(tkApiUrl($ticket->public_id, '', true))->assertOk();
});
