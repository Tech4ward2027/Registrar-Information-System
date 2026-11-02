<?php

use App\Mail\TicketPublicAccessLinkMail;
use App\Mail\TicketPublicVerificationMail;
use App\Models\Ticket;
use App\Models\TicketBlock;
use App\Models\TicketPublicSubmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

require_once __DIR__ . '/../../Support/TicketPublicTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(fn () => tkPubSetup());
afterEach(fn () => Carbon::setTestNow());

const TK_PUB = '/api/v1/public/tickets';

// ═════════════════════════════════════════════════════════════════════════════
// Gate
// ═════════════════════════════════════════════════════════════════════════════

test('every public ticket route answers 404 while the flag is off', function () {
    config(['features.tickets' => false]);

    $this->getJson(TK_PUB . '/form')->assertNotFound();
    $this->postJson(TK_PUB . '/start', tkPubPayload())->assertNotFound();
    $this->postJson(TK_PUB . '/confirm-email', ['token' => str_repeat('a', 43)])->assertNotFound();
    $this->postJson(TK_PUB . '/resend-link', ['email' => 'a@b.co', 'ticket_number' => 'TKT-2026-00001'])->assertNotFound();
    $this->getJson(TK_PUB . '/track', ['X-Ticket-Token' => str_repeat('a', 43)])->assertNotFound();
});

test('the public routes need no login', function () {
    $this->getJson(TK_PUB . '/form')->assertOk();
});

// ═════════════════════════════════════════════════════════════════════════════
// Form
// ═════════════════════════════════════════════════════════════════════════════

test('the form call returns a token, the categories, the honeypot name and CAPTCHA setup', function () {
    config(['tickets.public.captcha_enabled' => true, 'tickets.public.captcha.site_key' => 'site-key-1']);

    $data = $this->getJson(TK_PUB . '/form')->assertOk()->json('data');

    expect($data['form_token'])->toBeString()->not->toBeEmpty()
        ->and($data['honeypot_field'])->toBe('website')
        ->and($data['captcha'])->toBe(['enabled' => true, 'site_key' => 'site-key-1'])
        ->and(collect($data['categories'])->pluck('code')->all())->toContain('CAT-01', 'CAT-05')
        ->and($data['limits']['message_max_length'])->toBe(5000)
        ->and(json_encode($data))->not->toContain('secret');
});

// ═════════════════════════════════════════════════════════════════════════════
// Start: no ticket until the email is confirmed
// ═════════════════════════════════════════════════════════════════════════════

test('starting stores a pending submission and emails a confirmation link, but creates no ticket', function () {
    $res = $this->postJson(TK_PUB . '/start', tkPubPayload())->assertStatus(202);

    expect($res->json('expires_in_minutes'))->toBe(30);
    expect(Ticket::count())->toBe(0);

    Mail::assertQueued(TicketPublicVerificationMail::class, fn ($m) => $m->hasTo('juan@example.com'));

    $plain = tkPubMailedToken(TicketPublicVerificationMail::class);
    expect(strlen($plain))->toBeGreaterThanOrEqual(43)       // 32 bytes, URL-safe base64
        ->and(Mail::queued(TicketPublicVerificationMail::class)->last()->confirmUrl)->toContain('#token=');

    // Only the hash is stored; the token itself is nowhere in the database.
    $row = TicketPublicSubmission::first();
    expect($row->token_hash)->toBe(hash('sha256', $plain))
        ->and(json_encode($row->getAttributes()))->not->toContain($plain)
        ->and($row->email)->toBe('juan@example.com');
});

test('an address that never confirms never gets a ticket', function () {
    $this->postJson(TK_PUB . '/start', tkPubPayload())->assertStatus(202);
    Carbon::setTestNow(now()->addDays(3));

    expect(Ticket::count())->toBe(0);
});

test('the email is lowercased and the subject and name are tidied', function () {
    $this->postJson(TK_PUB . '/start', tkPubPayload([
        'email' => 'Juan@Example.COM', 'subject' => "Line one\n\n line   two", 'name' => "  Juan   Cruz ",
    ]))->assertStatus(202);

    $row = TicketPublicSubmission::first();
    expect($row->email)->toBe('juan@example.com')->and($row->subject)->toBe('Line one line two')->and($row->name)->toBe('Juan Cruz');
});

// ═════════════════════════════════════════════════════════════════════════════
// Start: validation
// ═════════════════════════════════════════════════════════════════════════════

test('start validates its fields', function () {
    $this->postJson(TK_PUB . '/start', [])->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'email', 'category_code', 'subject', 'description', 'form_token']);

    $this->postJson(TK_PUB . '/start', tkPubPayload(['email' => 'not-an-email']))->assertJsonValidationErrors(['email']);
    $this->postJson(TK_PUB . '/start', tkPubPayload(['category_code' => 'CAT-99']))->assertJsonValidationErrors(['category_code']);
    $this->postJson(TK_PUB . '/start', tkPubPayload(['description' => 'too short']))->assertJsonValidationErrors(['description']);
    $this->postJson(TK_PUB . '/start', tkPubPayload(['description' => str_repeat('x', 5001)]))->assertJsonValidationErrors(['description']);
    $this->postJson(TK_PUB . '/start', tkPubPayload(['name' => '<script>alert(1)</script>']))->assertJsonValidationErrors(['name']);

    expect(TicketPublicSubmission::count())->toBe(0);
    Mail::assertNothingQueued();
});

test('links are limited: none in a name or subject, a few in the message', function () {
    $this->postJson(TK_PUB . '/start', tkPubPayload(['name' => 'Visit http://spam.example']))->assertJsonValidationErrors(['name']);
    $this->postJson(TK_PUB . '/start', tkPubPayload(['subject' => 'Win at www.spam.example']))->assertJsonValidationErrors(['subject']);

    $three = 'See http://a.example and https://b.example and www.c.example for the details of my problem.';
    $this->postJson(TK_PUB . '/start', tkPubPayload(['description' => $three]))->assertJsonValidationErrors(['description']);

    $two = 'See http://a.example and https://b.example for the details of my problem here.';
    $this->postJson(TK_PUB . '/start', tkPubPayload(['description' => $two]))->assertStatus(202);
});

// ═════════════════════════════════════════════════════════════════════════════
// Start: honeypot, fill time, CAPTCHA, blocklist
// ═════════════════════════════════════════════════════════════════════════════

test('a filled honeypot looks like success but does nothing', function () {
    $res = $this->postJson(TK_PUB . '/start', tkPubPayload(['website' => 'http://bot.example']))->assertStatus(202);

    expect($res->json('message'))->toContain('emailed you');
    expect(TicketPublicSubmission::count())->toBe(0);
    Mail::assertNothingQueued();
});

test('a submission faster than the minimum fill time is refused', function () {
    config(['tickets.public.min_fill_seconds' => 30]);

    $this->postJson(TK_PUB . '/start', tkPubPayload())->assertUnprocessable()->assertJsonValidationErrors(['form_token']);
    Mail::assertNothingQueued();
});

test('a missing, tampered or stale form token is refused', function () {
    $this->postJson(TK_PUB . '/start', tkPubPayload(['form_token' => 'garbage']))->assertJsonValidationErrors(['form_token']);

    $valid = tkPubPayload()['form_token'];
    $this->postJson(TK_PUB . '/start', tkPubPayload(['form_token' => substr($valid, 0, -4) . 'AAAA']))->assertJsonValidationErrors(['form_token']);

    // Genuine (encrypted by us) but issued three hours ago.
    $stale = Crypt::encryptString((string) (time() - 3 * 3600));
    $this->postJson(TK_PUB . '/start', tkPubPayload(['form_token' => $stale]))->assertJsonValidationErrors(['form_token']);

    // Encrypted by us but not a timestamp.
    $this->postJson(TK_PUB . '/start', tkPubPayload(['form_token' => Crypt::encryptString('hello')]))->assertJsonValidationErrors(['form_token']);

    expect(TicketPublicSubmission::count())->toBe(0);
});

test('CAPTCHA is required when enabled, and a failed check is refused', function () {
    config(['tickets.public.captcha_enabled' => true]);

    $this->postJson(TK_PUB . '/start', tkPubPayload(['captcha_token' => null]))->assertJsonValidationErrors(['captcha_token']);

    tkPubCaptcha(false);
    $this->postJson(TK_PUB . '/start', tkPubPayload())->assertUnprocessable()->assertJsonValidationErrors(['captcha_token']);
    Mail::assertNothingQueued();

    tkPubCaptcha(true);
    $this->postJson(TK_PUB . '/start', tkPubPayload())->assertStatus(202);
});

test('a blocked email or IP gets the same answer as success but nothing is sent', function () {
    TicketBlock::create(['type' => 'email', 'value' => 'spammer@example.com']);
    TicketBlock::create(['type' => 'ip', 'value' => '198.51.100.77']);

    $blockedEmail = $this->postJson(TK_PUB . '/start', tkPubPayload(['email' => 'Spammer@Example.com']))->assertStatus(202);
    $blockedIp    = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.77'])
        ->postJson(TK_PUB . '/start', tkPubPayload(['email' => 'other@example.com']))->assertStatus(202);

    expect($blockedEmail->json())->toBe($blockedIp->json());
    expect(TicketPublicSubmission::count())->toBe(0);
    Mail::assertNothingQueued();
});

test('an expired block no longer applies', function () {
    TicketBlock::create(['type' => 'email', 'value' => 'juan@example.com', 'expires_at' => now()->subMinute()]);

    $this->postJson(TK_PUB . '/start', tkPubPayload())->assertStatus(202);
    Mail::assertQueued(TicketPublicVerificationMail::class);
});

// ═════════════════════════════════════════════════════════════════════════════
// Start: rate limits
// ═════════════════════════════════════════════════════════════════════════════

test('3 verification emails per address per hour, then 429 with Retry-After', function () {
    foreach (range(1, 3) as $_) {
        $this->postJson(TK_PUB . '/start', tkPubPayload())->assertStatus(202);
    }

    $blocked = $this->postJson(TK_PUB . '/start', tkPubPayload())->assertStatus(429)->assertHeader('Retry-After');

    expect($blocked->json('code'))->toBe('rate_limited')->and($blocked->json('retry_after'))->toBeInt()->toBeGreaterThan(0);
    Mail::assertQueuedCount(3);

    // Another address is unaffected.
    $this->postJson(TK_PUB . '/start', tkPubPayload(['email' => 'maria@example.com']))->assertStatus(202);
});

test('the per-address limit is not used up by requests that fail the human checks', function () {
    tkPubCaptcha(false);
    config(['tickets.public.captcha_enabled' => true]);

    foreach (range(1, 6) as $_) {
        $this->postJson(TK_PUB . '/start', tkPubPayload())->assertUnprocessable();
    }

    tkPubCaptcha(true);
    $this->postJson(TK_PUB . '/start', tkPubPayload())->assertStatus(202);
});

test('10 starts per IP per hour, then 429', function () {
    config(['tickets.rate_limits.public.verification_emails_per_ip_per_hour' => 2]);

    $this->postJson(TK_PUB . '/start', tkPubPayload(['email' => 'a1@example.com']))->assertStatus(202);
    $this->postJson(TK_PUB . '/start', tkPubPayload(['email' => 'a2@example.com']))->assertStatus(202);

    $this->postJson(TK_PUB . '/start', tkPubPayload(['email' => 'a3@example.com']))
        ->assertStatus(429)->assertHeader('Retry-After')->assertJsonPath('code', 'rate_limited');
});

test('a short per-IP burst ceiling applies too', function () {
    config(['tickets.rate_limits.public.start_per_ip_per_minute' => 2]);

    foreach (['b1', 'b2'] as $n) {
        $this->postJson(TK_PUB . '/start', tkPubPayload(['email' => "{$n}@example.com"]))->assertStatus(202);
    }
    $this->postJson(TK_PUB . '/start', tkPubPayload(['email' => 'b3@example.com']))->assertStatus(429);
});

test('3 tickets per address per day: the next start is refused with the time to wait', function () {
    foreach (range(1, 3) as $_) {
        tkPubTicket('juan@example.com');
    }
    config(['tickets.rate_limits.public.open_tickets_per_email' => 99]);

    $res = $this->postJson(TK_PUB . '/start', tkPubPayload())->assertStatus(429)->assertHeader('Retry-After');
    expect($res->json('retry_after'))->toBeGreaterThan(0)->toBeLessThanOrEqual(86400);
    Mail::assertNothingQueued();

    // A day later the window has moved on.
    Carbon::setTestNow(now()->addDay()->addMinute());
    $this->postJson(TK_PUB . '/start', tkPubPayload())->assertStatus(202);
});

test('10 tickets per IP per day', function () {
    config(['tickets.rate_limits.public.tickets_per_ip_per_day' => 2, 'tickets.rate_limits.public.open_tickets_per_email' => 99]);
    tkPubTicket('x1@example.com');
    tkPubTicket('x2@example.com');   // both created from 203.0.113.5

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])
        ->postJson(TK_PUB . '/start', tkPubPayload(['email' => 'x3@example.com']))->assertStatus(429);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.6'])
        ->postJson(TK_PUB . '/start', tkPubPayload(['email' => 'x3@example.com']))->assertStatus(202);
});

test('3 open tickets per address: a fourth is refused until one is resolved', function () {
    config(['tickets.rate_limits.public.tickets_per_email_per_day' => 99]);
    $tickets = collect(range(1, 3))->map(fn () => tkPubTicket('juan@example.com')[0]);

    $res = $this->postJson(TK_PUB . '/start', tkPubPayload())->assertStatus(409);
    expect($res->json('code'))->toBe('open_tickets_limit')->and($res->json('limit'))->toBe(3);

    tkService()->resolve($tickets[0], tkStaff(), 'Fixed, please confirm.');
    $this->postJson(TK_PUB . '/start', tkPubPayload())->assertStatus(202);
});

// ═════════════════════════════════════════════════════════════════════════════
// Confirm: the ticket is created
// ═════════════════════════════════════════════════════════════════════════════

test('confirming the email creates the ticket and hands back a tracking token', function () {
    [$res, $token] = tkPubFileTicket();
    $data = $res->json('data');

    expect($data['ticket_number'])->toMatch('/^TKT-\d{4}-\d{5}$/')
        ->and($data['status'])->toBe('open')
        ->and($data['priority'])->toBe('low')
        ->and($data['messages'])->toHaveCount(1)
        ->and($token)->toBeString()
        ->and(strlen($token))->toBeGreaterThanOrEqual(43)
        ->and(json_encode($res->json()))->not->toContain('juan@example.com')->not->toContain('requester_ip');

    $ticket = Ticket::first();
    expect($ticket->requester_user_id)->toBeNull()
        ->and($ticket->requester_email)->toBe('juan@example.com')
        ->and($ticket->requester_name)->toBe('Juan Dela Cruz')
        ->and($ticket->requester_ip)->toBe('127.0.0.1');

    // The magic link is emailed as well.
    Mail::assertQueued(TicketPublicAccessLinkMail::class, fn ($m) => $m->hasTo('juan@example.com') && $m->ticketNumber === $ticket->ticket_number);
    expect(tkPubMailedToken(TicketPublicAccessLinkMail::class))->toBe($token);
});

test('only the hash of the magic-link token is stored', function () {
    [, $token] = tkPubFileTicket();

    $this->assertDatabaseHas('ticket_access_tokens', ['token_hash' => hash('sha256', $token)]);
    expect(json_encode(DB::table('ticket_access_tokens')->get()))->not->toContain($token);
});

test('once confirmed, the pending submission keeps no copy of what was typed', function () {
    tkPubFileTicket();

    $row = TicketPublicSubmission::first();
    expect($row->consumed_at)->not->toBeNull()
        ->and($row->ticket_id)->toBe(Ticket::first()->getKey())
        ->and($row->description)->toBeNull()->and($row->subject)->toBeNull()->and($row->name)->toBeNull();
});

test('the link works once: a second click does not create a second ticket', function () {
    $this->postJson(TK_PUB . '/start', tkPubPayload())->assertStatus(202);
    $token = tkPubMailedToken(TicketPublicVerificationMail::class);

    $this->postJson(TK_PUB . '/confirm-email', ['token' => $token])->assertCreated();
    $this->postJson(TK_PUB . '/confirm-email', ['token' => $token])->assertUnprocessable()->assertJsonValidationErrors(['token']);

    expect(Ticket::count())->toBe(1);
});

test('tampered, unknown and malformed confirmation tokens all get the same refusal', function () {
    $this->postJson(TK_PUB . '/start', tkPubPayload())->assertStatus(202);
    $token = tkPubMailedToken(TicketPublicVerificationMail::class);

    $messages = collect([
        substr($token, 0, -1) . (str_ends_with($token, 'A') ? 'B' : 'A'),   // one character changed
        strrev($token),
        str_repeat('a', 43),                                                // well-formed, unknown
        'short',                                                            // malformed
        '../../etc/passwd',
    ])->map(fn ($t) => $this->postJson(TK_PUB . '/confirm-email', ['token' => $t])
        ->assertUnprocessable()->assertJsonValidationErrors(['token'])->json('errors.token.0'));

    expect($messages->unique()->count())->toBe(1);
    expect(Ticket::count())->toBe(0);
});

test('an expired confirmation link is refused with that same message', function () {
    $this->postJson(TK_PUB . '/start', tkPubPayload())->assertStatus(202);
    $token = tkPubMailedToken(TicketPublicVerificationMail::class);

    $unknown = $this->postJson(TK_PUB . '/confirm-email', ['token' => str_repeat('a', 43)])->json('errors.token.0');

    Carbon::setTestNow(now()->addMinutes(31));
    $expired = $this->postJson(TK_PUB . '/confirm-email', ['token' => $token])
        ->assertUnprocessable()->assertJsonValidationErrors(['token'])->json('errors.token.0');

    expect($expired)->toBe($unknown);
    expect(Ticket::count())->toBe(0);
});

test('a link still works just inside its lifetime', function () {
    $this->postJson(TK_PUB . '/start', tkPubPayload())->assertStatus(202);
    $token = tkPubMailedToken(TicketPublicVerificationMail::class);

    Carbon::setTestNow(now()->addMinutes(29));
    $this->postJson(TK_PUB . '/confirm-email', ['token' => $token])->assertCreated();
});

test('confirming is refused for an address blocked after it started', function () {
    $this->postJson(TK_PUB . '/start', tkPubPayload())->assertStatus(202);
    $token = tkPubMailedToken(TicketPublicVerificationMail::class);

    TicketBlock::create(['type' => 'email', 'value' => 'juan@example.com']);

    $this->postJson(TK_PUB . '/confirm-email', ['token' => $token])->assertUnprocessable();
    expect(Ticket::count())->toBe(0);
});

test('the ceilings are enforced again at confirmation, and a refused confirmation can be retried later', function () {
    config(['tickets.rate_limits.public.tickets_per_email_per_day' => 99]);
    $this->postJson(TK_PUB . '/start', tkPubPayload())->assertStatus(202);
    $token = tkPubMailedToken(TicketPublicVerificationMail::class);

    // Three open tickets appear for the address between start and confirm.
    $three = collect(range(1, 3))->map(fn () => tkPubTicket('juan@example.com')[0]);

    $this->postJson(TK_PUB . '/confirm-email', ['token' => $token])->assertStatus(409)->assertJsonPath('code', 'open_tickets_limit');
    expect(TicketPublicSubmission::first()->consumed_at)->toBeNull();

    tkService()->resolve($three[0], tkStaff(), 'Fixed, please confirm.');
    $this->postJson(TK_PUB . '/confirm-email', ['token' => $token])->assertCreated();
});

test('a category deactivated before confirmation is refused cleanly', function () {
    $this->postJson(TK_PUB . '/start', tkPubPayload())->assertStatus(202);
    $token = tkPubMailedToken(TicketPublicVerificationMail::class);
    \App\Models\TicketCategory::where('code', 'CAT-05')->update(['is_active' => false]);

    $this->postJson(TK_PUB . '/confirm-email', ['token' => $token])->assertUnprocessable()->assertJsonValidationErrors(['token']);
    expect(Ticket::count())->toBe(0);
});

test('confirm requests are rate limited per IP', function () {
    config(['tickets.rate_limits.public.confirm_per_ip_per_minute' => 2]);

    $this->postJson(TK_PUB . '/confirm-email', ['token' => str_repeat('a', 43)])->assertUnprocessable();
    $this->postJson(TK_PUB . '/confirm-email', ['token' => str_repeat('b', 43)])->assertUnprocessable();
    $this->postJson(TK_PUB . '/confirm-email', ['token' => str_repeat('c', 43)])->assertStatus(429)->assertHeader('Retry-After');
});
