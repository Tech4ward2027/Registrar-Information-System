<?php

use App\Models\AuditLog;
use App\Models\Ticket;
use App\Models\TicketBlock;
use App\Models\TicketCategory;
use App\Models\TicketEvent;
use App\Services\Tickets\PublicSubmission\TicketBlockList;
use App\Services\Tickets\TicketEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/../../Support/TicketPublicTestHelpers.php';

uses(RefreshDatabase::class);

/*
 * Phase 8 — category management, the spam blocklist and flagging spam. Every
 * route needs the tickets "Manage" permission on top of View.
 */

const TK_ADMIN = '/api/v1/admin/tickets';

function tkManager(): \App\Models\SystemUser
{
    tkApiOn();

    return tkApiAs(tkApiStaff(['View', 'Manage']));
}

function tkAudits(string $action)
{
    return AuditLog::where('action', $action)->get();
}

// ═════════════════════════════════════════════════════════════════════════════
// Gates
// ═════════════════════════════════════════════════════════════════════════════

test('every management route answers 404 while the flag is off', function () {
    tkApiAs(tkApiStaff(['View', 'Manage']));

    foreach (['/categories', '/blocks', '/summary', '/export'] as $path) {
        $this->getJson(TK_ADMIN . $path)->assertNotFound();
    }
    $this->postJson(TK_ADMIN . '/categories', [])->assertNotFound();
});

test('staff without Manage cannot use any management route, even with every other permission', function () {
    tkApiOn();
    tkApiAs(tkApiStaff(['View', 'Claim', 'Reply', 'Resolve']));
    $ticket = tkOpenTicket();
    $block  = TicketBlock::create(['type' => 'email', 'value' => 'x@example.com']);

    $this->getJson(TK_ADMIN . '/categories')->assertForbidden();
    $this->postJson(TK_ADMIN . '/categories', ['code' => 'CAT-09', 'name' => 'Other', 'priority' => 'low'])->assertForbidden();
    $this->patchJson(TK_ADMIN . '/categories/1', ['name' => 'Hacked'])->assertForbidden();
    $this->getJson(TK_ADMIN . '/blocks')->assertForbidden();
    $this->postJson(TK_ADMIN . '/blocks', ['type' => 'email', 'value' => 'a@example.com'])->assertForbidden();
    $this->deleteJson(TK_ADMIN . "/blocks/{$block->id}")->assertForbidden();
    $this->postJson(tkApiUrl($ticket->public_id, '/spam', true))->assertForbidden();
    $this->getJson(TK_ADMIN . '/summary')->assertForbidden();
    $this->getJson(TK_ADMIN . '/export')->assertForbidden();

    expect(AuditLog::count())->toBe(0)->and(TicketBlock::count())->toBe(1);
});

test('Manage without View is not enough either', function () {
    tkApiOn();
    tkApiAs(tkApiStaff(['Manage']));

    $this->getJson(TK_ADMIN . '/categories')->assertForbidden();
});

test('a student cannot reach the management routes', function () {
    tkApiOn();
    tkApiAs(tkApiRequester());

    $this->getJson(TK_ADMIN . '/categories')->assertForbidden();
    $this->getJson(TK_ADMIN . '/summary')->assertForbidden();
});

// ═════════════════════════════════════════════════════════════════════════════
// Categories
// ═════════════════════════════════════════════════════════════════════════════

test('the list shows every category, inactive ones too, with how many tickets use it', function () {
    tkManager();
    tkOpenTicket(null, 'CAT-01');
    tkOpenTicket(null, 'CAT-01');
    TicketCategory::where('code', 'CAT-05')->update(['is_active' => false]);

    $data = $this->getJson(TK_ADMIN . '/categories')->assertOk()->json('data');

    expect($data)->toHaveCount(5)
        ->and(collect($data)->firstWhere('code', 'CAT-01')['tickets_count'])->toBe(2)
        ->and(collect($data)->firstWhere('code', 'CAT-05')['is_active'])->toBeFalse()
        ->and(array_column($data, 'code'))->toBe(['CAT-01', 'CAT-02', 'CAT-03', 'CAT-04', 'CAT-05']);
});

test('a category can be created, and the creation is audited', function () {
    $manager = tkManager();

    $this->postJson(TK_ADMIN . '/categories', [
        'code' => 'CAT-06', 'name' => 'Scholarship Concerns', 'description' => 'Questions about scholarships.', 'priority' => 'medium',
    ])->assertCreated()
        ->assertJsonPath('data.code', 'CAT-06')
        ->assertJsonPath('data.priority', 'medium')
        ->assertJsonPath('data.is_active', true)
        ->assertJsonPath('data.sort_order', 6);

    $audit = tkAudits(AuditLog::ACTION_TICKET_CATEGORY_CREATED);
    expect($audit)->toHaveCount(1)
        ->and($audit[0]->user_id)->toBe($manager->user_id)
        ->and($audit[0]->metadata['category_code'])->toBe('CAT-06');
});

test('category input is validated: bad code, duplicate code, bad priority, short name', function () {
    tkManager();

    $this->postJson(TK_ADMIN . '/categories', ['code' => 'cat6', 'name' => 'Valid name', 'priority' => 'low'])->assertUnprocessable()->assertJsonValidationErrors('code');
    $this->postJson(TK_ADMIN . '/categories', ['code' => 'CAT-01', 'name' => 'Valid name', 'priority' => 'low'])->assertUnprocessable()->assertJsonValidationErrors('code');
    $this->postJson(TK_ADMIN . '/categories', ['code' => 'CAT-07', 'name' => 'Valid name', 'priority' => 'urgent'])->assertUnprocessable()->assertJsonValidationErrors('priority');
    $this->postJson(TK_ADMIN . '/categories', ['code' => 'CAT-07', 'name' => 'ab', 'priority' => 'low'])->assertUnprocessable()->assertJsonValidationErrors('name');

    expect(TicketCategory::count())->toBe(5)->and(AuditLog::count())->toBe(0);
});

test('a category can be edited; the code never changes; the audit row lists what changed', function () {
    tkManager();
    $cat = TicketCategory::where('code', 'CAT-04')->first();

    $this->patchJson(TK_ADMIN . "/categories/{$cat->id}", ['name' => 'Releasing Inquiries', 'priority' => 'medium', 'code' => 'CAT-99'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Releasing Inquiries')
        ->assertJsonPath('data.priority', 'medium')
        ->assertJsonPath('data.code', 'CAT-04');

    $audit = tkAudits(AuditLog::ACTION_TICKET_CATEGORY_UPDATED)->first();
    expect($audit->metadata['category_code'])->toBe('CAT-04')
        ->and($audit->metadata['changes']['priority'])->toBe(['low', 'medium'])
        ->and($audit->metadata['changes'])->toHaveKey('name');
});

test('an edit that changes nothing is not audited', function () {
    tkManager();
    $cat = TicketCategory::where('code', 'CAT-04')->first();

    $this->patchJson(TK_ADMIN . "/categories/{$cat->id}", ['name' => $cat->name, 'priority' => 'low'])->assertOk();

    expect(AuditLog::count())->toBe(0);
});

test('changing a priority affects new tickets only, not tickets already filed', function () {
    tkManager();
    $existing = tkOpenTicket(null, 'CAT-02');   // medium
    $cat      = TicketCategory::where('code', 'CAT-02')->first();

    $this->patchJson(TK_ADMIN . "/categories/{$cat->id}", ['priority' => 'high'])->assertOk();

    $fresh = tkOpenTicket(null, 'CAT-02');
    expect($existing->fresh()->priority->value)->toBe('medium')
        ->and($fresh->priority->value)->toBe('high');
});

test('a deactivated category is no longer offered, and its existing tickets are untouched', function () {
    tkManager();
    $ticket = tkOpenTicket(null, 'CAT-05');
    $cat    = TicketCategory::where('code', 'CAT-05')->first();

    $this->patchJson(TK_ADMIN . "/categories/{$cat->id}", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);

    $offered = collect($this->getJson('/api/v1/tickets/categories')->assertOk()->json('data'))->pluck('code');
    expect($offered)->not->toContain('CAT-05')->and($ticket->fresh()->category_id)->toBe($cat->id);
});

test('the last active category cannot be deactivated', function () {
    tkManager();
    TicketCategory::whereNotIn('code', ['CAT-01'])->update(['is_active' => false]);
    $last = TicketCategory::where('code', 'CAT-01')->first();

    $this->patchJson(TK_ADMIN . "/categories/{$last->id}", ['is_active' => false])
        ->assertUnprocessable()->assertJsonValidationErrors('is_active');

    expect($last->fresh()->is_active)->toBeTrue()->and(AuditLog::count())->toBe(0);
});

test('a category that does not exist is a 404', function () {
    tkManager();

    $this->patchJson(TK_ADMIN . '/categories/99999', ['name' => 'Whatever'])->assertNotFound();
});

// ═════════════════════════════════════════════════════════════════════════════
// Blocklist
// ═════════════════════════════════════════════════════════════════════════════

test('an email block is stored lower-case and stops that address from filing', function () {
    $manager = tkManager();

    $this->postJson(TK_ADMIN . '/blocks', ['type' => 'email', 'value' => '  Spammer@Example.COM ', 'reason' => 'Repeated junk'])
        ->assertCreated()
        ->assertJsonPath('data.value', 'spammer@example.com')
        ->assertJsonPath('data.in_force', true);

    expect(app(TicketBlockList::class)->isBlocked('SPAMMER@example.com', null))->toBeTrue()
        ->and(TicketBlock::first()->created_by_user_id)->toBe($manager->user_id);
});

test('an IP block is stored in canonical form and matches', function () {
    tkManager();

    $this->postJson(TK_ADMIN . '/blocks', ['type' => 'ip', 'value' => '2001:0DB8:0000:0000:0000:0000:0000:0001'])
        ->assertCreated()->assertJsonPath('data.value', '2001:db8::1');

    expect(app(TicketBlockList::class)->isBlocked('someone@example.com', '2001:db8::1'))->toBeTrue();
});

test('block input is validated', function () {
    tkManager();

    $this->postJson(TK_ADMIN . '/blocks', ['type' => 'email', 'value' => 'not-an-email'])->assertUnprocessable()->assertJsonValidationErrors('value');
    $this->postJson(TK_ADMIN . '/blocks', ['type' => 'ip', 'value' => '999.1.1.1'])->assertUnprocessable()->assertJsonValidationErrors('value');
    $this->postJson(TK_ADMIN . '/blocks', ['type' => 'phone', 'value' => '123'])->assertUnprocessable()->assertJsonValidationErrors('type');
    $this->postJson(TK_ADMIN . '/blocks', ['type' => 'email', 'value' => 'a@example.com', 'expires_at' => now()->subDay()->toIso8601String()])
        ->assertUnprocessable()->assertJsonValidationErrors('expires_at');

    expect(TicketBlock::count())->toBe(0);
});

test('adding a value that is already listed renews it instead of failing', function () {
    tkManager();
    $this->postJson(TK_ADMIN . '/blocks', ['type' => 'email', 'value' => 'a@example.com', 'expires_at' => now()->addDay()->toIso8601String()])->assertCreated();

    $this->postJson(TK_ADMIN . '/blocks', ['type' => 'email', 'value' => 'A@example.com', 'reason' => 'Again'])
        ->assertOk()->assertJsonPath('data.reason', 'Again')->assertJsonPath('data.expires_at', null);

    expect(TicketBlock::count())->toBe(1);
});

test('the audit log records a block by a short fingerprint, never the address', function () {
    tkManager();
    $this->postJson(TK_ADMIN . '/blocks', ['type' => 'email', 'value' => 'private.person@example.com'])->assertCreated();
    $block = TicketBlock::first();
    $this->deleteJson(TK_ADMIN . "/blocks/{$block->id}")->assertOk();

    $all = json_encode(AuditLog::all()->map->metadata);
    expect($all)->not->toContain('private.person')->and($all)->not->toContain('@example.com');

    $added = tkAudits(AuditLog::ACTION_TICKET_BLOCK_ADDED)->first();
    expect($added->metadata['value_sha256'])->toBe(substr(hash('sha256', 'private.person@example.com'), 0, 16));
    expect(tkAudits(AuditLog::ACTION_TICKET_BLOCK_REMOVED))->toHaveCount(1);
});

test('removing a block lets the address file again', function () {
    tkManager();
    $this->postJson(TK_ADMIN . '/blocks', ['type' => 'email', 'value' => 'a@example.com'])->assertCreated();
    $block = TicketBlock::first();

    $this->deleteJson(TK_ADMIN . "/blocks/{$block->id}")->assertOk();

    expect(app(TicketBlockList::class)->isBlocked('a@example.com', null))->toBeFalse()->and(TicketBlock::count())->toBe(0);
});

test('the list shows active blocks by default and can show expired or all', function () {
    tkManager();
    TicketBlock::create(['type' => 'email', 'value' => 'live@example.com']);
    TicketBlock::create(['type' => 'email', 'value' => 'timed@example.com', 'expires_at' => now()->addDay()]);
    TicketBlock::create(['type' => 'ip', 'value' => '203.0.113.9', 'expires_at' => now()->subDay()]);

    expect($this->getJson(TK_ADMIN . '/blocks')->json('data'))->toHaveCount(2);
    expect($this->getJson(TK_ADMIN . '/blocks?state=expired')->json('data'))->toHaveCount(1);
    expect($this->getJson(TK_ADMIN . '/blocks?state=all')->json('data'))->toHaveCount(3);
    expect($this->getJson(TK_ADMIN . '/blocks?state=all&type=ip')->json('data'))->toHaveCount(1);
});

// ═════════════════════════════════════════════════════════════════════════════
// Flagging spam
// ═════════════════════════════════════════════════════════════════════════════

test('flagging spam logs it on the ticket, optionally blocks the submitter, and audits it', function () {
    tkPubSetup();
    $manager = tkManager();
    [$ticket] = tkPubTicket('junk@example.com');

    $this->postJson(tkApiUrl($ticket->public_id, '/spam', true), ['block_email' => true, 'block_ip' => true, 'reason' => 'Advertising'])
        ->assertOk();

    $event = TicketEvent::where('ticket_id', $ticket->id)->where('event_type', TicketEvent::TYPE_SPAM_FLAGGED)->first();
    expect($event)->not->toBeNull()
        ->and($event->actor_user_id)->toBe($manager->user_id)
        ->and($event->metadata['blocked'])->toBe(['email', 'ip'])
        ->and(json_encode($event->metadata))->not->toContain('junk@example.com')->not->toContain('Advertising')
        ->and(app(TicketEventRecorder::class)->verifyChain($ticket)['ok'])->toBeTrue();

    expect(app(TicketBlockList::class)->isBlocked('junk@example.com', null))->toBeTrue()
        ->and(TicketBlock::where('type', 'ip')->where('value', '203.0.113.5')->exists())->toBeTrue()
        ->and(TicketBlock::where('type', 'email')->first()->reason)->toBe('Advertising');

    expect(tkAudits(AuditLog::ACTION_TICKET_SPAM_FLAGGED)->first()->metadata)
        ->toMatchArray(['ticket_number' => $ticket->ticket_number, 'blocked' => ['email', 'ip']]);
});

test('flagging without blocking only records the flag and leaves the ticket status alone', function () {
    tkPubSetup();
    tkManager();
    [$ticket] = tkPubTicket();

    $this->postJson(tkApiUrl($ticket->public_id, '/spam', true))->assertOk();

    expect(TicketBlock::count())->toBe(0)
        ->and($ticket->fresh()->status->value)->toBe('open')
        ->and(tkEventTypes($ticket))->toContain(TicketEvent::TYPE_SPAM_FLAGGED);
});

test('a signed-in requester can be flagged but not blocked', function () {
    tkManager();
    $ticket = tkOpenTicket();

    $this->postJson(tkApiUrl($ticket->public_id, '/spam', true), ['block_email' => true])
        ->assertUnprocessable()->assertJsonValidationErrors('block');

    expect(TicketBlock::count())->toBe(0)
        ->and(tkEventTypes($ticket))->not->toContain(TicketEvent::TYPE_SPAM_FLAGGED)
        ->and(AuditLog::count())->toBe(0);   // nothing half-done: the refusal rolled everything back
});

test('an anonymized public ticket cannot be blocked', function () {
    tkPubSetup();
    tkManager();
    [$ticket] = tkPubTicket();
    DB::table('tickets')->where('id', $ticket->id)->update(['requester_email' => null, 'requester_ip' => null, 'anonymized_at' => now()]);

    $this->postJson(tkApiUrl($ticket->public_id, '/spam', true), ['block_email' => true])->assertUnprocessable();
});

test('flagging spam needs Manage and the ticket must exist', function () {
    tkApiOn();
    tkApiAs(tkApiStaff(['View', 'Reply']));
    $ticket = tkOpenTicket();

    $this->postJson(tkApiUrl($ticket->public_id, '/spam', true))->assertForbidden();

    tkApiAs(tkApiStaff(['View', 'Manage']));
    $this->postJson(tkApiUrl(str_repeat('a', 26), '/spam', true))->assertNotFound();
});
