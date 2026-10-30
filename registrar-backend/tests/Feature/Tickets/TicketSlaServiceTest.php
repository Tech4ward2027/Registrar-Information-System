<?php

use App\Enums\TicketPriorityEnum;
use App\Enums\TicketStatusEnum;
use App\Models\BusinessCalendar;
use App\Models\SystemUser;
use App\Models\Ticket;
use App\Services\Tickets\SlaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

require_once __DIR__ . '/../../Support/TicketSlaTestHelpers.php';

uses(RefreshDatabase::class);

/*
 * Phase 5 — due dates, pause/resume, restart and claim expiry. Calendar: open
 * Tue–Fri 08:00–20:00, Monday closed (tkSlaPolicyCalendar). November 2026:
 * Tue 3, Wed 4, Thu 5, Fri 6, Sat 7, Sun 8, Mon 9, Tue 10, Wed 11.
 */

beforeEach(fn () => tkSlaPolicyCalendar());
afterEach(fn () => Carbon::setTestNow());

// ═════════════════════════════════════════════════════════════════════════════
// Targets
// ═════════════════════════════════════════════════════════════════════════════

test('the targets follow the policy: 4 h / 1 day for High, 8 h / 2 days for the rest', function () {
    $sla = app(SlaService::class);

    expect($sla->firstResponseMinutes(TicketPriorityEnum::High))->toBe(4 * 60)
        ->and($sla->resolutionMinutes(TicketPriorityEnum::High))->toBe(12 * 60)
        ->and($sla->firstResponseMinutes(TicketPriorityEnum::Medium))->toBe(8 * 60)
        ->and($sla->resolutionMinutes(TicketPriorityEnum::Medium))->toBe(24 * 60)
        ->and($sla->firstResponseMinutes(TicketPriorityEnum::Low))->toBe(8 * 60)
        ->and($sla->resolutionMinutes(TicketPriorityEnum::Low))->toBe(24 * 60);
});

test('the targets come from config', function () {
    config(['tickets.sla.first_response_hours.high' => 2, 'tickets.sla.resolution_days.high' => 3, 'tickets.sla.operating_day_hours' => 10]);

    $sla = app(SlaService::class);

    expect($sla->firstResponseMinutes(TicketPriorityEnum::High))->toBe(120)
        ->and($sla->resolutionMinutes(TicketPriorityEnum::High))->toBe(3 * 10 * 60);
});

// ═════════════════════════════════════════════════════════════════════════════
// Due dates at creation
// ═════════════════════════════════════════════════════════════════════════════

test('a High ticket filed during working hours is due 4 working hours / 1 operating day later', function () {
    tkSlaNow('2026-11-03 10:00'); // Tuesday

    $ticket = tkOpenTicket(null, 'CAT-01');

    expect(tkSlaManila($ticket->first_response_due_at))->toBe('2026-11-03 14:00')
        ->and(tkSlaManila($ticket->resolution_due_at))->toBe('2026-11-04 10:00')
        ->and($ticket->sla_paused_at)->toBeNull();
});

test('a Medium ticket gets the looser targets', function () {
    tkSlaNow('2026-11-03 10:00');

    $ticket = tkOpenTicket(null, 'CAT-02');

    // 8 working hours: 10 -> 18. 24 working hours: 10 h Tue + 12 h Wed + 2 h Thu.
    expect(tkSlaManila($ticket->first_response_due_at))->toBe('2026-11-03 18:00')
        ->and(tkSlaManila($ticket->resolution_due_at))->toBe('2026-11-05 10:00');
});

test('a ticket filed after hours starts counting at the next opening', function () {
    tkSlaNow('2026-11-03 22:00'); // Tuesday 10 PM

    $ticket = tkOpenTicket(null, 'CAT-01');

    expect(tkSlaManila($ticket->first_response_due_at))->toBe('2026-11-04 12:00');
});

test('a ticket filed Friday evening skips the weekend AND the closed Monday', function () {
    tkSlaNow('2026-11-06 18:00'); // Friday

    $ticket = tkOpenTicket(null, 'CAT-01');

    // First response: 2 h Friday, then Tuesday 08:00 + 2 h. Resolution: 2 h + 10 h on Tuesday.
    expect(tkSlaManila($ticket->first_response_due_at))->toBe('2026-11-10 10:00')
        ->and(tkSlaManila($ticket->resolution_due_at))->toBe('2026-11-10 18:00');
});

test('a ticket filed on the closed Monday starts at Tuesday opening', function () {
    tkSlaNow('2026-11-09 10:00'); // Monday

    $ticket = tkOpenTicket(null, 'CAT-01');

    expect(tkSlaManila($ticket->first_response_due_at))->toBe('2026-11-10 12:00')
        ->and(tkSlaManila($ticket->resolution_due_at))->toBe('2026-11-10 20:00');
});

test('a declared holiday is skipped', function () {
    BusinessCalendar::where('is_default', true)->firstOrFail()
        ->holidays()->create(['date' => '2026-11-04', 'label' => 'Test holiday']); // Wednesday
    tkSlaNow('2026-11-03 18:00');

    $ticket = tkOpenTicket(null, 'CAT-01');

    // Resolution 12 h: 2 h Tuesday, Wednesday closed, 10 h Thursday.
    expect(tkSlaManila($ticket->resolution_due_at))->toBe('2026-11-05 18:00');
});

test('deadlines are stored as the right instant, not as Manila wall-clock text in a UTC column', function () {
    tkSlaNow('2026-11-03 10:00');

    $ticket = tkOpenTicket(null, 'CAT-01');
    $raw    = DB::table('tickets')->where('id', $ticket->id)->value('first_response_due_at');

    expect($raw)->toBe(tkSlaAt('2026-11-03 14:00')->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'));
});

test('the arithmetic does not change with the zone the server clock is in', function () {
    config(['app.display_timezone' => 'Asia/Manila']);
    Carbon::setTestNow(Carbon::parse('2026-11-03 02:00:00', 'UTC')); // = 10:00 Manila

    $ticket = tkOpenTicket(null, 'CAT-01');

    expect(tkSlaManila($ticket->first_response_due_at))->toBe('2026-11-03 14:00');
});

// ═════════════════════════════════════════════════════════════════════════════
// Pause and resume (Pending Client)
// ═════════════════════════════════════════════════════════════════════════════

test('Pending Client pauses the resolution clock and the requester\'s answer resumes it, pushed back by the wait', function () {
    $staff     = tkStaff();
    $requester = SystemUser::factory()->create();

    tkSlaNow('2026-11-03 10:00'); // Tuesday
    $ticket = tkOpenTicket($requester, 'CAT-01'); // resolution due Wed 10:00
    tkService()->claim($ticket, $staff);

    tkSlaNow('2026-11-03 12:00');
    tkService()->replyAsStaff($ticket, $staff, 'Which receipt do you mean?', awaitRequester: true);

    $paused = $ticket->fresh();
    expect($paused->status)->toBe(TicketStatusEnum::PendingClient)
        ->and(tkSlaManila($paused->sla_paused_at))->toBe('2026-11-03 12:00')
        ->and(tkSlaManila($paused->resolution_due_at))->toBe('2026-11-04 10:00') // untouched while paused
        ->and($paused->slaState(Ticket::SLA_RESOLUTION))->toBe(Ticket::SLA_STATE_PAUSED);

    // Wednesday noon: the requester answers. They were away 8 h (Tue) + 4 h (Wed) = 12 working hours.
    tkSlaNow('2026-11-04 12:00');
    tkService()->replyAsRequester($ticket, $requester, 'Receipt OR-1234.');

    $resumed = $ticket->fresh();
    expect($resumed->status)->toBe(TicketStatusEnum::InProgress)
        ->and($resumed->sla_paused_at)->toBeNull()
        // 10 working hours were left at the pause (Tue 12 -> Wed 10); 10 h after Wed 12:00 is Thu 10:00.
        ->and(tkSlaManila($resumed->resolution_due_at))->toBe('2026-11-05 10:00');
});

test('time paused over a weekend and the closed Monday is not counted', function () {
    $staff     = tkStaff();
    $requester = SystemUser::factory()->create();

    tkSlaNow('2026-11-06 14:00'); // Friday
    $ticket = tkOpenTicket($requester, 'CAT-01'); // 6 h Friday + 6 h Tuesday -> Tue 14:00
    expect(tkSlaManila($ticket->resolution_due_at))->toBe('2026-11-10 14:00');
    tkService()->claim($ticket, $staff);

    tkSlaNow('2026-11-06 18:00');
    tkService()->replyAsStaff($ticket, $staff, 'Need more details.', awaitRequester: true);

    tkSlaNow('2026-11-10 09:00'); // Tuesday
    tkService()->replyAsRequester($ticket, $requester, 'Here they are.');

    // Paused 2 h Friday + 1 h Tuesday = 3 working hours; Sat, Sun, Mon cost nothing.
    expect(tkSlaManila($ticket->fresh()->resolution_due_at))->toBe('2026-11-10 17:00');
});

test('pausing never moves the first-response deadline', function () {
    $staff     = tkStaff();
    $requester = SystemUser::factory()->create();

    tkSlaNow('2026-11-03 10:00');
    $ticket = tkOpenTicket($requester, 'CAT-01');
    $before = tkSlaManila($ticket->first_response_due_at);
    tkService()->replyAsStaff($ticket, $staff, 'Question?', awaitRequester: true);

    tkSlaNow('2026-11-04 12:00');
    tkService()->replyAsRequester($ticket, $requester, 'Answer.');

    expect(tkSlaManila($ticket->fresh()->first_response_due_at))->toBe($before);
});

test('resolving straight from Pending Client ends the pause', function () {
    $staff = tkStaff();

    tkSlaNow('2026-11-03 10:00');
    $ticket = tkOpenTicket(null, 'CAT-02');
    tkService()->replyAsStaff($ticket, $staff, 'Question?', awaitRequester: true);
    expect($ticket->fresh()->sla_paused_at)->not->toBeNull();

    tkService()->resolve($ticket, $staff, 'Sorted out by phone.');

    expect($ticket->fresh()->sla_paused_at)->toBeNull();
});

test('a staff reply that does not ask a question does not pause anything', function () {
    $staff = tkStaff();

    tkSlaNow('2026-11-03 10:00');
    $ticket = tkOpenTicket(null, 'CAT-02');
    tkService()->replyAsStaff($ticket, $staff, 'Looking into it.');

    expect($ticket->fresh()->sla_paused_at)->toBeNull();
});

// ═════════════════════════════════════════════════════════════════════════════
// Restart after "this isn't resolved"
// ═════════════════════════════════════════════════════════════════════════════

test('sending a Resolved ticket back opens a fresh resolution window and clears its flags', function () {
    $staff     = tkStaff();
    $requester = SystemUser::factory()->create();

    tkSlaNow('2026-11-03 10:00');
    $ticket = tkOpenTicket($requester, 'CAT-01');
    tkService()->claim($ticket, $staff);

    tkSlaNow('2026-11-04 11:00'); // already past the Wed 10:00 deadline
    $ticket->forceFill(['resolution_at_risk_at' => now(), 'resolution_breached_at' => now()])->save();
    tkService()->resolve($ticket, $staff, 'Receipt recorded now.');

    tkSlaNow('2026-11-04 15:00');
    tkService()->returnToProgress($ticket, $requester);

    $back = $ticket->fresh();
    // 12 working hours from Wed 15:00: 5 h Wednesday + 7 h Thursday.
    expect(tkSlaManila($back->resolution_due_at))->toBe('2026-11-05 15:00')
        ->and($back->resolution_at_risk_at)->toBeNull()
        ->and($back->resolution_breached_at)->toBeNull();
});

test('replying during the Resolved window restarts the window too', function () {
    $staff     = tkStaff();
    $requester = SystemUser::factory()->create();

    tkSlaNow('2026-11-03 10:00');
    $ticket = tkOpenTicket($requester, 'CAT-01');
    tkService()->resolve($ticket, $staff, 'Receipt recorded.');

    tkSlaNow('2026-11-03 15:00');
    tkService()->replyAsRequester($ticket, $requester, 'Still wrong.');

    expect(tkSlaManila($ticket->fresh()->resolution_due_at))->toBe('2026-11-04 15:00');
});

// ═════════════════════════════════════════════════════════════════════════════
// Claim timeout
// ═════════════════════════════════════════════════════════════════════════════

test('a claim lapses after 24 working hours', function () {
    tkSlaNow('2026-11-03 10:00'); // Tuesday
    $ticket = tkService()->claim(tkOpenTicket(), tkStaff(['View', 'Claim']));

    // 10 h Tuesday + 12 h Wednesday + 2 h Thursday.
    expect(tkSlaManila($ticket->claim_expires_at))->toBe('2026-11-05 10:00');
});

test('a claim taken on Friday does not run out over the weekend', function () {
    tkSlaNow('2026-11-06 10:00'); // Friday
    $ticket = tkService()->claim(tkOpenTicket(), tkStaff(['View', 'Claim']));

    // 10 h Friday + 12 h Tuesday + 2 h Wednesday.
    expect(tkSlaManila($ticket->claim_expires_at))->toBe('2026-11-11 10:00');
});

test('the claim timeout comes from config, and 0 turns it off', function () {
    config(['tickets.claim_timeout_working_hours' => 4]);
    tkSlaNow('2026-11-03 10:00');
    $ticket = tkService()->claim(tkOpenTicket(), tkStaff(['View', 'Claim']));
    expect(tkSlaManila($ticket->claim_expires_at))->toBe('2026-11-03 14:00');

    config(['tickets.claim_timeout_working_hours' => 0]);
    $other = tkService()->claim(tkOpenTicket(), tkStaff(['View', 'Claim']));
    expect($other->claim_expires_at)->toBeNull();
});

test('the claimer replying restarts the timeout; a colleague covering does not', function () {
    $claimer   = tkStaff();
    $colleague = tkStaff();

    tkSlaNow('2026-11-03 10:00');
    $ticket = tkService()->claim(tkOpenTicket(), $claimer);
    expect(tkSlaManila($ticket->claim_expires_at))->toBe('2026-11-05 10:00');

    tkSlaNow('2026-11-03 16:00');
    tkService()->replyAsStaff($ticket, $colleague, 'Covering while you are out.');
    expect(tkSlaManila($ticket->fresh()->claim_expires_at))->toBe('2026-11-05 10:00');

    tkSlaNow('2026-11-04 09:00');
    tkService()->replyAsStaff($ticket, $claimer, 'Back, working on it.');
    // 11 h Wed + 12 h Thu + 1 h Fri.
    expect(tkSlaManila($ticket->fresh()->claim_expires_at))->toBe('2026-11-06 09:00');
});

test('a requester answering a Pending Client ticket gives its claimer a fresh claim window', function () {
    $claimer   = tkStaff();
    $requester = SystemUser::factory()->create();

    tkSlaNow('2026-11-03 10:00');
    $ticket = tkOpenTicket($requester);
    tkService()->claim($ticket, $claimer);
    tkService()->replyAsStaff($ticket, $claimer, 'Question?', awaitRequester: true);

    tkSlaNow('2026-11-04 12:00');
    tkService()->replyAsRequester($ticket, $requester, 'Answer.');

    // 8 h Wed + 12 h Thu + 4 h Fri.
    expect(tkSlaManila($ticket->fresh()->claim_expires_at))->toBe('2026-11-06 12:00');
});

// ═════════════════════════════════════════════════════════════════════════════
// A calendar problem never blocks the real work
// ═════════════════════════════════════════════════════════════════════════════

test('a broken calendar does not stop a ticket being filed or claimed, and is logged', function () {
    Log::spy();
    config(['tickets.sla.calendar_id' => 999999]); // no such calendar

    $ticket = tkOpenTicket(null, 'CAT-01');
    expect($ticket->exists)->toBeTrue()
        ->and($ticket->first_response_due_at)->toBeNull()
        ->and($ticket->resolution_due_at)->toBeNull()
        ->and($ticket->slaState(Ticket::SLA_FIRST_RESPONSE))->toBe(Ticket::SLA_STATE_NONE);

    $claimed = tkService()->claim($ticket, tkStaff(['View', 'Claim']));
    expect($claimed->claimed_by_user_id)->not->toBeNull()
        ->and($claimed->claim_expires_at)->toBeNull();

    Log::shouldHaveReceived('error')->atLeast()->once();
});

test('a custom calendar id is honoured', function () {
    $other = BusinessCalendar::create([
        'name' => 'Short days', 'is_default' => false,
        'weekly_hours' => ['tuesday' => ['open' => '09:00', 'close' => '10:00'], 'wednesday' => ['open' => '09:00', 'close' => '17:00']],
    ]);
    config(['tickets.sla.calendar_id' => $other->calendar_id]);
    tkSlaNow('2026-11-03 09:30'); // Tuesday

    $ticket = tkOpenTicket(null, 'CAT-01');

    // 30 min left on Tuesday, then 3 h 30 min on Wednesday from 09:00.
    expect(tkSlaManila($ticket->first_response_due_at))->toBe('2026-11-04 12:30');
});

// ═════════════════════════════════════════════════════════════════════════════
// SLA state (model) and the at-risk test
// ═════════════════════════════════════════════════════════════════════════════

test('slaState reads the stored columns only', function () {
    tkSlaNow('2026-11-03 10:00');
    $ticket = tkOpenTicket(null, 'CAT-01'); // FR due 14:00, resolution due Wed 10:00
    $fr = Ticket::SLA_FIRST_RESPONSE;

    expect($ticket->slaState($fr))->toBe(Ticket::SLA_STATE_ON_TRACK);

    $ticket->first_response_at_risk_at = now();
    expect($ticket->slaState($fr))->toBe(Ticket::SLA_STATE_AT_RISK);

    expect($ticket->slaState($fr, tkSlaAt('2026-11-03 14:00')->setTimezone('UTC')))->toBe(Ticket::SLA_STATE_AT_RISK) // due is inclusive
        ->and($ticket->slaState($fr, tkSlaAt('2026-11-03 14:01')->setTimezone('UTC')))->toBe(Ticket::SLA_STATE_BREACHED);

    $ticket->first_responded_at = tkSlaAt('2026-11-03 13:00')->setTimezone(config('app.timezone')); // Eloquent stores wall-clock text, so convert first
    expect($ticket->slaState($fr))->toBe(Ticket::SLA_STATE_MET);

    $ticket->first_responded_at = tkSlaAt('2026-11-03 15:00')->setTimezone(config('app.timezone'));
    expect($ticket->slaState($fr))->toBe(Ticket::SLA_STATE_MISSED);

    $ticket->first_responded_at = null;
    $ticket->sla_paused_at = now();
    expect($ticket->slaState(Ticket::SLA_RESOLUTION))->toBe(Ticket::SLA_STATE_PAUSED);
});

test('at risk means 75% of the window used, counted in working time', function () {
    tkSlaNow('2026-11-03 10:00');
    $ticket = tkOpenTicket(null, 'CAT-01'); // first response due 14:00; 240 min window -> at risk with <= 60 min left
    $sla    = app(SlaService::class);
    $fr     = Ticket::SLA_FIRST_RESPONSE;

    expect($sla->isAtRisk($ticket, $fr, tkSlaAt('2026-11-03 12:59')))->toBeFalse()
        ->and($sla->isAtRisk($ticket, $fr, tkSlaAt('2026-11-03 13:00')))->toBeTrue()
        ->and($sla->isAtRisk($ticket, $fr, tkSlaAt('2026-11-03 14:00')))->toBeTrue()
        ->and($sla->isAtRisk($ticket, $fr, tkSlaAt('2026-11-03 14:01')))->toBeFalse(); // past due is "breached", not "at risk"
});

test('at risk ignores the closed days between now and the deadline', function () {
    tkSlaNow('2026-11-06 19:00'); // Friday evening
    $ticket = tkOpenTicket(null, 'CAT-01'); // 1 h Friday + 3 h Tuesday -> due Tue 11:00
    $sla    = app(SlaService::class);
    $fr     = Ticket::SLA_FIRST_RESPONSE;

    expect(tkSlaManila($ticket->first_response_due_at))->toBe('2026-11-10 11:00');

    // Monday noon is 3 working hours before the deadline (Tue 08:00-11:00): not yet at risk,
    // even though only a day of wall-clock time is left.
    expect($sla->isAtRisk($ticket, $fr, tkSlaAt('2026-11-09 12:00')))->toBeFalse()
        ->and($sla->isAtRisk($ticket, $fr, tkSlaAt('2026-11-10 10:00')))->toBeTrue();
});

test('the at-risk ratio comes from config', function () {
    config(['tickets.sla.at_risk_ratio' => 0.5]);
    tkSlaNow('2026-11-03 10:00');
    $ticket = tkOpenTicket(null, 'CAT-01');
    $sla    = app(SlaService::class);

    expect($sla->isAtRisk($ticket, Ticket::SLA_FIRST_RESPONSE, tkSlaAt('2026-11-03 11:59')))->toBeFalse()
        ->and($sla->isAtRisk($ticket, Ticket::SLA_FIRST_RESPONSE, tkSlaAt('2026-11-03 12:00')))->toBeTrue();
});
