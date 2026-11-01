<?php

use App\Events\NotificationSent;
use App\Events\TicketUpdated;
use App\Models\Notification;
use App\Models\NotificationType;
use App\Models\SystemUser;
use App\Enums\TicketNotificationKindEnum;
use App\Support\Tickets\TicketChannelAuthorizer;
use Database\Seeders\TicketNotificationTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;

require_once __DIR__ . '/../../Support/TicketNotificationTestHelpers.php';

uses(RefreshDatabase::class);

/*
 * Phase 6 — the Reverb live update, who may listen to it, how the bell shows a
 * ticket notification, and the notification types themselves.
 */

beforeEach(function () {
    Mail::fake();
    Event::fake([TicketUpdated::class, NotificationSent::class]);
});

function tkLive(): \Illuminate\Support\Collection
{
    return Event::dispatched(TicketUpdated::class)->map(fn ($args) => $args[0]);
}

/** The ticket notification types. Filtered in PHP: LIKE's escape rules differ between SQLite and MySQL. */
function tkTicketTypes(): \Illuminate\Support\Collection
{
    return NotificationType::all()->filter(fn ($t) => str_starts_with($t->trigger_event, 'ticket_'))->values();
}

function tkChannelNames(TicketUpdated $event): array
{
    return array_map(fn ($c) => $c->name, $event->broadcastOn());
}

// ═════════════════════════════════════════════════════════════════════════════
// The live update
// ═════════════════════════════════════════════════════════════════════════════

test('every kind of change announces itself with the right action', function () {
    $requester = tkNotifRequester();
    $staff     = tkStaff(['View', 'Claim', 'Reply', 'Resolve']);

    $ticket = tkOpenTicket($requester);
    tkService()->claim($ticket, $staff);
    tkService()->release($ticket, $staff);
    tkService()->claim($ticket, $staff);
    tkService()->replyAsStaff($ticket, $staff, 'Reply.');
    tkService()->replyAsStaff($ticket, $staff, 'Question?', awaitRequester: true);
    tkService()->replyAsRequester($ticket, $requester, 'Answer.');
    tkService()->resolve($ticket, $staff, 'Done.');
    tkService()->confirm($ticket, $requester);

    expect(tkLive()->pluck('action')->all())->toBe([
        'created', 'claimed', 'released', 'claimed', 'staff_reply', 'question', 'requester_reply', 'resolved', 'closed',
    ]);
});

test('the payload names the ticket and its state and carries no message text', function () {
    $ticket = tkOpenTicket(tkNotifRequester());
    tkService()->replyAsStaff($ticket, tkStaff(), 'Your OR number 98765 was keyed in wrongly.');

    $event = tkLive()->last();
    $json  = json_encode($event->broadcastWith());

    expect($event->broadcastWith())->toMatchArray([
        'public_id' => $ticket->public_id, 'ticket_number' => $ticket->ticket_number,
        'status' => 'in_progress', 'claimed' => true, 'action' => 'staff_reply',
    ])->and($json)->not->toContain('98765')->not->toContain('Your OR');
    expect($event->broadcastAs())->toBe('TicketUpdated')->and($event->broadcastQueue())->toBe('broadcasts');
});

test('a signed-in requester\'s ticket goes to the staff channel and its own channel', function () {
    $ticket = tkOpenTicket(tkNotifRequester());

    expect(tkChannelNames(tkLive()->last()))->toBe(['private-tickets.staff', "private-ticket.{$ticket->public_id}"]);
});

test('a public ticket goes to the staff channel only, since a public submitter has no socket', function () {
    tkPubSetup();
    [$ticket] = tkPubTicket('juan@example.com', 'CAT-01');
    Event::fake([TicketUpdated::class, NotificationSent::class]);

    tkService()->replyAsStaff($ticket, tkStaff(), 'Reply.');

    expect(tkChannelNames(tkLive()->last()))->toBe(['private-tickets.staff']);
});

test('live updates can be switched off', function () {
    config(['tickets.notifications.live_updates_enabled' => false]);
    $ticket = tkOpenTicket(tkNotifRequester());
    tkService()->replyAsStaff($ticket, tkStaff(), 'Reply.');

    expect(tkLive())->toBeEmpty();
    // Switching them off must not switch the notifications off.
    expect(Notification::count())->toBe(1);
});

test('a change that rolls back announces nothing', function () {
    $ticket = tkOpenTicket(tkNotifRequester());
    $staff  = tkStaff();
    Event::fake([TicketUpdated::class, NotificationSent::class]); // forget the creation announcement

    try {
        DB::transaction(function () use ($ticket, $staff) {
            tkService()->replyAsStaff($ticket, $staff, 'Rolled back.');

            throw new RuntimeException('later failure');
        });
    } catch (RuntimeException) {
        // expected
    }

    Event::assertNotDispatched(TicketUpdated::class);
    expect(Notification::count())->toBe(0);
});

test('a broadcaster that fails never fails the ticket action', function () {
    Event::fake([NotificationSent::class]); // leave TicketUpdated real...
    Event::listen(TicketUpdated::class, function () {
        throw new RuntimeException('Reverb is down');
    });
    Log::spy();

    $ticket = tkOpenTicket(tkNotifRequester());

    expect($ticket->exists)->toBeTrue();
    Log::shouldHaveReceived('error')->atLeast()->once();
});

// ═════════════════════════════════════════════════════════════════════════════
// Who may listen
// ═════════════════════════════════════════════════════════════════════════════

test('staff who can see the queue may join the staff channel; others may not', function () {
    expect(TicketChannelAuthorizer::staffQueue(tkStaff(['View'])))->toBeTrue()
        ->and(TicketChannelAuthorizer::staffQueue(tkStaff(['Claim', 'Reply'])))->toBeFalse()
        ->and(TicketChannelAuthorizer::staffQueue(tkStaff([])))->toBeFalse()
        ->and(TicketChannelAuthorizer::staffQueue(tkNotifRequester()))->toBeFalse();
});

test('a ticket channel admits its owner and staff who can see the queue, and nobody else', function () {
    $owner  = tkNotifRequester();
    $ticket = tkOpenTicket($owner);
    $id     = $ticket->public_id;

    expect(TicketChannelAuthorizer::ticket($owner, $id))->toBeTrue()
        ->and(TicketChannelAuthorizer::ticket(tkStaff(['View']), $id))->toBeTrue()
        ->and(TicketChannelAuthorizer::ticket(tkNotifRequester(), $id))->toBeFalse()
        ->and(TicketChannelAuthorizer::ticket(tkStaff(['Claim', 'Reply']), $id))->toBeFalse()
        ->and(TicketChannelAuthorizer::ticket($owner, 'does-not-exist'))->toBeFalse();
});

test('a ticket channel never admits someone to a public ticket as its "owner"', function () {
    tkPubSetup();
    [$ticket] = tkPubTicket();

    expect(TicketChannelAuthorizer::ticket(tkNotifRequester(), $ticket->public_id))->toBeFalse();
});

// ═════════════════════════════════════════════════════════════════════════════
// The bell
// ═════════════════════════════════════════════════════════════════════════════

test('the notification list tells the bell which ticket to open', function () {
    $requester = tkNotifRequester();
    $ticket    = tkOpenTicket($requester);
    tkService()->replyAsStaff($ticket, tkStaff(), 'Reply.');
    Sanctum::actingAs($requester);

    $row = $this->getJson('/api/v1/notifications')->assertOk()->json('data.0');

    expect($row['type'])->toBe('ticket_staff_reply')
        ->and($row['ticket_public_id'])->toBe($ticket->public_id)
        ->and($row['ticket_number'])->toBe($ticket->ticket_number)
        ->and($row['request_public_id'])->toBeNull()
        ->and($row['title'])->toBe('New Reply on Your Ticket');
});

test('other notifications keep their shape: the new ticket fields are null', function () {
    $user = tkNotifRequester();
    $type = NotificationType::first();
    $n = Notification::create([
        'notification_type_id' => $type->notification_type_id, 'notifiable_type' => SystemUser::class,
        'notifiable_id' => $user->user_id, 'data' => ['message' => 'Hello'],
    ]);
    Sanctum::actingAs($user);

    $this->getJson("/api/v1/notifications/{$n->id}")->assertOk()
        ->assertJsonPath('data.ticket_public_id', null)->assertJsonPath('data.ticket_number', null);
});

// ═════════════════════════════════════════════════════════════════════════════
// The notification types
// ═════════════════════════════════════════════════════════════════════════════

test('every notification kind has an active type with its own wording', function () {
    foreach (TicketNotificationKindEnum::cases() as $kind) {
        $type = NotificationType::where('trigger_event', $kind->triggerEvent())->first();

        expect($type)->not->toBeNull($kind->triggerEvent())
            ->and($type->is_active)->toBeTrue()
            ->and($type->message_template)->toContain(':ticket_number');
    }

    expect(tkTicketTypes())->toHaveCount(count(TicketNotificationKindEnum::cases()));
});

test('requester kinds are for students and staff kinds for admins, and the lock-screen ones push', function () {
    foreach (TicketNotificationKindEnum::cases() as $kind) {
        $type = NotificationType::where('trigger_event', $kind->triggerEvent())->firstOrFail();

        expect($type->audience->value)->toBe($kind->isForRequester() ? 'student_alumni' : 'admin');
    }

    $pushing = tkTicketTypes()->filter(fn ($t) => $t->send_push)->pluck('trigger_event')->sort()->values()->all();

    expect($pushing)->toBe(['ticket_question', 'ticket_resolution_reminder', 'ticket_resolved', 'ticket_staff_reply']);
});

test('seeding again changes nothing and never overwrites an admin\'s edit', function () {
    NotificationType::where('trigger_event', 'ticket_resolved')->update(['title' => 'Edited by an admin']);
    $before = NotificationType::count();

    (new TicketNotificationTypeSeeder())->run();
    (new TicketNotificationTypeSeeder())->run();

    expect(NotificationType::count())->toBe($before)
        ->and(NotificationType::where('trigger_event', 'ticket_resolved')->value('title'))->toBe('Edited by an admin');
});

test('the ticket types take ids above the hand-numbered ones, so they can never collide with them', function () {
    $ids = tkTicketTypes()->pluck('notification_type_id');

    expect($ids->min())->toBeGreaterThan(100)->and($ids->unique())->toHaveCount($ids->count());
});

test('every placeholder in a template is one the sender fills in', function () {
    $known = [':ticket_number', ':close_date', ':deadline', ':timeout_hours'];

    foreach (TicketNotificationTypeSeeder::types() as $type) {
        preg_match_all('/:[a-z_]+/', $type['message_template'], $found);

        expect(array_diff($found[0], $known))->toBe([], $type['trigger_event']);
    }
});
