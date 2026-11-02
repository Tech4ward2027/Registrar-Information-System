<?php

use App\Models\JobRunLog;
use App\Models\SystemUser;
use App\Models\Ticket;
use App\Services\Tickets\TicketEventRecorder;
use App\Services\Tickets\TicketSlaMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

require_once __DIR__ . '/../../Support/TicketSlaTestHelpers.php';

uses(RefreshDatabase::class);

/*
 * Phase 5 — the SLA sweep (TicketSlaMonitor / tickets:sla-sweep). Calendar: open
 * Tue–Fri 08:00–20:00, Monday closed. A High ticket filed Tue 10:00 has its first
 * response due 14:00 (at risk from 13:00) and its resolution due Wed 10:00 (at
 * risk from Tue 19:00).
 */

beforeEach(fn () => tkSlaPolicyCalendar());
afterEach(fn () => Carbon::setTestNow());

function tkSweep(string $manila): array
{
    return app(TicketSlaMonitor::class)->run(tkSlaAt($manila));
}

test('a ticket is flagged at risk and then breached, once each, with one event each', function () {
    tkSlaNow('2026-11-03 10:00');
    $ticket = tkOpenTicket(null, 'CAT-01');

    expect(tkSweep('2026-11-03 12:59'))->toBe(['at_risk' => 0, 'breached' => 0]);

    expect(tkSweep('2026-11-03 13:00'))->toBe(['at_risk' => 1, 'breached' => 0]);
    expect(tkSweep('2026-11-03 14:00'))->toBe(['at_risk' => 0, 'breached' => 0]); // due is inclusive
    expect(tkSweep('2026-11-03 14:01'))->toBe(['at_risk' => 0, 'breached' => 1]);

    $fresh = $ticket->fresh();
    expect($fresh->first_response_at_risk_at)->not->toBeNull()
        ->and($fresh->first_response_breached_at)->not->toBeNull()
        ->and(tkEventTypes($fresh))->toBe(['created', 'sla_at_risk', 'sla_breached']);

    $breach = $fresh->events->last();
    expect($breach->actor_type->value)->toBe('system')
        ->and($breach->actor_user_id)->toBeNull()
        ->and($breach->metadata['target'])->toBe('first_response');
});

test('running the sweep again flags nothing and adds no events', function () {
    tkSlaNow('2026-11-03 10:00');
    $ticket = tkOpenTicket(null, 'CAT-01');

    tkSweep('2026-11-03 15:00');
    $events = $ticket->fresh()->events()->count();

    expect(tkSweep('2026-11-03 15:00'))->toBe(['at_risk' => 0, 'breached' => 0])
        ->and(tkSweep('2026-11-03 16:00'))->toBe(['at_risk' => 0, 'breached' => 0])
        ->and($ticket->fresh()->events()->count())->toBe($events);
});

test('a breach with no earlier at-risk flag sets both flags but logs only the breach', function () {
    tkSlaNow('2026-11-03 10:00');
    $ticket = tkOpenTicket(null, 'CAT-01');

    expect(tkSweep('2026-11-03 15:00'))->toBe(['at_risk' => 0, 'breached' => 1]);

    $fresh = $ticket->fresh();
    expect($fresh->first_response_at_risk_at)->not->toBeNull()
        ->and($fresh->first_response_breached_at)->not->toBeNull()
        ->and(tkEventTypes($fresh))->toBe(['created', 'sla_breached']);
});

test('the sweep is a system action: it does not count as activity and keeps the hash chain valid', function () {
    tkSlaNow('2026-11-03 10:00');
    $ticket = tkOpenTicket(null, 'CAT-01');
    $before = $ticket->last_activity_at;

    tkSweep('2026-11-03 15:00');

    $fresh = $ticket->fresh();
    expect($fresh->last_activity_at->equalTo($before))->toBeTrue()
        ->and(app(TicketEventRecorder::class)->verifyChain($fresh)['ok'])->toBeTrue();
});

test('an answered first response is never flagged', function () {
    tkSlaNow('2026-11-03 10:00');
    $ticket = tkOpenTicket(null, 'CAT-01');
    tkService()->replyAsStaff($ticket, tkStaff(), 'On it.');

    tkSweep('2026-11-03 15:00'); // past the 14:00 first-response deadline, but it was answered at 10:00

    $fresh = $ticket->fresh();
    expect($fresh->first_response_at_risk_at)->toBeNull()
        ->and($fresh->first_response_breached_at)->toBeNull();
});

test('resolution is flagged at risk and then breached on its own deadline', function () {
    tkSlaNow('2026-11-03 10:00');
    $ticket = tkOpenTicket(null, 'CAT-01'); // resolution due Wed 10:00, at risk with <= 180 min left (Tue 19:00)
    tkService()->replyAsStaff($ticket, tkStaff(), 'On it.');

    tkSweep('2026-11-03 18:59');
    expect($ticket->fresh()->resolution_at_risk_at)->toBeNull();

    expect(tkSweep('2026-11-03 19:00'))->toBe(['at_risk' => 1, 'breached' => 0]);
    expect($ticket->fresh()->resolution_at_risk_at)->not->toBeNull();

    expect(tkSweep('2026-11-04 10:01'))->toBe(['at_risk' => 0, 'breached' => 1]);

    $fresh = $ticket->fresh();
    expect($fresh->resolution_breached_at)->not->toBeNull()
        ->and($fresh->events->last()->metadata['target'])->toBe('resolution');
});

test('at risk is measured in working time: closed days do not shorten the window', function () {
    tkSlaNow('2026-11-06 19:00'); // Friday evening
    $ticket = tkOpenTicket(null, 'CAT-01'); // first response due Tue 11:00 (1 h Fri + 3 h Tue)
    expect(tkSlaManila($ticket->first_response_due_at))->toBe('2026-11-10 11:00');

    // Monday noon: a day of wall-clock time left, but 3 working hours: not at risk.
    expect(tkSweep('2026-11-09 12:00'))->toBe(['at_risk' => 0, 'breached' => 0]);

    // Tuesday 10:00: 1 working hour left (<= 25% of 4 h): at risk.
    expect(tkSweep('2026-11-10 10:00'))->toBe(['at_risk' => 1, 'breached' => 0]);
});

test('a ticket waiting on the requester is skipped, and picked up again once they answer', function () {
    $requester = SystemUser::factory()->create();
    tkSlaNow('2026-11-03 10:00');
    $ticket = tkOpenTicket($requester, 'CAT-01'); // resolution due Wed 10:00
    tkService()->replyAsStaff($ticket, tkStaff(), 'Which receipt?', awaitRequester: true);

    expect(tkSweep('2026-11-05 12:00'))->toBe(['at_risk' => 0, 'breached' => 0]); // days later, still paused
    expect($ticket->fresh()->resolution_breached_at)->toBeNull();

    tkSlaNow('2026-11-05 12:00'); // Thursday: answered after 12+ working hours paused
    tkService()->replyAsRequester($ticket, $requester, 'OR-1234.');

    // Due moved back by the wait, so it is not yet breached right after resuming.
    expect(tkSweep('2026-11-05 12:00')['breached'])->toBe(0);
});

test('Resolved and Closed tickets are never flagged', function () {
    tkSlaNow('2026-11-03 10:00');
    $resolved = tkOpenTicket(null, 'CAT-01');
    tkService()->resolve($resolved, tkStaff(), 'Fixed it for you.');

    $closed = tkOpenTicket(null, 'CAT-01');
    tkService()->resolve($closed, tkStaff(), 'Fixed it for you.');
    tkService()->confirm($closed, null);

    expect(tkSweep('2026-11-10 12:00'))->toBe(['at_risk' => 0, 'breached' => 0]);
});

test('a ticket with no deadlines (calendar was unreadable) is ignored', function () {
    tkSlaNow('2026-11-03 10:00');
    $ticket = tkOpenTicket(null, 'CAT-01');
    $ticket->forceFill(['first_response_due_at' => null, 'resolution_due_at' => null])->save();

    expect(tkSweep('2026-11-10 12:00'))->toBe(['at_risk' => 0, 'breached' => 0]);
});

test('the sweep visits every ticket whatever the chunk size', function () {
    config(['tickets.sweep_chunk_size' => 1]);
    tkSlaNow('2026-11-03 10:00');
    foreach (range(1, 3) as $_) {
        tkOpenTicket(null, 'CAT-01');
    }

    expect(tkSweep('2026-11-03 15:00'))->toBe(['at_risk' => 0, 'breached' => 3]);
});

test('tickets:sla-sweep reports what it flagged and is recorded in job_run_logs', function () {
    tkSlaNow('2026-11-03 10:00');
    tkOpenTicket(null, 'CAT-01');

    tkSlaNow('2026-11-03 15:00');
    expect(Artisan::call('tickets:sla-sweep'))->toBe(0)
        ->and(Artisan::output())->toContain('1 breached');

    $run = JobRunLog::where('job_name', 'tickets:sla-sweep')->orderByDesc('job_run_id')->firstOrFail();
    expect($run->status)->toBe(JobRunLog::STATUS_SUCCESS)
        ->and($run->rows_affected)->toBe(1);

    // Twice is safe.
    expect(Artisan::call('tickets:sla-sweep'))->toBe(0)
        ->and(JobRunLog::where('job_name', 'tickets:sla-sweep')->orderByDesc('job_run_id')->firstOrFail()->rows_affected)->toBe(0);
});
