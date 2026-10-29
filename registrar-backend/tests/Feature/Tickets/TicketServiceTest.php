<?php

use App\Enums\TicketPriorityEnum;
use App\Enums\TicketSenderTypeEnum;
use App\Enums\TicketStatusEnum;
use App\Exceptions\Tickets\TicketActionNotAllowedException;
use App\Exceptions\Tickets\TicketClaimException;
use App\Exceptions\Tickets\TicketReturnLimitException;
use App\Exceptions\Tickets\TicketTransitionException;
use App\Models\SystemUser;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Services\Tickets\TicketEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/../../Support/TicketTestHelpers.php';

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

// ═════════════════════════════════════════════════════════════════════════════
// Create
// ═════════════════════════════════════════════════════════════════════════════

test('a signed-in requester creates a ticket with a number, a first message and a created event', function () {
    Carbon::setTestNow('2026-10-28 02:00:00');
    $user   = SystemUser::factory()->create();
    $ticket = tkOpenTicket($user);

    expect($ticket->ticket_number)->toBe('TKT-2026-00001')
        ->and($ticket->status)->toBe(TicketStatusEnum::Open)
        ->and($ticket->requester_user_id)->toBe($user->user_id)
        ->and($ticket->requester_ip)->toBe('203.0.113.9')
        ->and($ticket->messages)->toHaveCount(1)
        ->and($ticket->messages->first()->body)->toBe('My official receipt was rejected at the counter.')
        ->and($ticket->messages->first()->sender_type)->toBe(TicketSenderTypeEnum::Requester)
        ->and(tkEventTypes($ticket))->toBe(['created'])
        ->and($ticket->events->first()->to_status)->toBe(TicketStatusEnum::Open)
        ->and($ticket->events->first()->metadata['channel'])->toBe('authenticated');
});

test('priority comes from the category and is a copy, not a live reference', function () {
    $high = tkOpenTicket(null, 'CAT-01');
    $low  = tkOpenTicket(null, 'CAT-04');

    expect($high->priority)->toBe(TicketPriorityEnum::High)
        ->and($low->priority)->toBe(TicketPriorityEnum::Low);

    tkCategory('CAT-01')->update(['priority' => 'low']);

    expect($high->fresh()->priority)->toBe(TicketPriorityEnum::High);
});

test('a public submitter is stored with a lowercased email and no account', function () {
    $ticket = tkService()->createForPublic(
        '  Juan Dela Cruz ', '  Juan.DelaCruz@Example.COM ', tkCategory(), 'Cannot log in', 'I cannot log in to the portal at all.', '198.51.100.4',
    );

    expect($ticket->requester_user_id)->toBeNull()
        ->and($ticket->requester_name)->toBe('Juan Dela Cruz')
        ->and($ticket->requester_email)->toBe('juan.delacruz@example.com')
        ->and($ticket->messages->first()->sender_user_id)->toBeNull()
        ->and($ticket->events->first()->actor_user_id)->toBeNull()
        ->and($ticket->events->first()->metadata['channel'])->toBe('public');
});

test('ticket numbers are sequential and unique across tickets', function () {
    Carbon::setTestNow('2026-10-28 02:00:00');

    $numbers = collect(range(1, 5))->map(fn () => tkOpenTicket()->ticket_number)->all();

    expect($numbers)->toBe(['TKT-2026-00001', 'TKT-2026-00002', 'TKT-2026-00003', 'TKT-2026-00004', 'TKT-2026-00005']);
});

test('an inactive category, an empty subject and an empty description are refused', function () {
    $user = SystemUser::factory()->create();
    tkCategory('CAT-05')->update(['is_active' => false]);

    expect(fn () => tkService()->createForUser($user, tkCategory('CAT-05'), 'Subject', 'A long enough description here.'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => tkService()->createForUser($user, tkCategory(), '   ', 'A long enough description here.'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => tkService()->createForUser($user, tkCategory(), 'Subject', '   '))
        ->toThrow(InvalidArgumentException::class);
});

test('a failed create leaves nothing behind and does not burn a ticket number', function () {
    Carbon::setTestNow('2026-10-28 02:00:00');
    $user = SystemUser::factory()->create();

    // Force the message insert to fail after the ticket row and number exist.
    TicketEvent::creating(fn () => throw new RuntimeException('event write failed'));

    expect(fn () => tkService()->createForUser($user, tkCategory(), 'Subject', 'A long enough description here.'))
        ->toThrow(RuntimeException::class);

    TicketEvent::flushEventListeners();
    TicketEvent::boot(); // restore the model's own append-only guards

    expect(Ticket::count())->toBe(0)
        ->and(DB::table('ticket_messages')->count())->toBe(0);

    expect(tkOpenTicket($user)->ticket_number)->toBe('TKT-2026-00001');
});

// ═════════════════════════════════════════════════════════════════════════════
// Claim / release
// ═════════════════════════════════════════════════════════════════════════════

test('claiming an Open ticket starts work on it and records the claim', function () {
    $staff  = tkStaff();
    $ticket = tkService()->claim(tkOpenTicket(), $staff);

    expect($ticket->status)->toBe(TicketStatusEnum::InProgress)
        ->and($ticket->claimed_by_user_id)->toBe($staff->user_id)
        ->and($ticket->claimed_at)->not->toBeNull();

    $claimed = $ticket->events()->where('event_type', 'claimed')->first();
    expect($claimed->actor_user_id)->toBe($staff->user_id)
        ->and($claimed->from_status)->toBe(TicketStatusEnum::Open)
        ->and($claimed->to_status)->toBe(TicketStatusEnum::InProgress);
});

test('a second claim is refused and names the holder, whether it is another staff member or the same one', function () {
    $first  = tkStaff();
    $second = tkStaff();
    $ticket = tkService()->claim(tkOpenTicket(), $first);

    foreach ([$second, $first] as $claimant) {
        try {
            tkService()->claim($ticket, $claimant);
            $this->fail('expected the claim to be refused');
        } catch (TicketClaimException $e) {
            expect($e->reason)->toBe(TicketClaimException::ALREADY_CLAIMED)
                ->and($e->claimerId)->toBe($first->user_id);
        }
    }

    expect($ticket->fresh()->claimed_by_user_id)->toBe($first->user_id)
        ->and(tkEventTypes($ticket))->toBe(['created', 'claimed']); // refused claims leave no event
});

test('a Pending Client ticket can be claimed and keeps its status', function () {
    $staff  = tkStaff();
    $ticket = tkService()->claim(tkOpenTicket(), $staff);
    tkService()->replyAsStaff($ticket, $staff, 'Please send the receipt number.', awaitRequester: true);
    tkService()->release($ticket, $staff);

    $other = tkStaff();
    $ticket = tkService()->claim($ticket, $other);

    expect($ticket->status)->toBe(TicketStatusEnum::PendingClient)
        ->and($ticket->claimed_by_user_id)->toBe($other->user_id);
});

test('Resolved and Closed tickets cannot be claimed', function () {
    $staff = tkStaff();

    $resolved = tkService()->resolve(tkOpenTicket(), $staff, 'Fixed.');
    expect(fn () => tkService()->claim($resolved, $staff))->toThrow(TicketActionNotAllowedException::class);

    $closed = tkService()->confirm($resolved, null);
    expect(fn () => tkService()->claim($closed, $staff))->toThrow(TicketActionNotAllowedException::class);
});

test('release returns the ticket to the queue, leaves its status, and is recorded', function () {
    $staff  = tkStaff();
    $ticket = tkService()->claim(tkOpenTicket(), $staff);
    $ticket = tkService()->release($ticket, $staff);

    expect($ticket->claimed_by_user_id)->toBeNull()
        ->and($ticket->claimed_at)->toBeNull()
        ->and($ticket->status)->toBe(TicketStatusEnum::InProgress)
        ->and(tkEventTypes($ticket))->toBe(['created', 'claimed', 'released']);

    $released = $ticket->events()->where('event_type', 'released')->first();
    expect($released->metadata)->toMatchArray(['previous_claimer' => $staff->user_id, 'released_by_other' => false]);

    // ...and it can be claimed again by someone else.
    $again = tkService()->claim($ticket, tkStaff());
    expect($again->claimed_by_user_id)->not->toBe($staff->user_id);
});

test('release: a stranger is refused, a manager may free it, an unclaimed ticket cannot be released', function () {
    $claimer  = tkStaff(['View', 'Claim']);
    $stranger = tkStaff(['View', 'Claim']);
    $manager  = tkStaff(['View', 'Manage']);

    $ticket = tkService()->claim(tkOpenTicket(), $claimer);

    try {
        tkService()->release($ticket, $stranger);
        $this->fail('expected a refusal');
    } catch (TicketClaimException $e) {
        expect($e->reason)->toBe(TicketClaimException::NOT_PERMITTED)->and($e->claimerId)->toBe($claimer->user_id);
    }
    expect($ticket->fresh()->claimed_by_user_id)->toBe($claimer->user_id);

    $freed = tkService()->release($ticket, $manager);
    expect($freed->claimed_by_user_id)->toBeNull();
    expect($freed->events()->where('event_type', 'released')->first()->metadata['released_by_other'])->toBeTrue();

    try {
        tkService()->release($freed, $manager);
        $this->fail('expected a refusal');
    } catch (TicketClaimException $e) {
        expect($e->reason)->toBe(TicketClaimException::NOT_CLAIMED);
    }
});

// ═════════════════════════════════════════════════════════════════════════════
// Staff replies
// ═════════════════════════════════════════════════════════════════════════════

test('a staff reply to an unclaimed ticket claims it first, and stamps the first response once', function () {
    Carbon::setTestNow('2026-10-28 02:00:00');
    $ticket = tkOpenTicket();
    $staff  = tkStaff();

    Carbon::setTestNow('2026-10-28 03:00:00');
    tkService()->replyAsStaff($ticket, $staff, 'Looking into it.');
    $ticket->refresh();

    expect($ticket->claimed_by_user_id)->toBe($staff->user_id)
        ->and($ticket->status)->toBe(TicketStatusEnum::InProgress)
        ->and($ticket->first_responded_at->toDateTimeString())->toBe('2026-10-28 03:00:00')
        ->and(tkEventTypes($ticket))->toBe(['created', 'claimed', 'replied'])
        ->and($ticket->messages)->toHaveCount(2);

    Carbon::setTestNow('2026-10-28 05:00:00');
    tkService()->replyAsStaff($ticket, $staff, 'Any update?');

    expect($ticket->fresh()->first_responded_at->toDateTimeString())->toBe('2026-10-28 03:00:00'); // unchanged
});

test('replying to a ticket someone else holds is allowed and does not steal the claim', function () {
    $holder = tkStaff();
    $cover  = tkStaff();
    $ticket = tkService()->claim(tkOpenTicket(), $holder);

    tkService()->replyAsStaff($ticket, $cover, 'Covering for a colleague.');

    expect($ticket->fresh()->claimed_by_user_id)->toBe($holder->user_id)
        ->and(tkEventTypes($ticket))->toBe(['created', 'claimed', 'replied']);
});

test('a staff reply that asks the requester a question moves the ticket to Pending Client', function () {
    $staff  = tkStaff();
    $ticket = tkOpenTicket();

    tkService()->replyAsStaff($ticket, $staff, 'What is the OR number?', awaitRequester: true);
    $ticket->refresh();

    expect($ticket->status)->toBe(TicketStatusEnum::PendingClient);

    $replied = $ticket->events()->where('event_type', 'replied')->first();
    expect($replied->from_status)->toBe(TicketStatusEnum::InProgress)
        ->and($replied->to_status)->toBe(TicketStatusEnum::PendingClient);

    // A further staff message while waiting changes nothing about status.
    tkService()->replyAsStaff($ticket, $staff, 'Take your time.', awaitRequester: true);
    expect($ticket->fresh()->status)->toBe(TicketStatusEnum::PendingClient);
});

test('staff cannot reply to a Resolved or Closed ticket', function () {
    $staff    = tkStaff();
    $resolved = tkService()->resolve(tkOpenTicket(), $staff, 'Done.');

    expect(fn () => tkService()->replyAsStaff($resolved, $staff, 'One more thing'))
        ->toThrow(TicketActionNotAllowedException::class);

    $closed = tkService()->confirm($resolved, null);
    expect(fn () => tkService()->replyAsStaff($closed, $staff, 'Too late'))
        ->toThrow(TicketActionNotAllowedException::class);
});

// ═════════════════════════════════════════════════════════════════════════════
// Requester replies
// ═════════════════════════════════════════════════════════════════════════════

test('a requester reply on an Open or In Progress ticket just adds the message', function () {
    $user   = SystemUser::factory()->create();
    $ticket = tkOpenTicket($user);

    tkService()->replyAsRequester($ticket, $user, 'Any news?');
    expect($ticket->fresh()->status)->toBe(TicketStatusEnum::Open);

    tkService()->claim($ticket, tkStaff());
    tkService()->replyAsRequester($ticket, $user, 'Still waiting.');

    expect($ticket->fresh()->status)->toBe(TicketStatusEnum::InProgress)
        ->and($ticket->messages()->count())->toBe(3);
});

test('answering a staff question moves Pending Client back to In Progress', function () {
    $user   = SystemUser::factory()->create();
    $staff  = tkStaff();
    $ticket = tkOpenTicket($user);

    tkService()->replyAsStaff($ticket, $staff, 'What is the OR number?', awaitRequester: true);
    tkService()->replyAsRequester($ticket, $user, 'It is 1234567.');
    $ticket->refresh();

    expect($ticket->status)->toBe(TicketStatusEnum::InProgress)
        ->and($ticket->claimed_by_user_id)->toBe($staff->user_id); // still theirs

    $reply = $ticket->events()->where('event_type', 'replied')->reorder('seq', 'desc')->first();
    expect($reply->actor_type)->toBe(TicketSenderTypeEnum::Requester)
        ->and($reply->from_status)->toBe(TicketStatusEnum::PendingClient)
        ->and($reply->to_status)->toBe(TicketStatusEnum::InProgress);
});

test('a public requester can reply with no account', function () {
    $ticket = tkService()->createForPublic('Ana', 'ana@example.com', tkCategory(), 'Subject', 'A long enough description here.');
    tkService()->replyAsStaff($ticket, tkStaff(), 'Which receipt?', awaitRequester: true);

    $message = tkService()->replyAsRequester($ticket, null, 'The one dated 5 October.');

    expect($message->sender_user_id)->toBeNull()
        ->and($message->sender_type)->toBe(TicketSenderTypeEnum::Requester)
        ->and($ticket->fresh()->status)->toBe(TicketStatusEnum::InProgress);
});

test('a requester cannot reply to a Closed ticket', function () {
    $user   = SystemUser::factory()->create();
    $ticket = tkService()->confirm(tkService()->resolve(tkOpenTicket($user), tkStaff(), 'Done.'), $user);

    expect(fn () => tkService()->replyAsRequester($ticket, $user, 'Wait, one more thing'))
        ->toThrow(TicketActionNotAllowedException::class)
        ->and($ticket->messages()->count())->toBe(1);
});

// ═════════════════════════════════════════════════════════════════════════════
// Resolve
// ═════════════════════════════════════════════════════════════════════════════

test('resolving records the note, opens the 3-calendar-day auto-close window and releases the claim', function () {
    Carbon::setTestNow('2026-10-28 02:00:00');
    $staff  = tkStaff();
    $ticket = tkService()->claim(tkOpenTicket(), $staff);

    Carbon::setTestNow('2026-10-29 04:30:00');
    $ticket = tkService()->resolve($ticket, $staff, '  Receipt validated; please retry.  ');

    expect($ticket->status)->toBe(TicketStatusEnum::Resolved)
        ->and($ticket->resolution_note)->toBe('Receipt validated; please retry.')
        ->and($ticket->resolved_by_user_id)->toBe($staff->user_id)
        ->and($ticket->resolved_at->toDateTimeString())->toBe('2026-10-29 04:30:00')
        ->and($ticket->auto_close_at->toDateTimeString())->toBe('2026-11-01 04:30:00') // +3 calendar days
        ->and($ticket->claimed_by_user_id)->toBeNull()
        ->and($ticket->first_responded_at->toDateTimeString())->toBe('2026-10-29 04:30:00');

    $event = $ticket->events()->where('event_type', 'resolved')->first();
    expect($event->from_status)->toBe(TicketStatusEnum::InProgress)
        ->and($event->to_status)->toBe(TicketStatusEnum::Resolved)
        ->and($event->metadata['resolution_note'])->toBe('Receipt validated; please retry.')
        ->and($event->metadata['previous_claimer'])->toBe($staff->user_id);
});

test('staff can resolve straight from Open and from Pending Client', function () {
    $staff = tkStaff();

    expect(tkService()->resolve(tkOpenTicket(), $staff, 'Answered in the FAQ.')->status)->toBe(TicketStatusEnum::Resolved);

    $waiting = tkOpenTicket();
    tkService()->replyAsStaff($waiting, $staff, 'Which receipt?', awaitRequester: true);
    expect(tkService()->resolve($waiting, $staff, 'Found it ourselves.')->status)->toBe(TicketStatusEnum::Resolved);
});

test('a resolution note is required', function () {
    $ticket = tkOpenTicket();

    expect(fn () => tkService()->resolve($ticket, tkStaff(), '   '))->toThrow(InvalidArgumentException::class)
        ->and($ticket->fresh()->status)->toBe(TicketStatusEnum::Open);
});

test('a Resolved or Closed ticket cannot be resolved again', function () {
    $staff    = tkStaff();
    $resolved = tkService()->resolve(tkOpenTicket(), $staff, 'Done.');

    expect(fn () => tkService()->resolve($resolved, $staff, 'Again'))->toThrow(TicketTransitionException::class);

    $closed = tkService()->confirm($resolved, null);
    expect(fn () => tkService()->resolve($closed, $staff, 'Again'))->toThrow(TicketTransitionException::class);
});

// ═════════════════════════════════════════════════════════════════════════════
// Confirm
// ═════════════════════════════════════════════════════════════════════════════

test('the requester confirming a resolution closes the ticket for good', function () {
    Carbon::setTestNow('2026-10-28 02:00:00');
    $user   = SystemUser::factory()->create();
    $ticket = tkService()->resolve(tkOpenTicket($user), tkStaff(), 'Done.');

    Carbon::setTestNow('2026-10-28 09:00:00');
    $ticket = tkService()->confirm($ticket, $user);

    expect($ticket->status)->toBe(TicketStatusEnum::Closed)
        ->and($ticket->close_reason)->toBe(Ticket::CLOSE_REASON_CONFIRMED)
        ->and($ticket->closed_at->toDateTimeString())->toBe('2026-10-28 09:00:00')
        ->and($ticket->auto_close_at)->toBeNull()
        ->and(tkEventTypes($ticket))->toBe(['created', 'resolved', 'resolution_confirmed']);

    // Closed is final: nothing leaves it.
    expect(fn () => tkService()->confirm($ticket, $user))->toThrow(TicketTransitionException::class)
        ->and(fn () => tkService()->returnToProgress($ticket, $user))->toThrow(TicketActionNotAllowedException::class);
});

test('only a Resolved ticket can be confirmed', function () {
    foreach ([TicketStatusEnum::Open, TicketStatusEnum::InProgress, TicketStatusEnum::PendingClient] as $status) {
        $ticket = Ticket::factory()->status($status)->create();

        expect(fn () => tkService()->confirm($ticket, null))->toThrow(TicketTransitionException::class);
    }
});

// ═════════════════════════════════════════════════════════════════════════════
// Return ("This isn't resolved")
// ═════════════════════════════════════════════════════════════════════════════

test('"This isn\'t resolved" sends a Resolved ticket back to In Progress, unclaimed, keeping the history', function () {
    $user   = SystemUser::factory()->create();
    $staff  = tkStaff();
    $ticket = tkService()->resolve(tkService()->claim(tkOpenTicket($user), $staff), $staff, 'Try again now.');

    $ticket = tkService()->returnToProgress($ticket, $user, 'Still getting the same error.');

    expect($ticket->status)->toBe(TicketStatusEnum::InProgress)
        ->and($ticket->requester_returns)->toBe(1)
        ->and($ticket->claimed_by_user_id)->toBeNull()
        ->and($ticket->resolution_note)->toBeNull()
        ->and($ticket->resolved_at)->toBeNull()
        ->and($ticket->resolved_by_user_id)->toBeNull()
        ->and($ticket->auto_close_at)->toBeNull()
        ->and($ticket->messages()->reorder('id', 'desc')->first()->body)->toBe('Still getting the same error.');

    $returned = $ticket->events()->where('event_type', 'returned')->first();
    expect($returned->from_status)->toBe(TicketStatusEnum::Resolved)
        ->and($returned->to_status)->toBe(TicketStatusEnum::InProgress)
        ->and($returned->metadata)->toMatchArray(['return_number' => 1, 'via' => 'button', 'previous_resolution_note' => 'Try again now.']);

    // The original resolution is still in the log.
    expect($ticket->events()->where('event_type', 'resolved')->first()->metadata['resolution_note'])->toBe('Try again now.');
});

test('a reply during the Resolved window returns the ticket and stores the message', function () {
    $user   = SystemUser::factory()->create();
    $ticket = tkService()->resolve(tkOpenTicket($user), tkStaff(), 'Done.');

    tkService()->replyAsRequester($ticket, $user, 'No, it is still broken.');
    $ticket->refresh();

    expect($ticket->status)->toBe(TicketStatusEnum::InProgress)
        ->and($ticket->requester_returns)->toBe(1)
        ->and(tkEventTypes($ticket))->toBe(['created', 'resolved', 'returned', 'replied'])
        ->and($ticket->messages()->reorder('id', 'desc')->first()->body)->toBe('No, it is still broken.');
});

test('a ticket can be sent back twice; the third time is refused and nothing changes', function () {
    $user  = SystemUser::factory()->create();
    $staff = tkStaff();
    $ticket = tkOpenTicket($user);

    foreach ([1, 2] as $round) {
        $ticket = tkService()->resolve($ticket, $staff, "Resolution {$round}");
        $ticket = tkService()->returnToProgress($ticket, $user);
        expect($ticket->requester_returns)->toBe($round);
    }

    $ticket   = tkService()->resolve($ticket, $staff, 'Resolution 3');
    $messages = $ticket->messages()->count();
    $events   = $ticket->events()->count();

    try {
        tkService()->returnToProgress($ticket, $user);
        $this->fail('expected the limit to stop the return');
    } catch (TicketReturnLimitException $e) {
        expect($e->limit)->toBe(2);
    }

    // A reply is refused too, and is not stored (it would sit unseen).
    expect(fn () => tkService()->replyAsRequester($ticket, $user, 'Still broken'))
        ->toThrow(TicketReturnLimitException::class);

    $ticket->refresh();
    expect($ticket->status)->toBe(TicketStatusEnum::Resolved)
        ->and($ticket->requester_returns)->toBe(2)
        ->and($ticket->messages()->count())->toBe($messages)
        ->and($ticket->events()->count())->toBe($events);

    // The requester can still close it.
    expect(tkService()->confirm($ticket, $user)->status)->toBe(TicketStatusEnum::Closed);
});

test('the return limit is read from config', function () {
    config(['tickets.max_requester_returns' => 0]);
    $user   = SystemUser::factory()->create();
    $ticket = tkService()->resolve(tkOpenTicket($user), tkStaff(), 'Done.');

    expect(fn () => tkService()->returnToProgress($ticket, $user))->toThrow(TicketReturnLimitException::class);
});

test('only a Resolved ticket can be sent back', function () {
    foreach ([TicketStatusEnum::Open, TicketStatusEnum::InProgress, TicketStatusEnum::PendingClient, TicketStatusEnum::Closed] as $status) {
        $ticket = Ticket::factory()->status($status)->create();

        expect(fn () => tkService()->returnToProgress($ticket, null))->toThrow(TicketActionNotAllowedException::class);
    }
});

test('a returned ticket is claimable again and can complete a second lifecycle', function () {
    $user  = SystemUser::factory()->create();
    $first = tkStaff();
    $ticket = tkService()->resolve(tkService()->claim(tkOpenTicket($user), $first), $first, 'First fix.');
    $ticket = tkService()->returnToProgress($ticket, $user);

    $second = tkStaff();
    $ticket = tkService()->claim($ticket, $second);
    $ticket = tkService()->resolve($ticket, $second, 'Second, real fix.');
    $ticket = tkService()->confirm($ticket, $user);

    expect($ticket->status)->toBe(TicketStatusEnum::Closed)
        ->and(tkEventTypes($ticket))->toBe([
            'created', 'claimed', 'resolved', 'returned', 'claimed', 'resolved', 'resolution_confirmed',
        ]);
});

// ═════════════════════════════════════════════════════════════════════════════
// Event log
// ═════════════════════════════════════════════════════════════════════════════

test('a full lifecycle leaves a gap-free, verifiable hash chain', function () {
    $user  = SystemUser::factory()->create();
    $staff = tkStaff();

    $ticket = tkOpenTicket($user);
    tkService()->replyAsStaff($ticket, $staff, 'Which receipt?', awaitRequester: true);
    tkService()->replyAsRequester($ticket, $user, 'The 5 October one.');
    tkService()->release($ticket, $staff);
    tkService()->claim($ticket, $staff);
    tkService()->resolve($ticket, $staff, 'Done.');
    tkService()->returnToProgress($ticket, $user, 'Not fixed.');
    tkService()->resolve($ticket, $staff, 'Fixed properly.');
    tkService()->confirm($ticket, $user);

    $events = $ticket->events()->get();

    expect($events->pluck('seq')->all())->toBe(range(1, $events->count()))
        ->and($events->first()->prev_hash)->toBe(TicketEvent::GENESIS_HASH);

    $verdict = app(TicketEventRecorder::class)->verifyChain($ticket);
    expect($verdict)->toBe(['ok' => true, 'events' => $events->count(), 'broken_at_seq' => null, 'reason' => null]);
});

test('tampering with an event, or removing one, is detected', function () {
    $staff  = tkStaff();
    $ticket = tkService()->resolve(tkService()->claim(tkOpenTicket(), $staff), $staff, 'Done.');
    $recorder = app(TicketEventRecorder::class);

    expect($recorder->verifyChain($ticket)['ok'])->toBeTrue();

    // Edited content (raw SQL, bypassing the model's append-only guard).
    DB::table('ticket_events')->where('ticket_id', $ticket->id)->where('seq', 2)->update(['actor_user_id' => 999]);
    $verdict = $recorder->verifyChain($ticket);
    expect($verdict['ok'])->toBeFalse()->and($verdict['broken_at_seq'])->toBe(2)->and($verdict['reason'])->toContain('contents');

    // Deleted event: a gap in the sequence.
    DB::table('ticket_events')->where('ticket_id', $ticket->id)->where('seq', 2)->delete();
    $verdict = $recorder->verifyChain($ticket);
    expect($verdict['ok'])->toBeFalse()->and($verdict['broken_at_seq'])->toBe(2)->and($verdict['reason'])->toContain('gap');
});

test('events can only be recorded inside a transaction, and only with a known type', function () {
    $ticket   = tkOpenTicket();
    $recorder = app(TicketEventRecorder::class);

    expect(fn () => $recorder->record($ticket, 'not_a_type', TicketSenderTypeEnum::System))
        ->toThrow(LogicException::class, 'Unknown ticket event type');

    // RefreshDatabase wraps each test in a transaction, so step out of it to
    // observe the guard for what production code would see at level 0.
    $level = DB::transactionLevel();
    for ($i = 0; $i < $level; $i++) {
        DB::rollBack();
    }
    expect(fn () => $recorder->record($ticket, TicketEvent::TYPE_CLAIMED, TicketSenderTypeEnum::System))
        ->toThrow(LogicException::class, 'inside a transaction');
    for ($i = 0; $i < $level; $i++) {
        DB::beginTransaction();
    }
});

test('oversized and empty messages are refused by the service as a backstop', function () {
    $user   = SystemUser::factory()->create();
    $ticket = tkOpenTicket($user);
    config(['tickets.content.message_max_length' => 50]);

    expect(fn () => tkService()->replyAsRequester($ticket, $user, str_repeat('a', 51)))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => tkService()->replyAsRequester($ticket, $user, "  \n "))
        ->toThrow(InvalidArgumentException::class);
});
