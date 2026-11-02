<?php

use App\Enums\TicketStatusEnum;
use App\Models\JobRunLog;
use App\Models\Ticket;
use App\Models\TicketAccessToken;
use App\Models\TicketEvent;
use App\Models\TicketPublicSubmission;
use App\Services\Tickets\TicketEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/../../Support/TicketNotificationTestHelpers.php';

uses(RefreshDatabase::class);

/*
 * Phase 7 — tickets:anonymize-expired. Retention is 3 years from the day a
 * ticket was Closed. Personal data goes; the ticket, its messages and its
 * hash-chained event log stay.
 */

beforeEach(function () {
    tkSlaPolicyCalendar();
    tkPubSetup();
});
afterEach(fn () => Carbon::setTestNow());

/** Close a ticket through the real service, then backdate the closing. */
function tkRetClosed(Ticket $ticket, ?Carbon $closedAt = null): Ticket
{
    tkService()->resolve($ticket, tkStaff(), 'Done.');
    tkService()->confirm($ticket->fresh(), null);

    DB::table('tickets')->where('id', $ticket->id)->update(['closed_at' => $closedAt ?? now()->subYears(4)]);

    return $ticket->fresh();
}

function tkRetRun(string ...$options): string
{
    $args = [];
    foreach ($options as $o) {
        $args[$o] = true;
    }
    expect(Artisan::call('tickets:anonymize-expired', $args))->toBe(0);

    return Artisan::output();
}

// ═════════════════════════════════════════════════════════════════════════════
// Public submitters
// ═════════════════════════════════════════════════════════════════════════════

test('a public submitter\'s name, email and IP are removed once the ticket has been closed for 3 years', function () {
    [$ticket] = tkPubTicket('juan@example.com');
    tkRetClosed($ticket);

    expect(tkRetRun())->toContain('anonymized 1 ticket(s)');

    $fresh = $ticket->fresh();
    expect($fresh->requester_name)->toBeNull()
        ->and($fresh->requester_email)->toBeNull()
        ->and($fresh->requester_ip)->toBeNull()
        ->and($fresh->anonymized_at)->not->toBeNull()
        ->and($fresh->status)->toBe(TicketStatusEnum::Closed);
});

test('the ticket, its messages and its event log are kept', function () {
    [$ticket] = tkPubTicket();
    tkService()->replyAsStaff($ticket, tkStaff(), 'We are looking into it.');
    tkRetClosed($ticket);
    $messages = $ticket->messages()->count();
    $events   = $ticket->events()->count();

    tkRetRun();

    expect($ticket->fresh()->subject)->toBe($ticket->subject)
        ->and($ticket->messages()->count())->toBe($messages)
        ->and($ticket->events()->count())->toBe($events + 1);   // + the "anonymized" event
});

test('the retention action is itself logged on the ticket chain, without personal data, and the chain stays valid', function () {
    [$ticket] = tkPubTicket('juan@example.com');
    tkRetClosed($ticket);
    tkRetRun();

    $event = TicketEvent::where('ticket_id', $ticket->id)->orderByDesc('seq')->first();

    expect($event->event_type)->toBe(TicketEvent::TYPE_ANONYMIZED)
        ->and($event->actor_type->value)->toBe('system')
        ->and($event->metadata)->toEqual(['had_account' => false, 'retention_years' => 3])
        ->and(json_encode($ticket->events()->get()->map->metadata))->not->toContain('juan@example.com')
        ->and(app(TicketEventRecorder::class)->verifyChain($ticket)['ok'])->toBeTrue();
});

test('the magic link stops working and its token rows are gone', function () {
    [$ticket, $token] = tkPubTicket();
    tkRetClosed($ticket);
    // Keep the link inside its normal 7-day window so only anonymization can kill it.
    config(['tickets.public.token_grace_days' => 100000]);
    $this->getJson('/api/v1/public/tickets/track', ['X-Ticket-Token' => $token])->assertOk();

    tkRetRun();

    expect(TicketAccessToken::where('ticket_id', $ticket->id)->count())->toBe(0);
    $this->getJson('/api/v1/public/tickets/track', ['X-Ticket-Token' => $token])->assertNotFound();
});

test('confirmed submission rows that still hold the email and IP are deleted', function () {
    [$ticket] = tkPubTicket('juan@example.com');
    TicketPublicSubmission::create([
        'token_hash' => hash('sha256', 'x'), 'email' => 'juan@example.com', 'ip' => '203.0.113.5',
        'expires_at' => now()->addDay(), 'consumed_at' => now(), 'ticket_id' => $ticket->id,
    ]);
    $other = TicketPublicSubmission::create([
        'token_hash' => hash('sha256', 'y'), 'email' => 'other@example.com', 'ip' => '203.0.113.6',
        'expires_at' => now()->addDay(),
    ]);
    tkRetClosed($ticket);

    tkRetRun();

    expect(TicketPublicSubmission::where('ticket_id', $ticket->id)->count())->toBe(0)
        ->and(TicketPublicSubmission::find($other->id))->not->toBeNull();   // someone else's pending one is not touched
});

// ═════════════════════════════════════════════════════════════════════════════
// Signed-in requesters
// ═════════════════════════════════════════════════════════════════════════════

test('a signed-in requester\'s account link is cleared on the ticket and on their own messages', function () {
    $requester = tkNotifRequester();
    $ticket    = tkOpenTicket($requester);
    tkService()->replyAsRequester($ticket, $requester, 'Any news?');
    $staff = tkStaff();
    tkService()->replyAsStaff($ticket->fresh(), $staff, 'Yes.');
    tkRetClosed($ticket);

    tkRetRun();

    $fresh = $ticket->fresh();
    expect($fresh->requester_user_id)->toBeNull()
        ->and($fresh->anonymized_at)->not->toBeNull()
        ->and($ticket->messages()->where('sender_type', 'requester')->whereNotNull('sender_user_id')->count())->toBe(0)
        ->and($ticket->messages()->where('sender_type', 'staff')->whereNotNull('sender_user_id')->count())->toBeGreaterThan(0)
        ->and(TicketEvent::where('ticket_id', $ticket->id)->orderByDesc('seq')->first()->metadata['had_account'])->toBeTrue();
});

// ═════════════════════════════════════════════════════════════════════════════
// What is NOT touched
// ═════════════════════════════════════════════════════════════════════════════

test('nothing is anonymized before the 3-year mark', function () {
    [$ticket] = tkPubTicket('juan@example.com');
    tkRetClosed($ticket, now()->subYears(3)->addDay());

    expect(tkRetRun())->toContain('anonymized 0 ticket(s)');

    expect($ticket->fresh()->requester_email)->toBe('juan@example.com')
        ->and($ticket->fresh()->anonymized_at)->toBeNull();
});

test('the boundary is exact: closed exactly 3 years ago is anonymized', function () {
    Carbon::setTestNow('2030-06-15 12:00:00');
    [$ticket] = tkPubTicket();
    tkService()->resolve($ticket, tkStaff(), 'Done.');
    tkService()->confirm($ticket->fresh(), null);
    DB::table('tickets')->where('id', $ticket->id)->update(['closed_at' => now()->subYears(3)]);

    expect(tkRetRun())->toContain('anonymized 1 ticket(s)');
});

test('tickets that are not Closed are never anonymized, however old', function () {
    [$open]     = tkPubTicket('a@example.com');
    [$resolved] = tkPubTicket('b@example.com');
    tkService()->resolve($resolved, tkStaff(), 'Done.');
    DB::table('tickets')->whereIn('id', [$open->id, $resolved->id])->update(['created_at' => now()->subYears(5), 'resolved_at' => now()->subYears(5)]);

    tkRetRun();

    expect($open->fresh()->requester_email)->toBe('a@example.com')
        ->and($resolved->fresh()->requester_email)->toBe('b@example.com');
});

test('the retention period comes from config', function () {
    config(['tickets.retention.years' => 1]);
    [$ticket] = tkPubTicket();
    tkRetClosed($ticket, now()->subMonths(13));

    expect(tkRetRun())->toContain('anonymized 1 ticket(s)');
});

// ═════════════════════════════════════════════════════════════════════════════
// Safe to run again, dry run, job health
// ═════════════════════════════════════════════════════════════════════════════

test('running it twice is safe: the second run changes nothing', function () {
    [$ticket] = tkPubTicket();
    tkRetClosed($ticket);
    tkRetRun();
    $events = $ticket->events()->count();
    $stamp  = $ticket->fresh()->anonymized_at;

    expect(tkRetRun())->toContain('anonymized 0 ticket(s)');

    expect($ticket->events()->count())->toBe($events)
        ->and($ticket->fresh()->anonymized_at->equalTo($stamp))->toBeTrue();
});

test('a dry run reports what is due and changes nothing', function () {
    [$ticket] = tkPubTicket('juan@example.com');
    tkRetClosed($ticket);

    expect(tkRetRun('--dry-run'))->toContain('1 ticket(s)')->toContain('Nothing changed');

    expect($ticket->fresh()->requester_email)->toBe('juan@example.com')
        ->and($ticket->fresh()->anonymized_at)->toBeNull()
        ->and(TicketEvent::where('event_type', TicketEvent::TYPE_ANONYMIZED)->count())->toBe(0);
});

test('it works through more tickets than one chunk', function () {
    config(['tickets.retention.chunk_size' => 2]);
    foreach (['a', 'b', 'c', 'd', 'e'] as $n) {
        [$ticket] = tkPubTicket("{$n}@example.com");
        tkRetClosed($ticket);
    }

    expect(tkRetRun())->toContain('anonymized 5 ticket(s)');
    expect(Ticket::whereNull('anonymized_at')->count())->toBe(0);
});

test('each run is recorded in job_run_logs', function () {
    [$ticket] = tkPubTicket();
    tkRetClosed($ticket);
    tkRetRun();

    $log = JobRunLog::where('job_name', 'tickets:anonymize-expired')->latest('job_run_id')->first();

    expect($log->status)->toBe(JobRunLog::STATUS_SUCCESS)
        ->and($log->rows_affected)->toBe(1);
});

test('an anonymized ticket is still readable, and is no longer treated as a public submission', function () {
    [$ticket] = tkPubTicket();
    tkRetClosed($ticket);
    tkRetRun();

    expect(Ticket::find($ticket->id)->requester_email)->toBeNull()
        ->and(Ticket::find($ticket->id)->isPublicSubmission())->toBeFalse();
});
