<?php

use App\Models\AuditLog;
use App\Models\JobRunLog;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Services\Tickets\TicketChainVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/../../Support/TicketTestHelpers.php';

uses(RefreshDatabase::class);

/*
 * Phase 7 — every ticket's hash-chained event log is covered by audit:verify.
 * Each tampering below is done with direct SQL, bypassing the model's
 * append-only guard, the way a rogue database write would.
 */

/** A ticket with a few events: created, claimed, replied, resolved. */
function tkChainTicket(): Ticket
{
    $staff  = tkStaff();
    $ticket = tkOpenTicket();
    tkService()->claim($ticket, $staff);
    tkService()->replyAsStaff($ticket->fresh(), $staff, 'Looking into it.');
    tkService()->resolve($ticket->fresh(), $staff, 'All done.');

    return $ticket->fresh();
}

function tkVerifyOutput(int $expectedExit): string
{
    expect(Artisan::call('audit:verify'))->toBe($expectedExit);

    return Artisan::output();
}

// ═════════════════════════════════════════════════════════════════════════════
// Untampered
// ═════════════════════════════════════════════════════════════════════════════

test('audit:verify passes when every ticket chain is intact, and reports the ticket events it checked', function () {
    $a = tkChainTicket();
    $b = tkChainTicket();

    $out = tkVerifyOutput(0);

    $total = $a->events()->count() + $b->events()->count();
    expect($out)->toContain("{$total} ticket event(s) across 2 ticket(s) verified");
});

test('audit:verify passes with no tickets at all', function () {
    expect(tkVerifyOutput(0))->toContain('0 ticket event(s) across 0 ticket(s) verified');
});

test('the job-run log counts the ticket events too', function () {
    $ticket = tkChainTicket();
    Artisan::call('audit:verify');

    $log = JobRunLog::where('job_name', 'audit:verify')->latest('job_run_id')->first();

    expect($log->status)->toBe(JobRunLog::STATUS_SUCCESS)
        ->and($log->rows_affected)->toBe(AuditLog::count() + $ticket->events()->count());
});

test('the verifier pages correctly when there are more events than one chunk', function () {
    tkChainTicket();
    tkChainTicket();
    tkChainTicket();

    $all = app(TicketChainVerifier::class)->verifyAll(2);   // chunk of 2 events

    expect($all['breaks'])->toBe([])
        ->and($all['tickets'])->toBe(3)
        ->and($all['events'])->toBe(TicketEvent::count());
});

// ═════════════════════════════════════════════════════════════════════════════
// Tampering is detected
// ═════════════════════════════════════════════════════════════════════════════

test('an edited event is detected, and named by ticket and sequence number', function () {
    $ticket = tkChainTicket();
    $event  = $ticket->events()->where('seq', 2)->first();

    DB::table('ticket_events')->where('id', $event->id)->update(['actor_user_id' => 999999]);

    $out = tkVerifyOutput(1);

    expect($out)->toContain("ticket_id={$ticket->id} seq=2")
        ->toContain('hash does not match the event contents');
});

test('an edited event status or type is detected', function () {
    $ticket = tkChainTicket();

    $last = $ticket->events()->max('seq');

    DB::table('ticket_events')->where('ticket_id', $ticket->id)->where('seq', $last)->update(['to_status' => 'closed']);
    expect(tkVerifyOutput(1))->toContain("ticket_id={$ticket->id} seq={$last}");
});

test('edited event metadata is detected', function () {
    $ticket = tkChainTicket();
    $event  = $ticket->events()->where('event_type', TicketEvent::TYPE_RESOLVED)->first();

    DB::table('ticket_events')->where('id', $event->id)->update(['metadata' => json_encode(['resolution_note' => 'forged'])]);

    expect(tkVerifyOutput(1))->toContain("ticket_id={$ticket->id} seq={$event->seq}");
});

test('a deleted event in the middle is detected', function () {
    $ticket = tkChainTicket();

    DB::table('ticket_events')->where('ticket_id', $ticket->id)->where('seq', 2)->delete();

    expect(tkVerifyOutput(1))->toContain("ticket_id={$ticket->id} seq=3")
        ->toContain('sequence gap');
});

test('a deleted first event is detected', function () {
    $ticket = tkChainTicket();

    DB::table('ticket_events')->where('ticket_id', $ticket->id)->where('seq', 1)->delete();

    expect(tkVerifyOutput(1))->toContain("ticket_id={$ticket->id} seq=2");
});

test('a reordered event is detected', function () {
    $ticket = tkChainTicket();

    // Move event 2 to the end of the sequence.
    DB::table('ticket_events')->where('ticket_id', $ticket->id)->where('seq', 2)->update(['seq' => 99]);

    expect(tkVerifyOutput(1))->toContain("ticket_id={$ticket->id}");
});

test('a forged event with a made-up hash is detected', function () {
    $ticket = tkChainTicket();
    $last   = TicketEvent::where('ticket_id', $ticket->id)->orderByDesc('seq')->first();

    DB::table('ticket_events')->insert([
        'ticket_id' => $ticket->id, 'seq' => $last->seq + 1, 'event_type' => 'closed', 'actor_type' => 'system',
        'actor_user_id' => null, 'from_status' => 'resolved', 'to_status' => 'closed', 'metadata' => null,
        'created_at' => now(), 'prev_hash' => $last->hash, 'hash' => str_repeat('a', 64),
    ]);

    expect(tkVerifyOutput(1))->toContain("ticket_id={$ticket->id} seq=" . ($last->seq + 1));
});

test('an event holding a value that cannot be read back is reported, not a crash', function () {
    $ticket = tkChainTicket();

    DB::table('ticket_events')->where('ticket_id', $ticket->id)->where('seq', 2)->update(['actor_type' => 'not-a-sender']);

    expect(tkVerifyOutput(1))->toContain("ticket_id={$ticket->id} seq=2")
        ->toContain('cannot be read back');
});

test('a ticket whose whole event log was deleted is detected', function () {
    $ticket = tkChainTicket();

    DB::table('ticket_events')->where('ticket_id', $ticket->id)->delete();

    expect(tkVerifyOutput(1))->toContain("ticket_id={$ticket->id}")
        ->toContain('no event log');
});

test('only the first break in a ticket is reported, and other tickets are still checked', function () {
    $a = tkChainTicket();
    $b = tkChainTicket();

    DB::table('ticket_events')->where('ticket_id', $a->id)->where('seq', 2)->update(['actor_user_id' => 999999]);
    DB::table('ticket_events')->where('ticket_id', $a->id)->where('seq', 3)->update(['actor_user_id' => 999999]);
    DB::table('ticket_events')->where('ticket_id', $b->id)->where('seq', 3)->update(['actor_user_id' => 999999]);

    $result = app(TicketChainVerifier::class)->verifyAll();

    expect($result['breaks'])->toHaveCount(2)
        ->and(collect($result['breaks'])->pluck('ticket_id')->sort()->values()->all())->toBe([$a->id, $b->id])
        ->and(collect($result['breaks'])->firstWhere('ticket_id', $a->id)['seq'])->toBe(2);
});

test('a failed check is recorded in job_run_logs as failed and names the ticket', function () {
    $ticket = tkChainTicket();
    DB::table('ticket_events')->where('ticket_id', $ticket->id)->where('seq', 2)->update(['actor_user_id' => 999999]);

    Artisan::call('audit:verify');

    $log = JobRunLog::where('job_name', 'audit:verify')->latest('job_run_id')->first();
    expect($log->status)->toBe(JobRunLog::STATUS_FAILED)
        ->and($log->error_message)->toContain("ticket_id={$ticket->id} seq=2");
});

test('a break in the audit_logs chain is still reported alongside a clean ticket chain', function () {
    tkChainTicket();
    $actor = \App\Models\SystemUser::factory()->create(['role_id' => \App\Models\SystemUser::ROLE_SUPER_ADMIN, 'status' => 'Activated']);
    $logger = app(\App\Services\AuditLogger::class);
    $logger->log(\Illuminate\Http\Request::create('/', 'POST'), $actor, AuditLog::ACTION_LOGIN);
    $row = $logger->log(\Illuminate\Http\Request::create('/', 'POST'), $actor, AuditLog::ACTION_LOGOUT);
    DB::table('audit_logs')->where('id', $row->id)->update(['action' => 'tampered_action']);

    expect(tkVerifyOutput(1))->toContain("audit_logs.id={$row->id}")
        ->not->toContain('ticket_events');
});

test('retention keeps the chain valid: anonymizing a ticket does not break audit:verify', function () {
    $ticket = tkChainTicket();
    tkService()->confirm($ticket->fresh(), null);
    DB::table('tickets')->where('id', $ticket->id)->update(['closed_at' => now()->subYears(4)]);

    expect(Artisan::call('tickets:anonymize-expired'))->toBe(0);

    tkVerifyOutput(0);
});
