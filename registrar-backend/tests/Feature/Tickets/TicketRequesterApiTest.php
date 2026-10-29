<?php

use App\Models\SystemUser;
use App\Models\Ticket;
use App\Models\TicketCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

require_once __DIR__ . '/../../Support/TicketApiTestHelpers.php';

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

// ═════════════════════════════════════════════════════════════════════════════
// Gates: flag, authentication, role
// ═════════════════════════════════════════════════════════════════════════════

test('every requester route answers 404 while the flag is off', function () {
    tkApiAs(tkApiRequester());
    $id = str_repeat('a', 26);

    $this->getJson('/api/v1/tickets')->assertNotFound();
    $this->postJson('/api/v1/tickets', tkApiPayload())->assertNotFound();
    $this->getJson(tkApiUrl($id))->assertNotFound();
    $this->postJson(tkApiUrl($id, '/replies'), ['body' => 'hello'])->assertNotFound();
    $this->postJson(tkApiUrl($id, '/confirm'))->assertNotFound();
    $this->postJson(tkApiUrl($id, '/return'))->assertNotFound();
});

test('requester routes require authentication', function () {
    tkApiOn();

    $this->getJson('/api/v1/tickets')->assertUnauthorized();
    $this->postJson('/api/v1/tickets', tkApiPayload())->assertUnauthorized();
});

test('staff cannot use the requester routes', function () {
    tkApiOn();
    tkApiAs(tkApiStaff(['View', 'Claim', 'Reply', 'Resolve', 'Manage']));

    $this->postJson('/api/v1/tickets', tkApiPayload())->assertForbidden();
    $this->getJson('/api/v1/tickets')->assertForbidden();
});

test('students, alumni and undergrad requestors may all file tickets', function () {
    tkApiOn();

    foreach ([SystemUser::ROLE_STUDENT, SystemUser::ROLE_ALUMNI, SystemUser::ROLE_UNDERGRAD_REQUESTOR] as $role) {
        tkApiAs(tkApiRequester($role));
        $this->postJson('/api/v1/tickets', tkApiPayload())->assertCreated();
    }
});

// ═════════════════════════════════════════════════════════════════════════════
// Create
// ═════════════════════════════════════════════════════════════════════════════

test('a requester files a ticket and receives it with a number and its first message', function () {
    tkApiOn();
    $user = tkApiAs(tkApiRequester());

    $data = $this->postJson('/api/v1/tickets', tkApiPayload())->assertCreated()->json('data');

    expect($data['id'])->toMatch('/^[0-9a-z]{26}$/')
        ->and($data['ticket_number'])->toMatch('/^TKT-\d{4}-\d{5}$/')
        ->and($data['status'])->toBe('open')
        ->and($data['priority'])->toBe('high')
        ->and($data['category']['code'])->toBe('CAT-01')
        ->and($data['messages'])->toHaveCount(1)
        ->and($data['messages'][0]['sender_label'])->toBe('You')
        ->and($data['actions'])->toBe(['can_reply' => true, 'can_confirm' => false, 'can_return' => false])
        ->and($data)->not->toHaveKeys(['requester_ip', 'claimed_by', 'claimed_by_user_id', 'events']);

    $this->assertDatabaseHas('tickets', [
        'requester_user_id' => $user->user_id,
        'requester_ip'      => '127.0.0.1',
        'priority_weight'   => 3,
    ]);
});

test('priority comes from the category, never from the request', function () {
    tkApiOn();
    tkApiAs(tkApiRequester());

    $data = $this->postJson('/api/v1/tickets', tkApiPayload(['category_code' => 'CAT-04', 'priority' => 'high']))
        ->assertCreated()->json('data');

    expect($data['priority'])->toBe('low');
});

test('create rejects missing fields, a short description and an unknown category', function () {
    tkApiOn();
    tkApiAs(tkApiRequester());

    $this->postJson('/api/v1/tickets', [])
        ->assertUnprocessable()->assertJsonValidationErrors(['category_code', 'subject', 'description']);

    $this->postJson('/api/v1/tickets', tkApiPayload(['description' => 'too short']))
        ->assertUnprocessable()->assertJsonValidationErrors(['description']);

    $this->postJson('/api/v1/tickets', tkApiPayload(['category_code' => 'CAT-99']))
        ->assertUnprocessable()->assertJsonValidationErrors(['category_code']);
});

test('create rejects an over-long subject and an over-long description', function () {
    tkApiOn();
    tkApiAs(tkApiRequester());

    $this->postJson('/api/v1/tickets', tkApiPayload(['subject' => str_repeat('x', 151)]))
        ->assertUnprocessable()->assertJsonValidationErrors(['subject']);

    $this->postJson('/api/v1/tickets', tkApiPayload(['description' => str_repeat('x', 5001)]))
        ->assertUnprocessable()->assertJsonValidationErrors(['description']);
});

test('a deactivated category cannot be filed under', function () {
    tkApiOn();
    tkApiAs(tkApiRequester());
    TicketCategory::where('code', 'CAT-04')->update(['is_active' => false]);

    $this->postJson('/api/v1/tickets', tkApiPayload(['category_code' => 'CAT-04']))
        ->assertUnprocessable()->assertJsonValidationErrors(['category_code']);
});

test('line breaks in a subject are collapsed to single spaces', function () {
    tkApiOn();
    tkApiAs(tkApiRequester());

    $data = $this->postJson('/api/v1/tickets', tkApiPayload(['subject' => "Line one\n\n  line   two"]))
        ->assertCreated()->json('data');

    expect($data['subject'])->toBe('Line one line two');
});

test('creating tickets is rate limited per user and answers 429 with Retry-After', function () {
    tkApiOn();
    config(['tickets.rate_limits.authenticated.tickets_per_window' => 2]);
    tkApiAs(tkApiRequester());

    $this->postJson('/api/v1/tickets', tkApiPayload())->assertCreated();
    $this->postJson('/api/v1/tickets', tkApiPayload())->assertCreated();

    $blocked = $this->postJson('/api/v1/tickets', tkApiPayload())->assertStatus(429)->assertHeader('Retry-After');

    expect($blocked->json('code'))->toBe('rate_limited')
        ->and($blocked->json('retry_after'))->toBeInt()->toBeGreaterThan(0);
});

test('the create limit is per user, not shared', function () {
    tkApiOn();
    config(['tickets.rate_limits.authenticated.tickets_per_window' => 1]);

    tkApiAs(tkApiRequester());
    $this->postJson('/api/v1/tickets', tkApiPayload())->assertCreated();
    $this->postJson('/api/v1/tickets', tkApiPayload())->assertStatus(429);

    tkApiAs(tkApiRequester());
    $this->postJson('/api/v1/tickets', tkApiPayload())->assertCreated();
});

// ═════════════════════════════════════════════════════════════════════════════
// Read: ownership
// ═════════════════════════════════════════════════════════════════════════════

test('a requester reads their own ticket with its conversation', function () {
    tkApiOn();
    $owner  = tkApiAs(tkApiRequester());
    $ticket = tkOpenTicket($owner);

    $data = $this->getJson(tkApiUrl($ticket->public_id))->assertOk()->json('data');

    expect($data['id'])->toBe($ticket->public_id)
        ->and($data['messages'])->toHaveCount(1)
        ->and($data['messages'][0]['body'])->toBe('My official receipt was rejected at the counter.');
});

test('a requester cannot read another requester\'s ticket, and gets a 404 not a 403', function () {
    tkApiOn();
    $other  = tkApiRequester();
    $ticket = tkOpenTicket($other);

    tkApiAs(tkApiRequester());
    $this->getJson(tkApiUrl($ticket->public_id))->assertNotFound();
});

test('a requester cannot act on another requester\'s ticket', function () {
    tkApiOn();
    $ticket = tkOpenTicket(tkApiRequester());
    tkService()->resolve($ticket, tkStaff(), 'Fixed it for you, please check.');

    tkApiAs(tkApiRequester());

    $this->postJson(tkApiUrl($ticket->public_id, '/replies'), ['body' => 'hello'])->assertNotFound();
    $this->postJson(tkApiUrl($ticket->public_id, '/confirm'))->assertNotFound();
    $this->postJson(tkApiUrl($ticket->public_id, '/return'))->assertNotFound();

    expect($ticket->fresh()->status->value)->toBe('resolved');
});

test('a ticket id that does not exist is a 404', function () {
    tkApiOn();
    tkApiAs(tkApiRequester());

    $this->getJson(tkApiUrl('01h' . str_repeat('z', 23)))->assertNotFound();
});

test('a public ticket (no account) is never visible through the requester routes', function () {
    tkApiOn();
    $ticket = Ticket::factory()->public()->create();

    tkApiAs(tkApiRequester());
    $this->getJson(tkApiUrl($ticket->public_id))->assertNotFound();
});

test('a requester never sees who claimed or resolved their ticket', function () {
    tkApiOn();
    $owner  = tkApiAs(tkApiRequester());
    $ticket = tkOpenTicket($owner);
    $staff  = tkApiStaff(first: 'Zephyrine', last: 'Quillfeather');
    tkService()->claim($ticket, $staff);
    tkService()->replyAsStaff($ticket, $staff, 'We are looking into it.');
    tkService()->resolve($ticket, $staff, 'Your receipt has been recorded.');

    $data = $this->getJson(tkApiUrl($ticket->public_id))->assertOk()->json('data');
    $json = json_encode($data);

    expect($data['messages'][1]['sender_label'])->toBe('Registrar Staff')
        ->and($data['resolution_note'])->toBe('Your receipt has been recorded.')
        ->and($json)->not->toContain('Zephyrine')->not->toContain('Quillfeather')
        ->and($data)->not->toHaveKeys(['claimed_by', 'resolved_by']);
});

test('the resolution note is hidden while the ticket is still being worked', function () {
    tkApiOn();
    $owner  = tkApiAs(tkApiRequester());
    $ticket = tkOpenTicket($owner);

    $data = $this->getJson(tkApiUrl($ticket->public_id))->json('data');

    expect($data)->not->toHaveKey('resolution_note');
});

// ═════════════════════════════════════════════════════════════════════════════
// List mine
// ═════════════════════════════════════════════════════════════════════════════

test('a requester lists only their own tickets, newest activity first', function () {
    tkApiOn();
    $me    = tkApiRequester();
    $other = tkApiRequester();

    Carbon::setTestNow('2026-10-28 01:00:00');
    $first = tkOpenTicket($me);
    Carbon::setTestNow('2026-10-28 01:10:00');
    $second = tkOpenTicket($me);
    tkOpenTicket($other);

    tkApiAs($me);
    $ids = $this->getJson('/api/v1/tickets')->assertOk()->json('data.*.id');

    expect($ids)->toBe([$second->public_id, $first->public_id]);
});

test('the list is cursor paginated without gaps or repeats', function () {
    tkApiOn();
    $me = tkApiAs(tkApiRequester());

    $created = [];
    foreach (range(1, 5) as $minute) {
        Carbon::setTestNow(Carbon::parse('2026-10-28 01:00:00')->addMinutes($minute));
        $created[] = tkOpenTicket($me)->public_id;
    }
    $expected = array_reverse($created);

    $seen = [];
    $url  = '/api/v1/tickets?per_page=2';
    for ($i = 0; $i < 5 && $url !== null; $i++) {
        $page = $this->getJson($url)->assertOk();
        $seen = array_merge($seen, $page->json('data.*.id'));
        $url  = $page->json('links.next');
    }

    expect($seen)->toBe($expected);
});

test('the list can be filtered by status and rejects an invalid one', function () {
    tkApiOn();
    $me      = tkApiAs(tkApiRequester());
    $open    = tkOpenTicket($me);
    $solved  = tkOpenTicket($me);
    tkService()->resolve($solved, tkStaff(), 'All sorted out for you.');

    $ids = $this->getJson('/api/v1/tickets?status=resolved')->assertOk()->json('data.*.id');
    expect($ids)->toBe([$solved->public_id]);

    $this->getJson('/api/v1/tickets?status=nonsense')->assertUnprocessable();
    $this->getJson('/api/v1/tickets?per_page=500')->assertUnprocessable();
});

test('list rows are summaries: no messages and no internal fields', function () {
    tkApiOn();
    $me = tkApiAs(tkApiRequester());
    tkOpenTicket($me);

    $row = $this->getJson('/api/v1/tickets')->json('data.0');

    expect($row)->not->toHaveKeys(['messages', 'actions', 'requester_ip', 'claimed_by']);
});

// ═════════════════════════════════════════════════════════════════════════════
// Reply
// ═════════════════════════════════════════════════════════════════════════════

test('a requester replies and gets the updated ticket back', function () {
    tkApiOn();
    $me     = tkApiAs(tkApiRequester());
    $ticket = tkOpenTicket($me);

    $data = $this->postJson(tkApiUrl($ticket->public_id, '/replies'), ['body' => 'Any update on this?'])
        ->assertCreated()->json('data');

    expect($data['messages'])->toHaveCount(2)
        ->and($data['messages'][1]['body'])->toBe('Any update on this?');
});

test('a reply to a Pending Client ticket moves it back to In Progress', function () {
    tkApiOn();
    $me     = tkApiAs(tkApiRequester());
    $ticket = tkOpenTicket($me);
    tkService()->replyAsStaff($ticket, tkStaff(), 'Which receipt number?', awaitRequester: true);
    expect($ticket->fresh()->status->value)->toBe('pending_client');

    $data = $this->postJson(tkApiUrl($ticket->public_id, '/replies'), ['body' => 'Number 12345.'])
        ->assertCreated()->json('data');

    expect($data['status'])->toBe('in_progress');
});

test('reply validation: empty and over-long bodies are rejected', function () {
    tkApiOn();
    $me     = tkApiAs(tkApiRequester());
    $ticket = tkOpenTicket($me);

    $this->postJson(tkApiUrl($ticket->public_id, '/replies'), [])
        ->assertUnprocessable()->assertJsonValidationErrors(['body']);
    $this->postJson(tkApiUrl($ticket->public_id, '/replies'), ['body' => str_repeat('x', 5001)])
        ->assertUnprocessable()->assertJsonValidationErrors(['body']);
});

test('a reply to a Closed ticket is refused with a 409 and a stable code', function () {
    tkApiOn();
    $me     = tkApiAs(tkApiRequester());
    $ticket = tkOpenTicket($me);
    tkService()->resolve($ticket, tkStaff(), 'Done, please confirm.');
    tkService()->confirm($ticket, $me);

    $res = $this->postJson(tkApiUrl($ticket->public_id, '/replies'), ['body' => 'One more thing'])
        ->assertStatus(409);

    expect($res->json('code'))->toBe('action_not_allowed')
        ->and($res->json('status'))->toBe('closed');
});

test('replies are limited per ticket per hour and answer 429 with Retry-After', function () {
    tkApiOn();
    config(['tickets.rate_limits.replies_per_ticket_per_hour' => 2]);
    $me     = tkApiAs(tkApiRequester());
    $ticket = tkOpenTicket($me);
    $other  = tkOpenTicket($me);

    $this->postJson(tkApiUrl($ticket->public_id, '/replies'), ['body' => 'one'])->assertCreated();
    $this->postJson(tkApiUrl($ticket->public_id, '/replies'), ['body' => 'two'])->assertCreated();

    $blocked = $this->postJson(tkApiUrl($ticket->public_id, '/replies'), ['body' => 'three'])
        ->assertStatus(429)->assertHeader('Retry-After');
    expect($blocked->json('code'))->toBe('rate_limited');

    // A different ticket has its own allowance.
    $this->postJson(tkApiUrl($other->public_id, '/replies'), ['body' => 'one'])->assertCreated();
});

// ═════════════════════════════════════════════════════════════════════════════
// Confirm and return
// ═════════════════════════════════════════════════════════════════════════════

test('the owner confirms a resolved ticket and it closes', function () {
    tkApiOn();
    $me     = tkApiAs(tkApiRequester());
    $ticket = tkOpenTicket($me);
    tkService()->resolve($ticket, tkStaff(), 'Receipt recorded, please confirm.');

    $data = $this->postJson(tkApiUrl($ticket->public_id, '/confirm'))->assertOk()->json('data');

    expect($data['status'])->toBe('closed')
        ->and($data['close_reason'])->toBe('confirmed')
        ->and($data['actions'])->toBe(['can_reply' => false, 'can_confirm' => false, 'can_return' => false]);
});

test('confirming a ticket that is not Resolved is a 409', function () {
    tkApiOn();
    $me     = tkApiAs(tkApiRequester());
    $ticket = tkOpenTicket($me);

    $res = $this->postJson(tkApiUrl($ticket->public_id, '/confirm'))->assertStatus(409);

    expect($res->json('code'))->toBe('invalid_transition')
        ->and($res->json('from'))->toBe('open')
        ->and($res->json('to'))->toBe('closed');
});

test('"this isn\'t resolved" sends the ticket back to In Progress and uses one return', function () {
    tkApiOn();
    $me     = tkApiAs(tkApiRequester());
    $ticket = tkOpenTicket($me);
    tkService()->resolve($ticket, tkStaff(), 'Receipt recorded, please confirm.');

    $data = $this->postJson(tkApiUrl($ticket->public_id, '/return'), ['reason' => 'Still not showing in my account.'])
        ->assertOk()->json('data');

    expect($data['status'])->toBe('in_progress')
        ->and($data['requester_returns'])->toBe(1)
        ->and($data['returns_remaining'])->toBe(1)
        ->and($data)->not->toHaveKey('resolution_note')
        ->and($data['messages'])->toHaveCount(2)
        ->and($data['messages'][1]['body'])->toBe('Still not showing in my account.');
});

test('returning is limited to the configured number of times', function () {
    tkApiOn();
    $me     = tkApiAs(tkApiRequester());
    $staff  = tkStaff();
    $ticket = tkOpenTicket($me);

    foreach ([1, 2] as $round) {
        tkService()->resolve($ticket, $staff, "Resolved attempt {$round}, please confirm.");
        $this->postJson(tkApiUrl($ticket->public_id, '/return'))->assertOk();
    }

    tkService()->resolve($ticket, $staff, 'Resolved attempt 3, please confirm.');

    $res = $this->postJson(tkApiUrl($ticket->public_id, '/return'))->assertStatus(409);
    expect($res->json('code'))->toBe('return_limit_reached')->and($res->json('limit'))->toBe(2);

    // Past the limit a reply would be a hidden return, so it is refused too.
    $this->postJson(tkApiUrl($ticket->public_id, '/replies'), ['body' => 'Please look again'])
        ->assertStatus(409);

    $shown = $this->getJson(tkApiUrl($ticket->public_id))->json('data');
    expect($shown['actions'])->toBe(['can_reply' => false, 'can_confirm' => true, 'can_return' => false]);
});

test('returning a ticket that is not Resolved is a 409', function () {
    tkApiOn();
    $me     = tkApiAs(tkApiRequester());
    $ticket = tkOpenTicket($me);

    $this->postJson(tkApiUrl($ticket->public_id, '/return'))->assertStatus(409)
        ->assertJsonPath('code', 'action_not_allowed');
});

test('a reply during the Resolved window returns the ticket to In Progress', function () {
    tkApiOn();
    $me     = tkApiAs(tkApiRequester());
    $ticket = tkOpenTicket($me);
    tkService()->resolve($ticket, tkStaff(), 'Receipt recorded, please confirm.');

    $data = $this->postJson(tkApiUrl($ticket->public_id, '/replies'), ['body' => 'It is still broken.'])
        ->assertCreated()->json('data');

    expect($data['status'])->toBe('in_progress')->and($data['requester_returns'])->toBe(1);
});
