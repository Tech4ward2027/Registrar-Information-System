<?php

use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

require_once __DIR__ . '/../../Support/TicketSlaTestHelpers.php';

uses(RefreshDatabase::class);

/*
 * Phase 5 — what the staff API shows and filters. Calendar: open Tue–Fri
 * 08:00–20:00, Monday closed. A High ticket filed Tue 10:00 has its first
 * response due 14:00 and its resolution due Wed 10:00.
 */

const TK_SLA_QUEUE = '/api/v1/admin/tickets';

beforeEach(function () {
    tkSlaPolicyCalendar();
    tkApiOn();
});
afterEach(fn () => Carbon::setTestNow());

function tkSlaQueueIds(string $query = ''): array
{
    return collect(test()->getJson(TK_SLA_QUEUE . '?scope=all' . $query)->assertOk()->json('data'))->pluck('id')->all();
}

test('queue rows carry both deadlines and where the ticket stands against them', function () {
    tkSlaNow('2026-11-03 10:00');
    $ticket = tkOpenTicket(null, 'CAT-01');
    tkApiAs(tkApiStaff(['View']));

    $row = $this->getJson(TK_SLA_QUEUE . '?scope=all')->assertOk()->json('data.0');

    expect($row['sla'])->toBe([
        'paused'         => false,
        'first_response' => ['due_at' => $ticket->first_response_due_at->toIso8601String(), 'state' => 'on_track'],
        'resolution'     => ['due_at' => $ticket->resolution_due_at->toIso8601String(), 'state' => 'on_track'],
    ]);
});

test('breached shows the moment the deadline passes, without waiting for the sweep', function () {
    tkSlaNow('2026-11-03 10:00');
    tkOpenTicket(null, 'CAT-01');
    tkApiAs(tkApiStaff(['View']));

    tkSlaNow('2026-11-03 14:01');

    $row = $this->getJson(TK_SLA_QUEUE . '?scope=all')->assertOk()->json('data.0');
    expect($row['sla']['first_response']['state'])->toBe('breached')
        ->and($row['sla']['resolution']['state'])->toBe('on_track');
});

test('at risk shows once the sweep has flagged it', function () {
    tkSlaNow('2026-11-03 10:00');
    $ticket = tkOpenTicket(null, 'CAT-01');
    tkApiAs(tkApiStaff(['View']));

    $ticket->forceFill(['first_response_at_risk_at' => now()])->save();

    expect($this->getJson(TK_SLA_QUEUE . '?scope=all')->json('data.0.sla.first_response.state'))->toBe('at_risk');
});

test('the detail view has the same sla block', function () {
    tkSlaNow('2026-11-03 10:00');
    $ticket = tkOpenTicket(null, 'CAT-01');
    tkApiAs(tkApiStaff(['View']));

    $this->getJson(tkApiUrl($ticket->public_id, '', true))->assertOk()
        ->assertJsonPath('data.sla.first_response.state', 'on_track')
        ->assertJsonPath('data.sla.paused', false);
});

test('a ticket waiting on the requester shows as paused', function () {
    tkSlaNow('2026-11-03 10:00');
    $ticket = tkOpenTicket(null, 'CAT-01');
    tkService()->replyAsStaff($ticket, tkStaff(), 'Which receipt?', awaitRequester: true);
    tkApiAs(tkApiStaff(['View']));

    tkSlaNow('2026-11-06 12:00');

    $row = $this->getJson(TK_SLA_QUEUE . '?scope=all')->assertOk()->json('data.0');
    expect($row['sla']['paused'])->toBeTrue()
        ->and($row['sla']['resolution']['state'])->toBe('paused');
});

test('the sla filter shows breached and at-risk tickets, and only ones staff can still act on', function () {
    tkSlaNow('2026-11-03 10:00');
    $breached  = tkOpenTicket(null, 'CAT-01');
    $atRisk    = tkOpenTicket(null, 'CAT-01');
    $untouched = tkOpenTicket(null, 'CAT-01');
    $paused    = tkOpenTicket(null, 'CAT-01');
    $answered  = tkOpenTicket(null, 'CAT-01');
    $resolved  = tkOpenTicket(null, 'CAT-01');

    $breached->forceFill(['first_response_at_risk_at' => now(), 'first_response_breached_at' => now()])->save();
    $atRisk->forceFill(['first_response_at_risk_at' => now()])->save();
    $paused->forceFill(['first_response_breached_at' => now(), 'sla_paused_at' => now()])->save();
    $answered->forceFill(['first_response_breached_at' => now(), 'first_responded_at' => now()])->save();
    $resolved->forceFill(['first_response_breached_at' => now(), 'status' => 'resolved', 'resolved_at' => now()])->save();

    tkApiAs(tkApiStaff(['View']));

    expect(tkSlaQueueIds('&sla=breached'))->toBe([$breached->public_id])
        ->and(tkSlaQueueIds('&sla=at_risk'))->toBe([$atRisk->public_id])
        ->and(tkSlaQueueIds())->toHaveCount(6 - 1 /* resolved is not in the default active view */);
});

test('an overdue resolution counts as breached too', function () {
    tkSlaNow('2026-11-03 10:00');
    $ticket = tkOpenTicket(null, 'CAT-01');
    tkOpenTicket(null, 'CAT-01');
    $ticket->forceFill(['resolution_at_risk_at' => now(), 'resolution_breached_at' => now()])->save();
    tkApiAs(tkApiStaff(['View']));

    expect(tkSlaQueueIds('&sla=breached'))->toBe([$ticket->public_id]);
});

test('an unknown sla filter is a validation error', function () {
    tkApiAs(tkApiStaff(['View']));

    $this->getJson(TK_SLA_QUEUE . '?sla=late')->assertUnprocessable()->assertJsonValidationErrors(['sla']);
});

test('requesters never see deadlines or SLA state', function () {
    tkSlaNow('2026-11-03 10:00');
    $student = tkApiRequester();
    $ticket  = tkOpenTicket($student, 'CAT-01');
    tkApiAs($student);

    $json = $this->getJson(tkApiUrl($ticket->public_id))->assertOk()->getContent();

    // Key names are checked with their quotes: a bare 'sla' could match inside a random name.
    expect($json)->not->toContain('"due_at"')->not->toContain('"sla"')->not->toContain('"breached"');
    expect($this->getJson('/api/v1/tickets')->assertOk()->getContent())->not->toContain('"due_at"')->not->toContain('"sla"');
});
