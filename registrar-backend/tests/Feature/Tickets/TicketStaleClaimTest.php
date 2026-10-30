<?php

use App\Enums\TicketStatusEnum;
use App\Models\JobRunLog;
use App\Models\SystemUser;
use App\Models\Ticket;
use App\Services\Tickets\TicketEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

require_once __DIR__ . '/../../Support/TicketSlaTestHelpers.php';

uses(RefreshDatabase::class);

/*
 * Phase 5 — the claim timeout (tickets:release-stale-claims). A claim taken
 * Tuesday 10:00 lapses 24 working hours later: Thursday 10:00. Calendar: open
 * Tue–Fri 08:00–20:00, Monday closed.
 */

beforeEach(fn () => tkSlaPolicyCalendar());
afterEach(fn () => Carbon::setTestNow());

function tkReleaseStale(string $manila): string
{
    tkSlaNow($manila);
    expect(Artisan::call('tickets:release-stale-claims'))->toBe(0);

    return Artisan::output();
}

test('a claim is released once its timeout passes, and not a minute before', function () {
    tkSlaNow('2026-11-03 10:00');
    $ticket = tkService()->claim(tkOpenTicket(), tkStaff(['View', 'Claim']));

    expect(tkReleaseStale('2026-11-05 09:59'))->toContain('Released 0');
    expect($ticket->fresh()->claimed_by_user_id)->not->toBeNull();

    expect(tkReleaseStale('2026-11-05 10:00'))->toContain('Released 1');

    $fresh = $ticket->fresh();
    expect($fresh->claimed_by_user_id)->toBeNull()
        ->and($fresh->claimed_at)->toBeNull()
        ->and($fresh->claim_expires_at)->toBeNull()
        ->and($fresh->status)->toBe(TicketStatusEnum::InProgress); // status is left alone
});

test('the release is logged as a system event naming the previous claimer, and the chain stays valid', function () {
    $staff = tkStaff(['View', 'Claim']);
    tkSlaNow('2026-11-03 10:00');
    $ticket = tkService()->claim(tkOpenTicket(), $staff);

    tkReleaseStale('2026-11-05 10:00');

    $fresh = $ticket->fresh();
    $event = $fresh->events->last();

    expect(tkEventTypes($fresh))->toBe(['created', 'claimed', 'claim_expired'])
        ->and($event->actor_type->value)->toBe('system')
        ->and($event->actor_user_id)->toBeNull()
        ->and($event->metadata['previous_claimer'])->toBe($staff->user_id)
        ->and(app(TicketEventRecorder::class)->verifyChain($fresh)['ok'])->toBeTrue();
});

test('running it again releases nothing and logs nothing more', function () {
    tkSlaNow('2026-11-03 10:00');
    $ticket = tkService()->claim(tkOpenTicket(), tkStaff(['View', 'Claim']));

    tkReleaseStale('2026-11-05 10:00');
    $events = $ticket->fresh()->events()->count();

    expect(tkReleaseStale('2026-11-05 11:00'))->toContain('Released 0')
        ->and($ticket->fresh()->events()->count())->toBe($events);
});

test('the claimer replying before the deadline keeps the claim alive', function () {
    $staff = tkStaff();
    tkSlaNow('2026-11-03 10:00');
    $ticket = tkService()->claim(tkOpenTicket(), $staff);

    tkSlaNow('2026-11-04 09:00');
    tkService()->replyAsStaff($ticket, $staff, 'Still working on it.'); // new expiry: Fri 09:00

    expect(tkReleaseStale('2026-11-05 10:00'))->toContain('Released 0')
        ->and($ticket->fresh()->claimed_by_user_id)->toBe($staff->user_id);

    expect(tkReleaseStale('2026-11-06 09:00'))->toContain('Released 1');
});

test('a released ticket can be claimed by someone else', function () {
    tkSlaNow('2026-11-03 10:00');
    $ticket = tkService()->claim(tkOpenTicket(), tkStaff(['View', 'Claim']));
    tkReleaseStale('2026-11-05 10:00');

    $other   = tkStaff(['View', 'Claim']);
    $claimed = tkService()->claim($ticket, $other);

    expect($claimed->claimed_by_user_id)->toBe($other->user_id);
});

test('a ticket waiting on the requester keeps its claim however long the wait', function () {
    $staff = tkStaff();
    tkSlaNow('2026-11-03 10:00');
    $ticket = tkService()->claim(tkOpenTicket(), $staff);
    tkService()->replyAsStaff($ticket, $staff, 'Which receipt?', awaitRequester: true);

    expect(tkReleaseStale('2026-11-13 12:00'))->toContain('Released 0')
        ->and($ticket->fresh()->claimed_by_user_id)->toBe($staff->user_id);
});

test('answering a Pending Client ticket does not leave the claimer with an already-expired claim', function () {
    $staff     = tkStaff();
    $requester = SystemUser::factory()->create();
    tkSlaNow('2026-11-03 10:00');
    $ticket = tkService()->claim(tkOpenTicket($requester), $staff);
    tkService()->replyAsStaff($ticket, $staff, 'Which receipt?', awaitRequester: true);

    tkSlaNow('2026-11-10 09:00'); // a week later, long past the original expiry
    tkService()->replyAsRequester($ticket, $requester, 'OR-1234.');

    expect(tkReleaseStale('2026-11-10 09:30'))->toContain('Released 0')
        ->and($ticket->fresh()->claimed_by_user_id)->toBe($staff->user_id);
});

test('a claim with no expiry never lapses by itself', function () {
    config(['tickets.claim_timeout_working_hours' => 0]);
    tkSlaNow('2026-11-03 10:00');
    $ticket = tkService()->claim(tkOpenTicket(), tkStaff(['View', 'Claim']));

    expect(tkReleaseStale('2027-01-05 10:00'))->toContain('Released 0')
        ->and($ticket->fresh()->claimed_by_user_id)->not->toBeNull();
});

test('a stale copy of the ticket cannot release a claim that was renewed in the meantime', function () {
    $staff = tkStaff();
    tkSlaNow('2026-11-03 10:00');
    $ticket = tkService()->claim(tkOpenTicket(), $staff);
    $stale  = Ticket::findOrFail($ticket->id); // still carries the Thursday 10:00 expiry

    tkSlaNow('2026-11-05 09:00');
    tkService()->replyAsStaff($ticket, $staff, 'Here, working.'); // renews the claim to Tue 09:00

    tkSlaNow('2026-11-05 11:00'); // after the OLD expiry
    expect(tkService()->expireClaim($stale, now()))->toBeFalse()
        ->and($ticket->fresh()->claimed_by_user_id)->toBe($staff->user_id);
});

test('releasing by hand first makes the sweep a no-op', function () {
    $staff = tkStaff(['View', 'Claim']);
    tkSlaNow('2026-11-03 10:00');
    $ticket = tkService()->claim(tkOpenTicket(), $staff);
    tkService()->release($ticket, $staff);

    expect(tkReleaseStale('2026-11-05 10:00'))->toContain('Released 0')
        ->and(tkEventTypes($ticket->fresh()))->toBe(['created', 'claimed', 'released']);
});

test('the run is recorded in job_run_logs', function () {
    tkSlaNow('2026-11-03 10:00');
    tkService()->claim(tkOpenTicket(), tkStaff(['View', 'Claim']));

    tkReleaseStale('2026-11-05 10:00');

    $run = JobRunLog::where('job_name', 'tickets:release-stale-claims')->orderByDesc('job_run_id')->firstOrFail();
    expect($run->status)->toBe(JobRunLog::STATUS_SUCCESS)
        ->and($run->rows_affected)->toBe(1);
});

test('both Phase 5 jobs are scheduled and registered with the job monitor', function () {
    foreach (['tickets:sla-sweep', 'tickets:release-stale-claims'] as $job) {
        expect(JobRunLog::JOBS)->toHaveKey($job)
            ->and(JobRunLog::EXPECTED_INTERVAL_MINUTES)->toHaveKey($job);
    }

    Artisan::call('schedule:list');

    // schedule:list pads the cron column to the widest expression, so collapse
    // runs of whitespace before matching literal cron strings.
    $list = preg_replace('/\s+/', ' ', Artisan::output());

    expect($list)->toContain('tickets:sla-sweep')
        ->toContain('7,22,37,52 * * * *')
        ->toContain('tickets:release-stale-claims')
        ->toContain('12,42 * * * *');
});