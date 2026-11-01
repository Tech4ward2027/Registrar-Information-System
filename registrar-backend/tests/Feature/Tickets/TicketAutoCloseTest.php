<?php

use App\Enums\TicketStatusEnum;
use App\Exceptions\Tickets\TicketActionNotAllowedException;
use App\Events\NotificationSent;
use App\Mail\TicketNotificationMail;
use App\Models\JobRunLog;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Services\Tickets\Notifications\TicketNotifier;
use App\Services\Tickets\SlaService;
use App\Services\Tickets\TicketEventRecorder;
use App\Services\Tickets\TicketNumberGenerator;
use App\Services\Tickets\TicketService;
use App\Services\Tickets\TicketStateMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;

require_once __DIR__ . '/../../Support/TicketNotificationTestHelpers.php';

uses(RefreshDatabase::class);

/*
 * Phase 7 — tickets:auto-close. A ticket resolved Tuesday 3 Nov 2026 10:00
 * (Manila) is due to close 3 CALENDAR days later, Friday 6 Nov 10:00; the
 * day-2 reminder is due Thursday 5 Nov 10:00.
 */

beforeEach(function () {
    tkSlaPolicyCalendar();
    Mail::fake();
    Event::fake([NotificationSent::class]);
});
afterEach(fn () => Carbon::setTestNow());

/** A ticket resolved at the given Manila time. Returns [ticket, requester]. */
function tkAutoResolved(string $resolvedAt = '2026-11-03 10:00'): array
{
    tkSlaNow($resolvedAt);
    $requester = tkNotifRequester();
    $ticket    = tkOpenTicket($requester);
    tkService()->resolve($ticket, tkStaff(), 'Done. Please confirm.');

    return [$ticket->fresh(), $requester];
}

function tkAutoRun(string $manila): string
{
    tkSlaNow($manila);
    expect(Artisan::call('tickets:auto-close'))->toBe(0);

    return Artisan::output();
}

// ═════════════════════════════════════════════════════════════════════════════
// Closing
// ═════════════════════════════════════════════════════════════════════════════

test('a Resolved ticket closes 3 calendar days after it was resolved, and not a minute before', function () {
    [$ticket] = tkAutoResolved();

    tkAutoRun('2026-11-06 09:59');
    expect($ticket->fresh()->status)->toBe(TicketStatusEnum::Resolved);

    expect(tkAutoRun('2026-11-06 10:00'))->toContain('Closed 1 ticket(s)');

    $fresh = $ticket->fresh();
    expect($fresh->status)->toBe(TicketStatusEnum::Closed)
        ->and($fresh->close_reason)->toBe(Ticket::CLOSE_REASON_AUTO)
        ->and(tkSlaManila($fresh->closed_at))->toBe('2026-11-06 10:00')
        ->and($fresh->auto_close_at)->toBeNull();
});

test('the calendar-day window ignores the closed Monday: resolved Friday closes Monday', function () {
    [$ticket] = tkAutoResolved('2026-11-06 10:00');   // Friday

    tkAutoRun('2026-11-09 09:59');   // Monday, business calendar closed
    expect($ticket->fresh()->status)->toBe(TicketStatusEnum::Resolved);

    tkAutoRun('2026-11-09 10:00');
    expect($ticket->fresh()->status)->toBe(TicketStatusEnum::Closed);
});

test('closing is logged as a system event and the ticket chain stays valid', function () {
    [$ticket] = tkAutoResolved();
    tkAutoRun('2026-11-06 10:00');

    $event = $ticket->events()->where('event_type', TicketEvent::TYPE_CLOSED)->first();

    expect($event)->not->toBeNull()
        ->and($event->actor_type->value)->toBe('system')
        ->and($event->from_status)->toBe(TicketStatusEnum::Resolved)
        ->and($event->to_status)->toBe(TicketStatusEnum::Closed)
        ->and($event->metadata['reason'])->toBe('auto')
        ->and(app(TicketEventRecorder::class)->verifyChain($ticket)['ok'])->toBeTrue();
});

test('the requester is told it closed: one email and one bell entry', function () {
    [$ticket, $requester] = tkAutoResolved();
    Mail::fake();   // forget the "resolved" mail
    $before = tkNotifs($requester)->count();

    tkAutoRun('2026-11-06 10:00');

    Mail::assertQueued(TicketNotificationMail::class, 1);
    expect(tkNotifMails()->first()->kind)->toBe('closed')
        ->and(tkNotifs($requester)->count())->toBe($before + 1)
        ->and(tkNotifTypes($requester))->toContain('ticket_closed');
});

test('a public submitter is told by email only', function () {
    tkPubSetup();
    tkSlaNow('2026-11-03 10:00');
    [$ticket] = tkPubTicket('juan@example.com');
    tkService()->resolve($ticket, tkStaff(), 'Done. Please confirm.');
    Mail::fake();

    tkAutoRun('2026-11-06 10:00');

    Mail::assertQueued(TicketNotificationMail::class, 1);
    expect(tkNotifMails()->first()->kind)->toBe('closed')
        ->and(tkNotifMails()->first()->hasTo('juan@example.com'))->toBeTrue();
});

test('running it twice is safe: the second run changes and sends nothing', function () {
    [$ticket] = tkAutoResolved();
    tkAutoRun('2026-11-06 10:00');
    Mail::fake();
    $events = $ticket->events()->count();

    expect(tkAutoRun('2026-11-06 10:30'))->toContain('Closed 0 ticket(s), sent 0 reminder(s)');

    Mail::assertNothingQueued();
    expect($ticket->events()->count())->toBe($events)
        ->and($ticket->fresh()->status)->toBe(TicketStatusEnum::Closed);
});

test('a ticket the requester already confirmed is left alone', function () {
    [$ticket] = tkAutoResolved();
    tkService()->confirm($ticket, null);
    $closedAt = $ticket->fresh()->closed_at;

    tkAutoRun('2026-11-07 10:00');

    $fresh = $ticket->fresh();
    expect($fresh->close_reason)->toBe(Ticket::CLOSE_REASON_CONFIRMED)
        ->and($fresh->closed_at->equalTo($closedAt))->toBeTrue();
});

test('a ticket sent back to In Progress is not closed, and can be resolved again with a fresh window', function () {
    [$ticket, $requester] = tkAutoResolved();
    tkSlaNow('2026-11-04 10:00');
    tkService()->returnToProgress($ticket, $requester);

    tkAutoRun('2026-11-06 10:00');
    expect($ticket->fresh()->status)->toBe(TicketStatusEnum::InProgress);

    tkSlaNow('2026-11-06 12:00');
    tkService()->resolve($ticket->fresh(), tkStaff(), 'Fixed properly this time.');

    tkAutoRun('2026-11-09 11:59');
    expect($ticket->fresh()->status)->toBe(TicketStatusEnum::Resolved);

    tkAutoRun('2026-11-09 12:00');
    expect($ticket->fresh()->status)->toBe(TicketStatusEnum::Closed);
});

test('open, in progress and pending client tickets are never auto-closed', function () {
    tkSlaNow('2026-11-03 10:00');
    $open       = tkOpenTicket(tkNotifRequester());
    $inProgress = tkService()->claim(tkOpenTicket(tkNotifRequester()), tkStaff(['View', 'Claim']));
    $pending    = tkService()->claim(tkOpenTicket(tkNotifRequester()), $staff = tkStaff());
    tkService()->replyAsStaff($pending, $staff, 'What is your student number?', true);

    tkAutoRun('2026-12-30 10:00');

    expect($open->fresh()->status)->toBe(TicketStatusEnum::Open)
        ->and($inProgress->fresh()->status)->toBe(TicketStatusEnum::InProgress)
        ->and($pending->fresh()->status)->toBe(TicketStatusEnum::PendingClient);
});

test('a Closed ticket cannot be sent back: closing is final', function () {
    [$ticket, $requester] = tkAutoResolved();
    tkAutoRun('2026-11-06 10:00');

    expect(fn () => tkService()->returnToProgress($ticket->fresh(), $requester))->toThrow(TicketActionNotAllowedException::class);
    expect($ticket->fresh()->status)->toBe(TicketStatusEnum::Closed);
});

// ═════════════════════════════════════════════════════════════════════════════
// The day-2 reminder
// ═════════════════════════════════════════════════════════════════════════════

test('the reminder goes out on day 2, once, and not before', function () {
    [$ticket, $requester] = tkAutoResolved();
    Mail::fake();

    expect(tkAutoRun('2026-11-05 09:59'))->toContain('sent 0 reminder(s)');
    Mail::assertNothingQueued();

    expect(tkAutoRun('2026-11-05 10:00'))->toContain('sent 1 reminder(s)');
    Mail::assertQueued(TicketNotificationMail::class, 1);
    expect(tkNotifMails()->first()->kind)->toBe('resolution_reminder')
        ->and(tkNotifTypes($requester))->toContain('ticket_resolution_reminder')
        ->and($ticket->fresh()->reminder_sent_at)->not->toBeNull()
        ->and(tkEventTypes($ticket))->toContain(TicketEvent::TYPE_REMINDER_SENT);

    // Later the same day, and the next day: still only one.
    tkAutoRun('2026-11-05 18:00');
    tkAutoRun('2026-11-06 09:00');
    Mail::assertQueued(TicketNotificationMail::class, 1);
    expect(collect(tkEventTypes($ticket))->filter(fn ($t) => $t === TicketEvent::TYPE_REMINDER_SENT))->toHaveCount(1);
});

test('the reminder does not change the ticket status or its close date', function () {
    [$ticket] = tkAutoResolved();
    $closeAt = $ticket->auto_close_at;

    tkAutoRun('2026-11-05 10:00');

    $fresh = $ticket->fresh();
    expect($fresh->status)->toBe(TicketStatusEnum::Resolved)
        ->and($fresh->auto_close_at->equalTo($closeAt))->toBeTrue()
        ->and(app(TicketEventRecorder::class)->verifyChain($fresh)['ok'])->toBeTrue();
});

test('a requester who replied is not reminded: the ticket is back In Progress', function () {
    [$ticket, $requester] = tkAutoResolved();
    tkSlaNow('2026-11-04 10:00');
    tkService()->replyAsRequester($ticket, $requester, 'It is still not working.');
    Mail::fake();

    tkAutoRun('2026-11-05 10:00');

    Mail::assertNothingQueued();
    expect($ticket->fresh()->reminder_sent_at)->toBeNull();
});

test('a ticket the scheduler only reaches after the window ended is closed, not reminded', function () {
    [$ticket] = tkAutoResolved();
    Mail::fake();

    tkAutoRun('2026-11-07 10:00');   // the job was down for two days

    Mail::assertQueued(TicketNotificationMail::class, 1);
    expect(tkNotifMails()->first()->kind)->toBe('closed')
        ->and($ticket->fresh()->status)->toBe(TicketStatusEnum::Closed)
        ->and($ticket->fresh()->reminder_sent_at)->toBeNull();
});

test('a ticket resolved a second time can be reminded again for the new window', function () {
    [$ticket, $requester] = tkAutoResolved();
    tkAutoRun('2026-11-05 10:00');   // reminder #1
    tkSlaNow('2026-11-05 11:00');
    tkService()->returnToProgress($ticket, $requester);
    tkSlaNow('2026-11-05 12:00');
    tkService()->resolve($ticket->fresh(), tkStaff(), 'Fixed again.');
    expect($ticket->fresh()->reminder_sent_at)->toBeNull();
    Mail::fake();

    tkAutoRun('2026-11-07 12:00');

    Mail::assertQueued(TicketNotificationMail::class, 1);
    expect(tkNotifMails()->first()->kind)->toBe('resolution_reminder');
});

// ═════════════════════════════════════════════════════════════════════════════
// Job health and resilience
// ═════════════════════════════════════════════════════════════════════════════

test('each run is recorded in job_run_logs with the number of tickets it changed', function () {
    tkAutoResolved('2026-11-03 10:00');
    tkAutoResolved('2026-11-03 11:00');
    tkAutoRun('2026-11-06 12:00');   // both closed

    $log = JobRunLog::where('job_name', 'tickets:auto-close')->latest('job_run_id')->first();

    expect($log->status)->toBe(JobRunLog::STATUS_SUCCESS)
        ->and($log->rows_affected)->toBe(2);

    tkAutoRun('2026-11-06 12:30');
    expect(JobRunLog::where('job_name', 'tickets:auto-close')->latest('job_run_id')->first()->rows_affected)->toBe(0)
        ->and(JobRunLog::where('job_name', 'tickets:auto-close')->count())->toBe(2);
});

test('the jobs are registered in Scheduled Jobs Health and on the schedule', function () {
    foreach (['tickets:auto-close', 'tickets:anonymize-expired'] as $job) {
        expect(JobRunLog::JOBS)->toHaveKey($job)
            ->and(JobRunLog::EXPECTED_INTERVAL_MINUTES)->toHaveKey($job);
    }

    Artisan::call('schedule:list');
    $list = preg_replace('/\s+/', ' ', Artisan::output());

    expect($list)->toContain('17,47 * * * * php artisan tickets:auto-close')
        ->toContain('45 3 * * * php artisan tickets:anonymize-expired');
});

test('one ticket that fails does not stop the others, and the run is reported as failed', function () {
    [$bad]  = tkAutoResolved('2026-11-03 10:00');
    [$good] = tkAutoResolved('2026-11-03 11:00');

    // A service that fails to close just the first ticket.
    app()->instance(TicketService::class, new class(
        app(TicketNumberGenerator::class), app(TicketStateMachine::class), app(TicketEventRecorder::class),
        app(SlaService::class), app(TicketNotifier::class), $bad->id,
    ) extends TicketService {
        public function __construct($n, $m, $e, $s, $no, private int $failId)
        {
            parent::__construct($n, $m, $e, $s, $no);
        }

        public function autoClose(Ticket $ticket, \Carbon\CarbonInterface $now): bool
        {
            if ($ticket->getKey() === $this->failId) {
                throw new \RuntimeException('boom');
            }

            return parent::autoClose($ticket, $now);
        }
    });

    tkSlaNow('2026-11-06 12:00');
    expect(Artisan::call('tickets:auto-close'))->toBe(1);

    expect($good->fresh()->status)->toBe(TicketStatusEnum::Closed)
        ->and($bad->fresh()->status)->toBe(TicketStatusEnum::Resolved);

    $log = JobRunLog::where('job_name', 'tickets:auto-close')->latest('job_run_id')->first();
    expect($log->status)->toBe(JobRunLog::STATUS_FAILED)
        ->and($log->error_message)->toContain("ticket #{$bad->id}");
});
