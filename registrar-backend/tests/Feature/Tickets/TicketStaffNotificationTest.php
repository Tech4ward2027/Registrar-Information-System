<?php

use App\Models\SystemUser;
use App\Services\Tickets\TicketSlaMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

require_once __DIR__ . '/../../Support/TicketNotificationTestHelpers.php';

uses(RefreshDatabase::class);

/*
 * Phase 6 — what staff are told: bell only, never email, and only about tickets
 * they can still open. Calendar for the SLA cases: open Tue–Fri 08:00–20:00,
 * Monday closed; a High ticket filed Tue 10:00 has its first response due 14:00.
 */

beforeEach(function () {
    Mail::fake();
    tkSlaPolicyCalendar();
});
afterEach(fn () => Carbon::setTestNow());

function tkStaffSweep(string $manila): void
{
    app(TicketSlaMonitor::class)->run(tkSlaAt($manila));
}

// ── Requester replied ────────────────────────────────────────────────────────

test('a requester\'s reply tells whoever holds the ticket, in the bell only', function () {
    $requester = tkNotifRequester();
    $claimer   = tkStaff(['View', 'Claim', 'Reply']);
    $ticket    = tkOpenTicket($requester);
    tkService()->claim($ticket, $claimer);

    tkService()->replyAsRequester($ticket, $requester, 'Here is the receipt.');

    expect(tkNotifTypes($claimer))->toBe(['ticket_requester_reply']);
    Mail::assertNothingQueued();
});

test('a requester\'s reply on an unclaimed ticket notifies nobody (it sits in the queue)', function () {
    $requester = tkNotifRequester();
    $bystander = tkStaff(['View', 'Claim', 'Reply']);
    $ticket    = tkOpenTicket($requester);

    tkService()->replyAsRequester($ticket, $requester, 'Anyone there?');

    expect(tkNotifs($bystander))->toBeEmpty();
});

test('a claimer who can no longer see tickets is not told about them', function () {
    $requester = tkNotifRequester();
    $claimer   = tkStaff(['Claim', 'Reply']); // no View
    $ticket    = tkOpenTicket($requester);
    tkService()->claim($ticket, $claimer);

    tkService()->replyAsRequester($ticket, $requester, 'Hello?');

    expect(tkNotifs($claimer))->toBeEmpty();
});

test('a deactivated claimer is not told', function () {
    $requester = tkNotifRequester();
    $claimer   = tkStaff(['View', 'Claim', 'Reply']);
    $ticket    = tkOpenTicket($requester);
    tkService()->claim($ticket, $claimer);
    $claimer->forceFill(['status' => 'Pending Verification'])->save();

    tkService()->replyAsRequester($ticket, $requester, 'Hello?');

    expect(tkNotifs($claimer))->toBeEmpty();
});

// ── Sent back ────────────────────────────────────────────────────────────────

test('"this isn\'t resolved" tells whoever resolved it', function () {
    $requester = tkNotifRequester();
    $resolver  = tkStaff();
    $other     = tkStaff();
    $ticket    = tkOpenTicket($requester);
    tkService()->resolve($ticket, $resolver, 'Receipt recorded.');

    tkService()->returnToProgress($ticket, $requester);

    expect(tkNotifTypes($resolver))->toBe(['ticket_returned'])
        ->and(tkNotifs($other))->toBeEmpty();
});

test('a reply that sends a Resolved ticket back is one notification, not two', function () {
    $requester = tkNotifRequester();
    $resolver  = tkStaff();
    $ticket    = tkOpenTicket($requester);
    tkService()->resolve($ticket, $resolver, 'Receipt recorded.');

    tkService()->replyAsRequester($ticket, $requester, 'Still wrong, sorry.');

    expect(tkNotifTypes($resolver))->toBe(['ticket_returned']);
});

// ── Claim released for inactivity ────────────────────────────────────────────

test('a claimer is told when their claim is released for inactivity', function () {
    $claimer = tkStaff(['View', 'Claim', 'Reply']);
    tkSlaNow('2026-11-03 10:00');
    $ticket = tkOpenTicket(tkNotifRequester());
    tkService()->claim($ticket, $claimer);

    expect(tkService()->expireClaim($ticket, tkSlaAt('2026-11-05 10:00')))->toBeTrue();

    expect(tkNotifTypes($claimer))->toBe(['ticket_claim_expired'])
        ->and(tkNotifs($claimer)->first()->data['message'])->toContain('24 working hours')->toContain($ticket->ticket_number);
});

test('a claim that was not released tells nobody', function () {
    $claimer = tkStaff(['View', 'Claim', 'Reply']);
    tkSlaNow('2026-11-03 10:00');
    $ticket = tkOpenTicket(tkNotifRequester());
    tkService()->claim($ticket, $claimer);

    expect(tkService()->expireClaim($ticket, tkSlaAt('2026-11-05 09:59')))->toBeFalse();

    expect(tkNotifs($claimer))->toBeEmpty();
});

// ── SLA ──────────────────────────────────────────────────────────────────────

test('an SLA breach on a claimed ticket tells the claimer, not the managers', function () {
    $claimer = tkStaff(['View', 'Claim', 'Reply']);
    $manager = tkStaff(['View', 'Manage']);
    tkSlaNow('2026-11-03 10:00');
    $ticket = tkOpenTicket(tkNotifRequester(), 'CAT-01');
    tkService()->claim($ticket, $claimer);

    tkStaffSweep('2026-11-03 15:00'); // first response was due 14:00

    expect(tkNotifTypes($claimer))->toBe(['ticket_sla_breached'])
        ->and(tkNotifs($claimer)->first()->data['message'])->toContain('first response')
        ->and(tkNotifs($manager))->toBeEmpty();
});

test('an SLA breach on an unclaimed ticket tells staff who can manage tickets, and nobody else', function () {
    $manager    = tkStaff(['View', 'Manage']);
    $viewOnly   = tkStaff(['View', 'Claim', 'Reply']);
    $noView     = tkStaff(['Manage']);
    $superAdmin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN, 'status' => 'Activated']);
    $student    = tkNotifRequester();

    tkSlaNow('2026-11-03 10:00');
    tkOpenTicket(tkNotifRequester(), 'CAT-01');

    tkStaffSweep('2026-11-03 15:00');

    expect(tkNotifTypes($manager))->toBe(['ticket_sla_breached'])
        ->and(tkNotifTypes($superAdmin))->toBe(['ticket_sla_breached'])
        ->and(tkNotifs($viewOnly))->toBeEmpty()
        ->and(tkNotifs($noView))->toBeEmpty()
        ->and(tkNotifs($student))->toBeEmpty();
});

test('at-risk and breached are each announced once, however many times the sweep runs', function () {
    $manager = tkStaff(['View', 'Manage']);
    tkSlaNow('2026-11-03 10:00');
    tkOpenTicket(tkNotifRequester(), 'CAT-01');

    tkStaffSweep('2026-11-03 13:00'); // at risk (<= 60 min left of 240)
    tkStaffSweep('2026-11-03 13:30');
    expect(tkNotifTypes($manager))->toBe(['ticket_sla_at_risk']);

    tkStaffSweep('2026-11-03 14:01'); // breached
    tkStaffSweep('2026-11-03 15:00');
    tkStaffSweep('2026-11-03 18:00'); // still before the resolution deadline's own at-risk window (Tue 19:00)

    expect(tkNotifTypes($manager))->toBe(['ticket_sla_at_risk', 'ticket_sla_breached']);
});

test('the resolution deadline is announced as such', function () {
    $manager = tkStaff(['View', 'Manage']);
    tkSlaNow('2026-11-03 10:00');
    $ticket = tkOpenTicket(tkNotifRequester(), 'CAT-01');
    tkService()->replyAsStaff($ticket, tkStaff(), 'On it.'); // first response met, claimed by that staff member
    $ticket->forceFill(['claimed_by_user_id' => null, 'claimed_at' => null, 'claim_expires_at' => null])->save();

    tkStaffSweep('2026-11-04 10:01'); // resolution due Wed 10:00

    expect(tkNotifTypes($manager))->toBe(['ticket_sla_breached'])
        ->and(tkNotifs($manager)->first()->data['message'])->toContain('resolution');
});

test('staff notifications are never emailed', function () {
    $manager = tkStaff(['View', 'Manage']);
    tkSlaNow('2026-11-03 10:00');
    tkOpenTicket(tkNotifRequester(), 'CAT-01');

    tkStaffSweep('2026-11-03 15:00');

    expect(tkNotifs($manager))->toHaveCount(1);
    Mail::assertNothingQueued();
});
