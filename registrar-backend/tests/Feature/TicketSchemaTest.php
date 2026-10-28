<?php

use App\Enums\TicketPriorityEnum;
use App\Enums\TicketSenderTypeEnum;
use App\Enums\TicketStatusEnum;
use App\Models\SystemUser;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketEvent;
use App\Models\TicketMessage;
use Database\Seeders\TicketCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

const TK_MIGRATIONS = [
    'database/migrations/2026_10_28_000001_create_ticket_categories_table.php',
    'database/migrations/2026_10_28_000002_create_tickets_table.php',
    'database/migrations/2026_10_28_000003_create_ticket_messages_table.php',
    'database/migrations/2026_10_28_000004_create_ticket_events_table.php',
    'database/migrations/2026_10_28_000005_create_ticket_number_sequences_table.php',
];

const TK_TABLES = ['ticket_categories', 'tickets', 'ticket_messages', 'ticket_events', 'ticket_number_sequences'];

// ── Migrations ──────────────────────────────────────────────────────────────

test('the ticket migrations roll back and re-run cleanly', function () {
    foreach (TK_TABLES as $table) {
        expect(Schema::hasTable($table))->toBeTrue("{$table} should exist after migrate");
    }

    expect(Artisan::call('migrate:rollback', ['--path' => TK_MIGRATIONS, '--force' => true]))->toBe(0);

    foreach (TK_TABLES as $table) {
        expect(Schema::hasTable($table))->toBeFalse("{$table} should be gone after rollback");
    }

    expect(Artisan::call('migrate', ['--path' => TK_MIGRATIONS, '--force' => true]))->toBe(0);

    foreach (TK_TABLES as $table) {
        expect(Schema::hasTable($table))->toBeTrue("{$table} should exist after re-migrate");
    }
});

test('tickets has the columns and unique constraints the design depends on', function () {
    expect(Schema::hasColumns('tickets', [
        'public_id', 'ticket_number', 'category_id', 'priority', 'status', 'subject',
        'requester_user_id', 'requester_name', 'requester_email', 'requester_ip',
        'claimed_by_user_id', 'claimed_at', 'claim_expires_at',
        'first_responded_at', 'last_activity_at',
        'requester_returns', 'resolution_note', 'resolved_at', 'resolved_by_user_id',
        'auto_close_at', 'reminder_sent_at', 'closed_at', 'close_reason', 'anonymized_at',
    ]))->toBeTrue();

    // Duplicate ticket numbers and public ids must be impossible at the DB level.
    $a = Ticket::factory()->create();

    expect(fn () => Ticket::factory()->create(['ticket_number' => $a->ticket_number]))
        ->toThrow(\Illuminate\Database\QueryException::class);
    expect(fn () => Ticket::factory()->create(['public_id' => $a->public_id]))
        ->toThrow(\Illuminate\Database\QueryException::class);
});

test('a ticket event position (ticket, seq) is unique', function () {
    $ticket = Ticket::factory()->create();
    $row = [
        'ticket_id' => $ticket->id, 'seq' => 1, 'event_type' => 'created', 'actor_type' => 'system',
        'created_at' => now(), 'prev_hash' => '0', 'hash' => str_repeat('a', 64),
    ];

    DB::table('ticket_events')->insert($row);

    expect(fn () => DB::table('ticket_events')->insert($row))
        ->toThrow(\Illuminate\Database\QueryException::class);
});

// ── Seeder ──────────────────────────────────────────────────────────────────

test('the seeder creates the five approved categories with the approved priorities', function () {
    $rows = TicketCategory::query()->orderBy('sort_order')->get();

    expect($rows->pluck('code')->all())->toBe(['CAT-01', 'CAT-02', 'CAT-03', 'CAT-04', 'CAT-05'])
        ->and($rows->pluck('priority')->map->value->all())->toBe(['high', 'medium', 'medium', 'low', 'low'])
        ->and($rows->pluck('name')->first())->toBe('Payment & Receipt (OR) Issues');
});

test('re-running the seeder is safe and never overwrites an admin edit', function () {
    TicketCategory::where('code', 'CAT-04')->update([
        'name' => 'Renamed by admin', 'priority' => 'high', 'is_active' => false,
    ]);

    (new TicketCategorySeeder())->run();
    (new TicketCategorySeeder())->run();

    expect(TicketCategory::count())->toBe(5);

    $edited = TicketCategory::where('code', 'CAT-04')->first();
    expect($edited->name)->toBe('Renamed by admin')
        ->and($edited->priority)->toBe(TicketPriorityEnum::High)
        ->and($edited->is_active)->toBeFalse();
});

test('inactive categories are left out of the active scope', function () {
    TicketCategory::where('code', 'CAT-05')->update(['is_active' => false]);

    expect(TicketCategory::active()->pluck('code')->all())
        ->toBe(['CAT-01', 'CAT-02', 'CAT-03', 'CAT-04']);
});

// ── Ticket model ────────────────────────────────────────────────────────────

test('a new ticket gets a lowercase 26-character ULID public id', function () {
    $ticket = Ticket::factory()->create();

    expect($ticket->public_id)->toMatch('/^[0-9a-hjkmnp-tv-z]{26}$/')
        ->and($ticket->getRouteKeyName())->toBe('public_id');
});

test('ticket enums cast both ways', function () {
    $ticket = Ticket::factory()->create(['status' => 'pending_client', 'priority' => 'high'])->fresh();

    expect($ticket->status)->toBe(TicketStatusEnum::PendingClient)
        ->and($ticket->priority)->toBe(TicketPriorityEnum::High);
});

test('lifecycle fields cannot be mass-assigned', function () {
    $category = TicketCategory::first();

    $ticket = new Ticket();
    $ticket->fill([
        'category_id' => $category->id, 'subject' => 'x',
        'status' => 'closed', 'claimed_by_user_id' => 99, 'resolution_note' => 'z',
        'requester_returns' => 7, 'ticket_number' => 'TKT-1999-00001', 'requester_ip' => '1.2.3.4',
    ]);

    expect($ticket->getAttributes())->toHaveKeys(['category_id', 'subject'])
        ->and($ticket->getAttributes())->not->toHaveKeys([
            'status', 'claimed_by_user_id', 'resolution_note', 'requester_returns', 'ticket_number', 'requester_ip',
        ]);
});

test('requester_ip is never serialised', function () {
    $ticket = Ticket::factory()->public()->create();

    expect($ticket->toArray())->not->toHaveKey('requester_ip');
});

test('relationships resolve: category, requester, thread and events', function () {
    $staff   = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN]);
    $ticket  = Ticket::factory()->claimedBy($staff)->create();
    TicketMessage::factory()->for($ticket)->create(['sender_user_id' => $ticket->requester_user_id]);
    TicketMessage::factory()->for($ticket)->fromStaff($staff)->create();

    $ticket = $ticket->fresh();

    expect($ticket->category->code)->toBe('CAT-05')
        ->and($ticket->requester)->toBeInstanceOf(SystemUser::class)
        ->and($ticket->claimedBy->user_id)->toBe($staff->user_id)
        ->and($ticket->messages)->toHaveCount(2)
        ->and($ticket->messages->first()->sender_type)->toBe(TicketSenderTypeEnum::Requester)
        ->and($ticket->events)->toHaveCount(0);
});

test('active scope excludes Resolved and Closed', function () {
    Ticket::factory()->create();
    Ticket::factory()->status(TicketStatusEnum::InProgress)->create();
    Ticket::factory()->status(TicketStatusEnum::PendingClient)->create();
    Ticket::factory()->resolved()->create();
    Ticket::factory()->closed()->create();

    expect(Ticket::active()->count())->toBe(3);
});

test('user references: tickets null out on user delete; messages and events keep a plain id with no foreign key', function () {
    // Asserted on the declared schema, not by deleting a user: the base-schema
    // migration switches SQLite foreign-key enforcement off for the whole test
    // connection, so a delete here would prove nothing about MySQL, where the
    // constraints are enforced.
    $usersFks = fn (string $table) => collect(Schema::getForeignKeys($table))
        ->filter(fn ($fk) => $fk['foreign_table'] === 'users')
        ->mapWithKeys(fn ($fk) => [implode(',', $fk['columns']) => strtolower($fk['on_delete'])]);

    // Driver-reported order is not stable, so compare as sorted maps.
    $ticketFks = $usersFks('tickets')->sortKeys()->all();

    expect($ticketFks)->toBe([
        'claimed_by_user_id'  => 'set null',
        'requester_user_id'   => 'set null',
        'resolved_by_user_id' => 'set null',
    ])
        ->and($usersFks('ticket_messages')->all())->toBe([])
        ->and($usersFks('ticket_events')->all())->toBe([]);
});

test('a message keeps its sender id after the staff row is gone', function () {
    $staff = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN]);
    $msg   = TicketMessage::factory()->fromStaff($staff)->create();

    DB::table('users')->where('user_id', $staff->user_id)->delete();

    expect($msg->fresh()->sender_user_id)->toBe($staff->user_id)
        ->and($msg->fresh()->sender)->toBeNull();
});

// ── Append-only guards ──────────────────────────────────────────────────────

test('ticket messages cannot be edited or deleted through the model', function () {
    $msg = TicketMessage::factory()->create();

    expect(fn () => $msg->update(['body' => 'edited']))->toThrow(LogicException::class)
        ->and(fn () => $msg->delete())->toThrow(LogicException::class)
        ->and($msg->fresh()->body)->not->toBe('edited');
});

test('ticket events cannot be edited or deleted through the model', function () {
    $ticket = Ticket::factory()->create();
    $event  = TicketEvent::create([
        'ticket_id' => $ticket->id, 'seq' => 1, 'event_type' => TicketEvent::TYPE_CREATED,
        'actor_type' => 'system', 'created_at' => now()->startOfSecond(),
        'prev_hash' => TicketEvent::GENESIS_HASH, 'hash' => str_repeat('b', 64),
    ]);

    expect(fn () => $event->update(['event_type' => 'closed']))->toThrow(LogicException::class)
        ->and(fn () => $event->delete())->toThrow(LogicException::class);
});

// ── Event hash chain primitives ─────────────────────────────────────────────

function tkWriteEvent(Ticket $ticket, int $seq, string $prevHash, array $overrides = []): TicketEvent
{
    $fields = $overrides + [
        'ticket_id' => $ticket->id, 'seq' => $seq, 'event_type' => TicketEvent::TYPE_STATUS_CHANGED,
        'actor_type' => TicketSenderTypeEnum::Staff, 'actor_user_id' => 7,
        'from_status' => TicketStatusEnum::Open, 'to_status' => TicketStatusEnum::InProgress,
        'metadata' => ['note' => 'x', 'a' => ['z' => 1, 'b' => 2]],
        'created_at' => now()->startOfSecond(),
    ];

    $fields['prev_hash'] = $prevHash;
    $fields['hash']      = TicketEvent::computeHash($prevHash, TicketEvent::hashPayload($fields));

    return TicketEvent::create($fields);
}

test('a stored event verifies: its hash equals the recomputed hash after a database round trip', function () {
    $ticket = Ticket::factory()->create();
    $first  = tkWriteEvent($ticket, 1, TicketEvent::GENESIS_HASH);
    $second = tkWriteEvent($ticket, 2, $first->hash, ['metadata' => null, 'actor_user_id' => null,
        'actor_type' => TicketSenderTypeEnum::System, 'from_status' => null, 'to_status' => null]);

    foreach ($ticket->events()->get() as $event) {
        expect($event->expectedHash())->toBe($event->hash);
    }

    expect($ticket->events()->get()->last()->prev_hash)->toBe($first->hash);
});

test('metadata key order does not change the hash', function () {
    $a = TicketEvent::hashPayload(['ticket_id' => 1, 'seq' => 1, 'event_type' => 'x', 'actor_type' => 'staff',
        'actor_user_id' => 1, 'from_status' => null, 'to_status' => null,
        'metadata' => ['b' => 1, 'a' => ['y' => 1, 'x' => 2]], 'created_at' => '2026-10-28 10:00:00']);
    $b = TicketEvent::hashPayload(['ticket_id' => 1, 'seq' => 1, 'event_type' => 'x', 'actor_type' => 'staff',
        'actor_user_id' => 1, 'from_status' => null, 'to_status' => null,
        'metadata' => ['a' => ['x' => 2, 'y' => 1], 'b' => 1], 'created_at' => '2026-10-28 10:00:00']);

    expect(TicketEvent::computeHash('0', $a))->toBe(TicketEvent::computeHash('0', $b));
});

test('altering a stored event is detected by recomputing its hash', function () {
    $ticket = Ticket::factory()->create();
    $event  = tkWriteEvent($ticket, 1, TicketEvent::GENESIS_HASH);

    // Tampering that bypasses the model's guard, as a raw SQL edit would.
    DB::table('ticket_events')->where('id', $event->id)->update(['to_status' => 'resolved']);

    $stored = TicketEvent::find($event->id);
    expect($stored->expectedHash())->not->toBe($stored->hash);
});
