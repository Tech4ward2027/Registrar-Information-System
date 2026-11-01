<?php

use App\Enums\TicketNotificationKindEnum;
use App\Events\NotificationSent;
use App\Jobs\SendTicketNotificationJob;
use App\Mail\TicketNotificationMail;
use App\Models\Notification;
use App\Models\SystemUser;
use App\Models\Ticket;
use App\Services\Tickets\PublicSubmission\TicketAccessTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;

require_once __DIR__ . '/../../Support/TicketNotificationTestHelpers.php';

uses(RefreshDatabase::class);

/*
 * Phase 6 — what the requester is told. Queue sync / mailer array / broadcast
 * log, so each notification job runs inline right after the change commits.
 */

beforeEach(function () {
    Mail::fake();
    Event::fake([NotificationSent::class]);
});
afterEach(fn () => Carbon::setTestNow());

// ═════════════════════════════════════════════════════════════════════════════
// The headline guarantee
// ═════════════════════════════════════════════════════════════════════════════

test('one staff reply produces exactly one email and one in-app notification', function () {
    $requester = tkNotifRequester();
    $ticket    = tkOpenTicket($requester, 'CAT-01');

    tkService()->replyAsStaff($ticket, tkStaff(), 'We are checking your receipt.');

    Mail::assertQueued(TicketNotificationMail::class, 1);
    expect(tkNotifMails()->first()->kind)->toBe('staff_reply')
        ->and(tkNotifMails()->first()->hasTo($requester->email))->toBeTrue();

    expect(tkNotifTypes($requester))->toBe(['ticket_staff_reply']);
    Event::assertDispatchedTimes(NotificationSent::class, 1);

    $bell = tkNotifs($requester)->first();
    expect($bell->data['ticket_public_id'])->toBe($ticket->public_id)
        ->and($bell->data['message'])->toContain($ticket->ticket_number)
        ->and($bell->request_id)->toBeNull();
});

test('a reply that asks a question is a Question notification, still exactly one of each', function () {
    $requester = tkNotifRequester();
    $ticket    = tkOpenTicket($requester);

    tkService()->replyAsStaff($ticket, tkStaff(), 'Which receipt do you mean?', awaitRequester: true);

    Mail::assertQueued(TicketNotificationMail::class, 1);
    expect(tkNotifMails()->first()->kind)->toBe('question')
        ->and(tkNotifTypes($requester))->toBe(['ticket_question'])
        ->and($ticket->fresh()->status->value)->toBe('pending_client');
});

test('a reply that also claims the ticket still notifies the requester once', function () {
    $requester = tkNotifRequester();
    $ticket    = tkOpenTicket($requester);

    tkService()->replyAsStaff($ticket, tkStaff(), 'Looking into it.'); // auto-claims, then replies

    Mail::assertQueued(TicketNotificationMail::class, 1);
    expect(tkNotifTypes($requester))->toHaveCount(1);
});

test('the notification and the email never carry the text of the reply', function () {
    $requester = tkNotifRequester();
    $ticket    = tkOpenTicket($requester);

    tkService()->replyAsStaff($ticket, tkStaff(), 'Your OR number 98765 was keyed in wrongly.');

    $html = tkNotifRender(tkNotifMails()->first());

    expect($html)->toContain($ticket->ticket_number)->not->toContain('98765')
        ->and(json_encode(tkNotifs($requester)->map->data))->not->toContain('98765');
});

// ═════════════════════════════════════════════════════════════════════════════
// Resolved
// ═════════════════════════════════════════════════════════════════════════════

test('resolving tells the requester, with the date the ticket will close', function () {
    $requester = tkNotifRequester();
    $ticket    = tkOpenTicket($requester);

    tkService()->resolve($ticket, tkStaff(), 'Receipt recorded. Please confirm.');

    $closes = $ticket->fresh()->auto_close_at->setTimezone('Asia/Manila')->format('F j, Y');

    Mail::assertQueued(TicketNotificationMail::class, 1);
    expect(tkNotifMails()->first()->kind)->toBe('resolved')
        ->and(tkNotifMails()->first()->closeDate)->toBe($closes)
        ->and(tkNotifRender(tkNotifMails()->first()))->toContain($closes)
        ->and(tkNotifTypes($requester))->toBe(['ticket_resolved'])
        ->and(tkNotifs($requester)->first()->data['message'])->toContain($closes);
});

// ═════════════════════════════════════════════════════════════════════════════
// Public submitters: email only
// ═════════════════════════════════════════════════════════════════════════════

test('a public submitter gets an email and no in-app notification', function () {
    tkPubSetup();
    [$ticket] = tkPubTicket('juan@example.com', 'CAT-01');
    Mail::fake(); // forget anything queued while filing

    tkService()->replyAsStaff($ticket, tkStaff(), 'We are checking your receipt.');

    Mail::assertQueued(TicketNotificationMail::class, 1);
    expect(tkNotifMails()->first()->hasTo('juan@example.com'))->toBeTrue()
        ->and(tkNotifMails()->first()->isPublic)->toBeTrue()
        ->and(Notification::count())->toBe(0);
});

test('the link in a public email opens that ticket', function () {
    tkPubSetup();
    [$ticket] = tkPubTicket('juan@example.com', 'CAT-01');
    Mail::fake();

    tkService()->replyAsStaff($ticket, tkStaff(), 'We are checking your receipt.');

    $mail  = tkNotifMails()->first();
    $plain = tkNotifMailToken($mail);

    expect($mail->url)->toContain('#token=')->and($mail->url)->not->toContain('?token=')
        ->and(app(TicketAccessTokenService::class)->resolve($plain)?->getKey())->toBe($ticket->getKey());
});

test('each public email carries its own fresh link, and the older ones keep working', function () {
    tkPubSetup();
    [$ticket, $original] = tkPubTicket('juan@example.com', 'CAT-01');
    Mail::fake();
    $staff = tkStaff();

    tkService()->replyAsStaff($ticket, $staff, 'First reply.');
    tkService()->replyAsStaff($ticket, $staff, 'Second reply.');

    $links = tkNotifMails()->map(fn ($m) => tkNotifMailToken($m));
    $service = app(TicketAccessTokenService::class);

    expect($links)->toHaveCount(2)->and($links->unique())->toHaveCount(2)
        ->and($service->resolve($original)?->getKey())->toBe($ticket->getKey())
        ->and($service->resolve($links[0])?->getKey())->toBe($ticket->getKey());
});

test('an anonymized public ticket is never emailed', function () {
    tkPubSetup();
    [$ticket] = tkPubTicket('juan@example.com', 'CAT-01');
    Mail::fake();
    $ticket->forceFill(['anonymized_at' => now(), 'requester_email' => null, 'requester_name' => null])->save();

    tkService()->replyAsStaff($ticket, tkStaff(), 'Anything.');

    Mail::assertNothingQueued();
});

// ═════════════════════════════════════════════════════════════════════════════
// Switches and recipients that cannot be notified
// ═════════════════════════════════════════════════════════════════════════════

test('turning email off keeps the bell and sends no mail', function () {
    config(['tickets.notifications.email_enabled' => false]);
    $requester = tkNotifRequester();
    $ticket    = tkOpenTicket($requester);

    tkService()->replyAsStaff($ticket, tkStaff(), 'Reply.');

    Mail::assertNothingQueued();
    expect(tkNotifTypes($requester))->toBe(['ticket_staff_reply']);
});

test('turning email off means a public submitter is not notified at all', function () {
    tkPubSetup();
    config(['tickets.notifications.email_enabled' => false]);
    [$ticket] = tkPubTicket('juan@example.com', 'CAT-01');
    Mail::fake();

    tkService()->replyAsStaff($ticket, tkStaff(), 'Reply.');

    Mail::assertNothingQueued();
    expect(Notification::count())->toBe(0);
});

test('a requester whose account is not active is not notified', function () {
    $requester = tkNotifRequester();
    $ticket    = tkOpenTicket($requester);
    $requester->forceFill(['status' => 'Pending Verification'])->save();

    tkService()->replyAsStaff($ticket, tkStaff(), 'Reply.');

    Mail::assertNothingQueued();
    expect(tkNotifs($requester))->toBeEmpty();
});

test('a requester whose account was deleted does not break the reply', function () {
    $requester = tkNotifRequester();
    $ticket    = tkOpenTicket($requester);
    DB::table('users')->where('user_id', $requester->user_id)->delete();

    tkService()->replyAsStaff($ticket, tkStaff(), 'Reply.');

    Mail::assertNothingQueued();
    expect($ticket->fresh()->messages()->count())->toBe(2);
});

test('a notification type switched off by an admin sends no bell entry but does not break the reply', function () {
    DB::table('notification_types')->where('trigger_event', 'ticket_staff_reply')->update(['is_active' => false]);
    Cache::flush();
    $requester = tkNotifRequester();
    $ticket    = tkOpenTicket($requester);

    tkService()->replyAsStaff($ticket, tkStaff(), 'Reply.');

    expect(tkNotifs($requester))->toBeEmpty()
        ->and($ticket->fresh()->messages()->count())->toBe(2);
});

// ═════════════════════════════════════════════════════════════════════════════
// Exactly-once, retries and rollbacks
// ═════════════════════════════════════════════════════════════════════════════

test('running the same notification job twice sends nothing the second time', function () {
    $requester = tkNotifRequester();
    $ticket    = tkOpenTicket($requester);

    $job = new SendTicketNotificationJob($ticket->getKey(), 'staff_reply', 'ref-fixed');
    app()->call([$job, 'handle']);
    app()->call([$job, 'handle']);

    Mail::assertQueued(TicketNotificationMail::class, 1);
    expect(tkNotifs($requester))->toHaveCount(1);
});

test('a retry sends only the channel that had not gone out', function () {
    $requester = tkNotifRequester();
    $ticket    = tkOpenTicket($requester);

    // The bell was delivered on the first attempt; the email was not.
    Cache::put("tickets:notified:ref-retry:bell:{$requester->user_id}", 1, now()->addDay());

    app()->call([new SendTicketNotificationJob($ticket->getKey(), 'staff_reply', 'ref-retry'), 'handle']);

    expect(tkNotifs($requester))->toBeEmpty();
    Mail::assertQueued(TicketNotificationMail::class, 1);
});

test('two different replies are two notifications', function () {
    $requester = tkNotifRequester();
    $ticket    = tkOpenTicket($requester);
    $staff     = tkStaff();

    tkService()->replyAsStaff($ticket, $staff, 'First.');
    tkService()->replyAsStaff($ticket, $staff, 'Second.');

    Mail::assertQueued(TicketNotificationMail::class, 2);
    expect(tkNotifs($requester))->toHaveCount(2);
});

test('a change that rolls back notifies nobody', function () {
    $requester = tkNotifRequester();
    $ticket    = tkOpenTicket($requester);
    $staff     = tkStaff();

    try {
        DB::transaction(function () use ($ticket, $staff) {
            tkService()->replyAsStaff($ticket, $staff, 'This reply will be rolled back.');

            throw new RuntimeException('something later in the same transaction failed');
        });
    } catch (RuntimeException) {
        // expected
    }

    Mail::assertNothingQueued();
    expect(tkNotifs($requester))->toBeEmpty()
        ->and($ticket->fresh()->messages()->count())->toBe(1);
});

test('a notification for a ticket that no longer exists is skipped quietly', function () {
    app()->call([new SendTicketNotificationJob(999999, 'staff_reply', 'ref-gone'), 'handle']);

    Mail::assertNothingQueued();
    expect(Notification::count())->toBe(0);
});

test('a queued job whose kind has since been removed is dropped', function () {
    $ticket = tkOpenTicket(tkNotifRequester());

    app()->call([new SendTicketNotificationJob($ticket->getKey(), 'no_such_kind', 'ref-old'), 'handle']);

    Mail::assertNothingQueued();
});

// ═════════════════════════════════════════════════════════════════════════════
// The email itself
// ═════════════════════════════════════════════════════════════════════════════

test('every requester email renders, with and without a close date', function () {
    foreach (TicketNotificationKindEnum::cases() as $kind) {
        if (! $kind->isForRequester()) {
            continue;
        }

        foreach ([null, 'November 6, 2026'] as $closeDate) {
            foreach ([false, true] as $isPublic) {
                $mail = new TicketNotificationMail($kind->value, 'TKT-2026-00042', 'https://example.test/t', $isPublic, $closeDate);
                $html = tkNotifRender($mail);

                expect($html)->toContain('TKT-2026-00042')->toContain('https://example.test/t');
                expect($mail->build()->subject)->toContain('TKT-2026-00042');
            }
        }
    }
});

test('a public email warns against forwarding it; an account email does not', function () {
    $public  = tkNotifRender(new TicketNotificationMail('staff_reply', 'TKT-2026-00042', 'https://example.test/t', true));
    $account = tkNotifRender(new TicketNotificationMail('staff_reply', 'TKT-2026-00042', 'https://example.test/t', false));

    expect($public)->toContain('forward')->and($account)->not->toContain('forward');
});

test('the Resolved email says when the ticket closes', function () {
    $html = tkNotifRender(new TicketNotificationMail('resolved', 'TKT-2026-00042', 'https://example.test/t', false, 'November 6, 2026'));

    expect($html)->toContain('November 6, 2026')->toContain('isn');
});

test('a signed-in requester\'s email links to their ticket page', function () {
    config(['app.frontend_url' => 'https://registrar.example.test', 'tickets.notifications.requester_path' => '/tickets/{public_id}']);
    $requester = tkNotifRequester();
    $ticket    = tkOpenTicket($requester);

    tkService()->replyAsStaff($ticket, tkStaff(), 'Reply.');

    expect(tkNotifMails()->first()->url)->toBe("https://registrar.example.test/tickets/{$ticket->public_id}")
        ->and(tkNotifMails()->first()->isPublic)->toBeFalse();
});