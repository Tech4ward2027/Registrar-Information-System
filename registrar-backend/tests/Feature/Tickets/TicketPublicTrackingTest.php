<?php

use App\Mail\TicketPublicAccessLinkMail;
use App\Models\Ticket;
use App\Models\TicketAccessToken;
use App\Services\Tickets\PublicSubmission\TicketAccessTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

require_once __DIR__ . '/../../Support/TicketPublicTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(fn () => tkPubSetup());
afterEach(fn () => Carbon::setTestNow());

// ═════════════════════════════════════════════════════════════════════════════
// The token gate
// ═════════════════════════════════════════════════════════════════════════════

test('a valid magic-link token opens its ticket and shows the requester view', function () {
    [$ticket, $token] = tkPubTicket();
    [$url, $headers] = tkPubTrack($token);

    $data = $this->getJson($url, $headers)->assertOk()->json('data');

    expect($data['id'])->toBe($ticket->public_id)
        ->and($data['ticket_number'])->toBe($ticket->ticket_number)
        ->and($data['messages'])->toHaveCount(1)
        ->and($data['actions']['can_reply'])->toBeTrue()
        ->and(json_encode($data))->not->toContain('juan@example.com')->not->toContain('203.0.113.5')->not->toContain('claimed_by');
});

test('missing, wrong, tampered and malformed tokens all answer the same 404', function () {
    [, $token] = tkPubTicket();
    $flipped = substr($token, 0, -1) . (str_ends_with($token, 'A') ? 'B' : 'A');

    $bodies = collect([
        [],                                                  // no header
        ['X-Ticket-Token' => $flipped],                      // one character changed
        ['X-Ticket-Token' => strrev($token)],
        ['X-Ticket-Token' => str_repeat('a', 43)],           // well-formed, unknown
        ['X-Ticket-Token' => 'short'],                       // malformed
        ['X-Ticket-Token' => $token . 'x'],                  // appended
    ])->map(fn ($h) => $this->getJson('/api/v1/public/tickets/track', $h)->assertNotFound()->json());

    expect($bodies->pluck('code')->unique()->all())->toBe(['invalid_ticket_link'])
        ->and($bodies->pluck('message')->unique())->toHaveCount(1);
});

test('the token is only read from the header, never from the URL or the body', function () {
    [, $token] = tkPubTicket();

    $this->getJson('/api/v1/public/tickets/track?token=' . $token)->assertNotFound();
    $this->postJson('/api/v1/public/tickets/track/replies', ['body' => 'hello', 'token' => $token])->assertNotFound();
});

test('there is no ticket id in a tracking URL to enumerate', function () {
    [$ticket, $token] = tkPubTicket();

    $this->getJson('/api/v1/public/tickets/track/' . $ticket->public_id, ['X-Ticket-Token' => $token])->assertNotFound();
});

test('a token never opens a different ticket', function () {
    [$a, $tokenA] = tkPubTicket('a@example.com');
    [$b]          = tkPubTicket('b@example.com');

    $data = $this->getJson('/api/v1/public/tickets/track', ['X-Ticket-Token' => $tokenA])->json('data');

    expect($data['id'])->toBe($a->public_id)->not->toBe($b->public_id);
});

test('a token for a signed-in requester\'s ticket is never valid', function () {
    $ticket = tkOpenTicket();   // an account ticket
    $plain  = app(TicketAccessTokenService::class)->issue($ticket);

    $this->getJson('/api/v1/public/tickets/track', ['X-Ticket-Token' => $plain])->assertNotFound();
});

test('an anonymized ticket can no longer be opened', function () {
    [$ticket, $token] = tkPubTicket();
    $ticket->forceFill(['anonymized_at' => now()])->save();

    $this->getJson('/api/v1/public/tickets/track', ['X-Ticket-Token' => $token])->assertNotFound();
});

// ═════════════════════════════════════════════════════════════════════════════
// Lifetime: until Closed, plus about 7 days
// ═════════════════════════════════════════════════════════════════════════════

test('the link stays valid while the ticket is open, however long that is', function () {
    [, $token] = tkPubTicket();

    Carbon::setTestNow(now()->addDays(60));
    $this->getJson('/api/v1/public/tickets/track', ['X-Ticket-Token' => $token])->assertOk();
});

test('the link works for 7 days after Closed and then stops', function () {
    [$ticket, $token] = tkPubTicket();
    tkService()->resolve($ticket, tkStaff(), 'Fixed, please confirm.');
    tkService()->confirm($ticket, null);   // Closed now

    Carbon::setTestNow(now()->addDays(6)->addHours(23));
    $this->getJson('/api/v1/public/tickets/track', ['X-Ticket-Token' => $token])->assertOk()->assertJsonPath('data.status', 'closed');

    Carbon::setTestNow(now()->addHours(2));   // 7 days + 1 hour after closing
    $this->getJson('/api/v1/public/tickets/track', ['X-Ticket-Token' => $token])->assertNotFound();
});

test('the grace period comes from config', function () {
    config(['tickets.public.token_grace_days' => 1]);
    [$ticket, $token] = tkPubTicket();
    tkService()->resolve($ticket, tkStaff(), 'Fixed, please confirm.');
    tkService()->confirm($ticket, null);

    Carbon::setTestNow(now()->addDays(2));
    $this->getJson('/api/v1/public/tickets/track', ['X-Ticket-Token' => $token])->assertNotFound();
});

// ═════════════════════════════════════════════════════════════════════════════
// Token issuing
// ═════════════════════════════════════════════════════════════════════════════

test('tokens are at least 32 bytes, the config can never lower that, and only the hash is stored', function () {
    config(['tickets.public.token_bytes' => 8]);   // config() bypasses the max(32, ...) in the file, so the service must hold the floor itself
    [$ticket] = tkPubTicket();

    $plain = app(TicketAccessTokenService::class)->issue($ticket);

    expect(strlen(base64_decode(strtr($plain, '-_', '+/'))))->toBeGreaterThanOrEqual(32);
    $this->assertDatabaseHas('ticket_access_tokens', ['ticket_id' => $ticket->getKey(), 'token_hash' => hash('sha256', $plain)]);
    expect(json_encode(TicketAccessToken::all()->makeVisible('token_hash')->toArray()))->not->toContain($plain);
});

test('tokens are unique', function () {
    [$ticket] = tkPubTicket();
    $service  = app(TicketAccessTokenService::class);

    $tokens = collect(range(1, 20))->map(fn () => $service->issue($ticket));

    expect($tokens->unique()->count())->toBe(20);
});

test('only the newest few links stay alive per ticket', function () {
    config(['tickets.public.max_access_tokens_per_ticket' => 2]);
    [$ticket, $first] = tkPubTicket();
    $service = app(TicketAccessTokenService::class);

    $second = $service->issue($ticket);
    $third  = $service->issue($ticket);

    expect(TicketAccessToken::where('ticket_id', $ticket->getKey())->count())->toBe(2);
    $this->getJson('/api/v1/public/tickets/track', ['X-Ticket-Token' => $first])->assertNotFound();
    $this->getJson('/api/v1/public/tickets/track', ['X-Ticket-Token' => $second])->assertOk();
    $this->getJson('/api/v1/public/tickets/track', ['X-Ticket-Token' => $third])->assertOk();
});

test('the emailed link carries the token in the fragment, not the query string', function () {
    [, $token] = tkPubTicket();
    $url = app(TicketAccessTokenService::class)->trackUrl($token);

    expect($url)->toContain('/support/ticket#token=' . $token)->not->toContain('?');
});

// ═════════════════════════════════════════════════════════════════════════════
// Reply, confirm, return
// ═════════════════════════════════════════════════════════════════════════════

test('a public submitter replies through the link', function () {
    [$ticket, $token] = tkPubTicket();

    $data = $this->postJson('/api/v1/public/tickets/track/replies', ['body' => 'Any news?'], ['X-Ticket-Token' => $token])
        ->assertCreated()->json('data');

    expect($data['messages'])->toHaveCount(2)->and($data['messages'][1]['body'])->toBe('Any news?');
    $this->assertDatabaseHas('ticket_messages', ['ticket_id' => $ticket->getKey(), 'sender_type' => 'requester', 'sender_user_id' => null]);
});

test('a public reply is validated and limited to a few links', function () {
    [, $token] = tkPubTicket();
    $h = ['X-Ticket-Token' => $token];

    $this->postJson('/api/v1/public/tickets/track/replies', [], $h)->assertUnprocessable()->assertJsonValidationErrors(['body']);
    $this->postJson('/api/v1/public/tickets/track/replies', ['body' => str_repeat('x', 5001)], $h)->assertUnprocessable();
    $this->postJson('/api/v1/public/tickets/track/replies', ['body' => 'a http://1.example b http://2.example c http://3.example'], $h)
        ->assertUnprocessable()->assertJsonValidationErrors(['body']);
});

test('10 replies per ticket per hour, then 429 with Retry-After', function () {
    config(['tickets.rate_limits.replies_per_ticket_per_hour' => 2]);
    [, $token] = tkPubTicket();
    [, $otherToken] = tkPubTicket('other@example.com');
    $h = ['X-Ticket-Token' => $token];

    $this->postJson('/api/v1/public/tickets/track/replies', ['body' => 'one'], $h)->assertCreated();
    $this->postJson('/api/v1/public/tickets/track/replies', ['body' => 'two'], $h)->assertCreated();

    $blocked = $this->postJson('/api/v1/public/tickets/track/replies', ['body' => 'three'], $h)->assertStatus(429)->assertHeader('Retry-After');
    expect($blocked->json('code'))->toBe('rate_limited');

    // The limit is per ticket, and a second link to the SAME ticket does not reset it.
    $second = app(TicketAccessTokenService::class)->issue(Ticket::first());
    $this->postJson('/api/v1/public/tickets/track/replies', ['body' => 'four'], ['X-Ticket-Token' => $second])->assertStatus(429);

    $this->postJson('/api/v1/public/tickets/track/replies', ['body' => 'one'], ['X-Ticket-Token' => $otherToken])->assertCreated();
});

test('a reply to a Closed ticket is refused', function () {
    [$ticket, $token] = tkPubTicket();
    tkService()->resolve($ticket, tkStaff(), 'Fixed, please confirm.');
    tkService()->confirm($ticket, null);

    $this->postJson('/api/v1/public/tickets/track/replies', ['body' => 'one more'], ['X-Ticket-Token' => $token])
        ->assertStatus(409)->assertJsonPath('code', 'action_not_allowed');
});

test('a public submitter confirms a resolution and the ticket closes', function () {
    [$ticket, $token] = tkPubTicket();
    tkService()->resolve($ticket, tkStaff(), 'Fixed, please confirm.');

    $data = $this->postJson('/api/v1/public/tickets/track/confirm', [], ['X-Ticket-Token' => $token])->assertOk()->json('data');

    expect($data['status'])->toBe('closed')->and($data['close_reason'])->toBe('confirmed');
});

test('"this isn\'t resolved" works for a public submitter and is limited to 2 returns', function () {
    [$ticket, $token] = tkPubTicket();
    $h = ['X-Ticket-Token' => $token];
    $staff = tkStaff();

    foreach ([1, 2] as $round) {
        tkService()->resolve($ticket, $staff, "Resolved attempt {$round}, please confirm.");
        $this->postJson('/api/v1/public/tickets/track/return', ['reason' => 'Still broken'], $h)->assertOk()->assertJsonPath('data.status', 'in_progress');
    }

    tkService()->resolve($ticket, $staff, 'Resolved attempt 3, please confirm.');
    $this->postJson('/api/v1/public/tickets/track/return', [], $h)->assertStatus(409)->assertJsonPath('code', 'return_limit_reached');
});

test('confirming a ticket that is not Resolved is a 409', function () {
    [, $token] = tkPubTicket();

    $this->postJson('/api/v1/public/tickets/track/confirm', [], ['X-Ticket-Token' => $token])->assertStatus(409)->assertJsonPath('code', 'invalid_transition');
});

test('tracking requests are rate limited per IP', function () {
    config(['tickets.rate_limits.public.track_per_ip_per_minute' => 2]);

    $this->getJson('/api/v1/public/tickets/track', ['X-Ticket-Token' => str_repeat('a', 43)])->assertNotFound();
    $this->getJson('/api/v1/public/tickets/track', ['X-Ticket-Token' => str_repeat('b', 43)])->assertNotFound();
    $this->getJson('/api/v1/public/tickets/track', ['X-Ticket-Token' => str_repeat('c', 43)])->assertStatus(429)->assertHeader('Retry-After');
});

// ═════════════════════════════════════════════════════════════════════════════
// Resend link: one answer, whatever matched
// ═════════════════════════════════════════════════════════════════════════════

test('resend emails a fresh link when the address and ticket number match', function () {
    [$ticket, $old] = tkPubTicket('juan@example.com');

    $res = $this->postJson('/api/v1/public/tickets/resend-link', ['email' => 'Juan@Example.com', 'ticket_number' => strtolower($ticket->ticket_number)])->assertStatus(202);

    Mail::assertQueued(TicketPublicAccessLinkMail::class, fn ($m) => $m->hasTo('juan@example.com') && $m->ticketNumber === $ticket->ticket_number);

    $new = tkPubMailedToken(TicketPublicAccessLinkMail::class);
    expect($new)->not->toBe($old);
    $this->getJson('/api/v1/public/tickets/track', ['X-Ticket-Token' => $new])->assertOk();
    // Resending does not break the link they already had.
    $this->getJson('/api/v1/public/tickets/track', ['X-Ticket-Token' => $old])->assertOk();
});

test('resend gives the identical answer for a match, a wrong address, an unknown number and a closed ticket', function () {
    [$ticket] = tkPubTicket('juan@example.com');
    [$closed] = tkPubTicket('closed@example.com');
    tkService()->resolve($closed, tkStaff(), 'Fixed, please confirm.');
    tkService()->confirm($closed, null);
    Carbon::setTestNow(now()->addDays(8));
    $accountTicket = tkOpenTicket();

    $cases = [
        ['juan@example.com',   $ticket->ticket_number],        // match
        ['wrong@example.com',  $ticket->ticket_number],        // wrong address
        ['juan@example.com',   'TKT-2026-99999'],              // unknown number
        ['closed@example.com', $closed->ticket_number],        // link window ended
        ['anyone@example.com', $accountTicket->ticket_number], // belongs to an account holder
    ];

    $responses = collect($cases)->map(fn ($c) => $this->postJson('/api/v1/public/tickets/resend-link', ['email' => $c[0], 'ticket_number' => $c[1]])
        ->assertStatus(202)->getContent());

    expect($responses->unique()->count())->toBe(1);
    // Only the genuine match produced an email.
    Mail::assertQueuedCount(1);
});

test('resend is limited per address and per IP, counting every request so the limit reveals nothing', function () {
    config(['tickets.rate_limits.public.resend_per_email_per_hour' => 2]);

    foreach (range(1, 2) as $_) {
        $this->postJson('/api/v1/public/tickets/resend-link', ['email' => 'nobody@example.com', 'ticket_number' => 'TKT-2026-00001'])->assertStatus(202);
    }

    $this->postJson('/api/v1/public/tickets/resend-link', ['email' => 'nobody@example.com', 'ticket_number' => 'TKT-2026-00001'])
        ->assertStatus(429)->assertHeader('Retry-After');

    // A different address is unaffected.
    $this->postJson('/api/v1/public/tickets/resend-link', ['email' => 'else@example.com', 'ticket_number' => 'TKT-2026-00001'])->assertStatus(202);
});

test('hammering resend for someone\'s address does not use up their allowance to file a ticket', function () {
    config(['tickets.rate_limits.public.resend_per_email_per_hour' => 50]);

    foreach (range(1, 10) as $_) {
        $this->postJson('/api/v1/public/tickets/resend-link', ['email' => 'victim@example.com', 'ticket_number' => 'TKT-2026-00001'])->assertStatus(202);
    }

    $this->postJson('/api/v1/public/tickets/start', tkPubPayload(['email' => 'victim@example.com']))->assertStatus(202);
});

test('resend does not email a blocked address', function () {
    [$ticket] = tkPubTicket('juan@example.com');
    \App\Models\TicketBlock::create(['type' => 'email', 'value' => 'juan@example.com']);

    $this->postJson('/api/v1/public/tickets/resend-link', ['email' => 'juan@example.com', 'ticket_number' => $ticket->ticket_number])->assertStatus(202);

    Mail::assertNothingQueued();
});

test('resend validates only the shape of its input', function () {
    $this->postJson('/api/v1/public/tickets/resend-link', [])->assertUnprocessable()->assertJsonValidationErrors(['email', 'ticket_number']);
    $this->postJson('/api/v1/public/tickets/resend-link', ['email' => 'nope', 'ticket_number' => 'x'])->assertUnprocessable()->assertJsonValidationErrors(['email']);
});

// ═════════════════════════════════════════════════════════════════════════════
// End to end
// ═════════════════════════════════════════════════════════════════════════════

test('the whole public journey: submit, confirm, follow, staff reply, confirm resolution', function () {
    [$res, $token] = tkPubFileTicket(['category_code' => 'CAT-01']);
    $ticket = Ticket::first();
    $h      = ['X-Ticket-Token' => $token];

    expect($res->json('data.priority'))->toBe('high');

    $staff = tkApiStaff();
    tkService()->claim($ticket, $staff);
    tkService()->replyAsStaff($ticket, $staff, 'We are checking your receipt.');

    $view = $this->getJson('/api/v1/public/tickets/track', $h)->assertOk()->json('data');
    expect($view['status'])->toBe('in_progress')
        ->and($view['messages'][1]['sender_label'])->toBe('Registrar Staff')
        ->and(json_encode($view))->not->toContain('Ana')->not->toContain('Reyes');

    tkService()->resolve($ticket, $staff, 'Your receipt is recorded. Please confirm.');
    $this->postJson('/api/v1/public/tickets/track/confirm', [], $h)->assertOk()->assertJsonPath('data.status', 'closed');
});
