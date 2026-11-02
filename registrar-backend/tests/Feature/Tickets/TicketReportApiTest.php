<?php

use App\Models\AuditLog;
use App\Models\Ticket;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/../../Support/TicketSlaTestHelpers.php';
require_once __DIR__ . '/../../Support/TicketPublicTestHelpers.php';

uses(RefreshDatabase::class);

/*
 * Phase 8 — the SLA and volume summary, and the audited log export.
 * Manila calendar dates in; timestamps are stored in UTC (Manila = UTC+8).
 */

beforeEach(function () {
    tkSlaPolicyCalendar();
    tkApiOn();
    tkApiAs(tkApiStaff(['View', 'Manage']));
});
afterEach(fn () => Carbon::setTestNow());

const TK_SUM = '/api/v1/admin/tickets/summary';
const TK_EXP = '/api/v1/admin/tickets/export';

/** Set raw columns on a ticket, bypassing every model hook. */
function tkRaw(Ticket $t, array $cols): void
{
    DB::table('tickets')->where('id', $t->id)->update($cols);
}

// ═════════════════════════════════════════════════════════════════════════════
// Summary: volume
// ═════════════════════════════════════════════════════════════════════════════

test('volume counts tickets filed in the range, by category, priority and status', function () {
    tkSlaNow('2026-11-03 10:00');
    tkOpenTicket(null, 'CAT-01');
    tkOpenTicket(null, 'CAT-01');
    $resolved = tkOpenTicket(null, 'CAT-02');
    tkService()->resolve($resolved, tkStaff(), 'Done.');

    tkSlaNow('2026-11-20 10:00');
    tkOpenTicket(null, 'CAT-05');   // outside the range

    $v = $this->getJson(TK_SUM . '?from=2026-11-03&to=2026-11-04')->assertOk()->json('data.volume');

    expect($v['created'])->toBe(3)
        ->and(collect($v['by_category'])->firstWhere('code', 'CAT-01')['total'])->toBe(2)
        ->and(collect($v['by_category'])->pluck('code')->all())->not->toContain('CAT-05')
        ->and($v['by_priority'])->toBe(['high' => 2, 'medium' => 1])
        ->and($v['by_status'])->toBe(['open' => 2, 'resolved' => 1]);
});

test('per_day is bucketed by Manila day, every day present, and the boundaries are exact', function () {
    tkSlaNow('2026-11-03 10:00');
    $a = tkOpenTicket();
    $b = tkOpenTicket();
    $c = tkOpenTicket();

    tkRaw($a, ['created_at' => '2026-11-03 15:59:59']);   // 23:59:59 Manila on the 3rd
    tkRaw($b, ['created_at' => '2026-11-03 16:00:00']);   // 00:00:00 Manila on the 4th
    tkRaw($c, ['created_at' => '2026-11-02 16:00:00']);   // 00:00:00 Manila on the 3rd

    $perDay = $this->getJson(TK_SUM . '?from=2026-11-02&to=2026-11-05')->json('data.volume.per_day');

    expect($perDay)->toBe([
        ['date' => '2026-11-02', 'total' => 0],
        ['date' => '2026-11-03', 'total' => 2],
        ['date' => '2026-11-04', 'total' => 1],
        ['date' => '2026-11-05', 'total' => 0],
    ]);

    // And a one-day range for the 3rd holds exactly the two tickets Manila calls the 3rd.
    expect($this->getJson(TK_SUM . '?from=2026-11-03&to=2026-11-03')->json('data.volume.created'))->toBe(2);
});

test('closures are split by how they closed, using the closing date', function () {
    tkSlaNow('2026-11-03 10:00');
    $a = tkOpenTicket();
    $b = tkOpenTicket();
    foreach ([$a, $b] as $t) {
        tkService()->resolve($t, tkStaff(), 'Done.');
    }
    tkService()->confirm($a->fresh(), null);
    tkSlaNow('2026-11-06 10:00');
    $this->artisan('tickets:auto-close')->assertExitCode(0);

    expect($this->getJson(TK_SUM . '?from=2026-11-03&to=2026-11-06')->json('data.volume.closed'))->toBe(['confirmed' => 1, 'auto' => 1]);
    expect($this->getJson(TK_SUM . '?from=2026-11-04&to=2026-11-05')->json('data.volume.closed'))->toBe(['confirmed' => 0, 'auto' => 0]);
});

test('returned counts tickets a requester sent back', function () {
    tkSlaNow('2026-11-03 10:00');
    $requester = tkApiRequester();
    $t = tkOpenTicket($requester);
    tkService()->resolve($t, tkStaff(), 'Done.');
    tkService()->returnToProgress($t->fresh(), $requester);
    tkOpenTicket();

    expect($this->getJson(TK_SUM . '?from=2026-11-03&to=2026-11-03')->json('data.volume.returned'))->toBe(1);
});

// ═════════════════════════════════════════════════════════════════════════════
// Summary: SLA, read from the flag columns
// ═════════════════════════════════════════════════════════════════════════════

test('SLA figures count met, breached and pending from the stored flags', function () {
    tkSlaNow('2026-11-03 10:00');
    [$met, $flagged, $late, $pending] = [tkOpenTicket(), tkOpenTicket(), tkOpenTicket(), tkOpenTicket()];

    $due = '2026-11-03 12:00:00';
    foreach ([$met, $flagged, $late, $pending] as $t) {
        tkRaw($t, ['first_response_due_at' => $due, 'resolution_due_at' => '2026-11-06 12:00:00']);
    }

    // met: answered before the deadline, and resolved in time
    tkRaw($met, ['first_responded_at' => '2026-11-03 11:00:00', 'status' => 'resolved', 'resolved_at' => '2026-11-04 01:00:00']);
    // flagged by the sweep: first response and resolution both
    tkRaw($flagged, ['first_response_breached_at' => '2026-11-03 12:07:00', 'resolution_breached_at' => '2026-11-06 12:07:00']);
    // answered late before the sweep noticed: still a breach
    tkRaw($late, ['first_responded_at' => '2026-11-03 13:00:00', 'first_response_at_risk_at' => '2026-11-03 11:37:00']);
    // pending: still inside its time

    $sla = $this->getJson(TK_SUM . '?from=2026-11-03&to=2026-11-03')->json('data.sla');

    expect($sla['first_response'])->toEqual([
        'tracked' => 4, 'met' => 1, 'breached' => 2, 'pending' => 1, 'at_risk_flagged' => 1, 'compliance_pct' => 33.3,
    ])->and($sla['resolution'])->toEqual([
        'tracked' => 4, 'met' => 1, 'breached' => 1, 'pending' => 2, 'at_risk_flagged' => 0, 'compliance_pct' => 50.0,
    ]);
});

test('a ticket sent back after being resolved is not counted as resolved on time', function () {
    tkSlaNow('2026-11-03 10:00');
    $t = tkOpenTicket();
    tkRaw($t, ['resolution_due_at' => '2026-11-06 12:00:00', 'resolved_at' => '2026-11-04 01:00:00', 'status' => 'in_progress']);

    $res = $this->getJson(TK_SUM . '?from=2026-11-03&to=2026-11-03')->json('data.sla.resolution');

    expect($res['met'])->toBe(0)->and($res['pending'])->toBe(1);
});

test('compliance is null when nothing has finished, and tickets without deadlines are not tracked', function () {
    tkSlaNow('2026-11-03 10:00');
    $t = tkOpenTicket();
    tkRaw($t, ['first_response_due_at' => null, 'resolution_due_at' => null]);

    $sla = $this->getJson(TK_SUM . '?from=2026-11-03&to=2026-11-03')->json('data.sla');

    expect($sla['first_response']['tracked'])->toBe(0)->and($sla['first_response']['compliance_pct'])->toBeNull();
});

test('an empty range returns zeros, not errors', function () {
    $data = $this->getJson(TK_SUM . '?from=2020-01-01&to=2020-01-03')->assertOk()->json('data');

    expect($data['volume']['created'])->toBe(0)
        ->and($data['volume']['per_day'])->toHaveCount(3)
        ->and($data['sla']['resolution']['tracked'])->toBe(0);
});

test('the queue snapshot ignores the range and shows what is waiting now', function () {
    tkSlaNow('2026-11-03 10:00');
    tkOpenTicket();
    $claimed = tkService()->claim(tkOpenTicket(), tkStaff(['View', 'Claim']));
    tkService()->resolve(tkOpenTicket(), tkStaff(), 'Done.');

    $q = $this->getJson(TK_SUM . '?from=2020-01-01&to=2020-01-01')->json('data.queue_now');

    expect($q['unclaimed'])->toBe(1)->and($q['claimed'])->toBe(1)->and($q['resolved_waiting'])->toBe(1)
        ->and($q['oldest_unclaimed_at'])->toStartWith('2026-11-03T10:00:00+08:00');
});

test('the range defaults to the last 30 days and is validated', function () {
    tkSlaNow('2026-11-30 10:00');

    $r = $this->getJson(TK_SUM)->assertOk()->json('data.range');
    expect($r['from'])->toBe('2026-11-01')->and($r['to'])->toBe('2026-11-30')->and($r['timezone'])->toBe('Asia/Manila');

    $this->getJson(TK_SUM . '?from=2026-11-10&to=2026-11-01')->assertUnprocessable()->assertJsonValidationErrors('to');
    $this->getJson(TK_SUM . '?from=2024-01-01&to=2026-01-01')->assertUnprocessable()->assertJsonValidationErrors('to');
    $this->getJson(TK_SUM . '?from=11/01/2026')->assertUnprocessable()->assertJsonValidationErrors('from');
});

// ═════════════════════════════════════════════════════════════════════════════
// Export
// ═════════════════════════════════════════════════════════════════════════════

/** Parse a streamed CSV body into [header, rows]. */
function tkCsv(string $body): array
{
    $lines = array_values(array_filter(array_map('str_getcsv', explode("\n", trim($body)))));

    return [array_shift($lines), $lines];
}

test('the export is a CSV of the ticket events in the range, oldest first', function () {
    tkSlaNow('2026-11-03 10:00');
    $ticket = tkOpenTicket(null, 'CAT-01');
    tkService()->claim($ticket, tkStaff(['View', 'Claim']));
    tkSlaNow('2026-11-20 10:00');
    tkOpenTicket();   // outside the range

    $res = $this->get(TK_EXP . '?from=2026-11-03&to=2026-11-03')->assertOk();
    [$header, $rows] = tkCsv($res->streamedContent());

    expect($res->headers->get('Content-Type'))->toContain('text/csv')
        ->and($res->headers->get('Content-Disposition'))->toContain('ticket-log_2026-11-03_2026-11-03.csv')
        ->and($res->headers->get('Cache-Control'))->toContain('no-store')
        ->and($header)->toBe(['ticket_number', 'category', 'seq', 'event_type', 'actor_type', 'actor_user_id', 'from_status', 'to_status', 'created_at', 'hash'])
        ->and($rows)->toHaveCount(2)
        ->and($rows[0][0])->toBe($ticket->ticket_number)
        ->and($rows[0][1])->toBe('CAT-01')
        ->and([$rows[0][3], $rows[1][3]])->toBe(['created', 'claimed'])
        ->and($rows[0][8])->toBe('2026-11-03T10:00:00+08:00')
        ->and($rows[0][9])->toMatch('/^[0-9a-f]{64}$/');
});

test('the export carries no requester details and no message or resolution text', function () {
    tkSlaNow('2026-11-03 10:00');
    tkPubSetup();
    [$ticket] = tkPubTicket('private.person@example.com');
    tkService()->replyAsStaff($ticket, tkStaff(), 'SECRET REPLY TEXT');
    tkService()->resolve($ticket->fresh(), tkStaff(), 'SECRET RESOLUTION NOTE');

    $body = $this->get(TK_EXP . '?from=2026-11-03&to=2026-11-03')->assertOk()->streamedContent();

    expect($body)->not->toContain('private.person')
        ->not->toContain('Juan Dela Cruz')
        ->not->toContain('203.0.113.5')
        ->not->toContain('SECRET')
        ->not->toContain($ticket->subject);
});

test('every export leaves an audit row with the range and row count, and no data', function () {
    tkSlaNow('2026-11-03 10:00');
    tkOpenTicket();
    $manager = tkApiAs(tkApiStaff(['View', 'Manage']));

    $this->get(TK_EXP . '?from=2026-11-03&to=2026-11-03')->assertOk()->streamedContent();

    $row = AuditLog::where('action', AuditLog::ACTION_TICKET_LOG_EXPORTED)->first();
    expect($row)->not->toBeNull()
        ->and($row->user_id)->toBe($manager->user_id)
        ->and($row->metadata)->toMatchArray(['format' => 'csv', 'date_from' => '2026-11-03', 'date_to' => '2026-11-03', 'row_count' => 1]);
});

test('the export fails closed: if the audit row cannot be written, nothing is sent', function () {
    tkSlaNow('2026-11-03 10:00');
    tkOpenTicket();

    app()->instance(AuditLogger::class, new class extends AuditLogger {
        public function log(\Illuminate\Http\Request $request, \App\Models\SystemUser $user, string $action, array $metadata = []): AuditLog
        {
            throw new \RuntimeException('audit store down');
        }
    });

    $res = $this->get(TK_EXP . '?from=2026-11-03&to=2026-11-03');

    expect($res->status())->toBe(500)->and($res->headers->get('Content-Type'))->not->toContain('text/csv');
});

test('a range with too many events is refused, and not audited', function () {
    tkSlaNow('2026-11-03 10:00');
    tkOpenTicket();
    tkOpenTicket();
    config(['tickets.reports.export_max_rows' => 1]);

    $this->getJson(TK_EXP . '?from=2026-11-03&to=2026-11-03')->assertUnprocessable()->assertJsonValidationErrors('to');

    expect(AuditLog::where('action', AuditLog::ACTION_TICKET_LOG_EXPORTED)->count())->toBe(0);
});

test('the export streams correctly across more than one chunk', function () {
    tkSlaNow('2026-11-03 10:00');
    foreach (range(1, 5) as $i) {
        tkOpenTicket();
    }
    config(['tickets.reports.export_chunk_size' => 2]);

    [, $rows] = tkCsv($this->get(TK_EXP . '?from=2026-11-03&to=2026-11-03')->streamedContent());

    expect($rows)->toHaveCount(5)->and(array_unique(array_column($rows, 0)))->toHaveCount(5);
});

test('the export range is validated like the summary', function () {
    $this->getJson(TK_EXP . '?from=2026-11-10&to=2026-11-01')->assertUnprocessable();
    $this->getJson(TK_EXP . '?from=bad')->assertUnprocessable();
    expect(AuditLog::count())->toBe(0);
});
