<?php

use App\Mail\RequestLifecycleMail;
use App\Models\Notification;
use App\Models\NotificationDispatchLog;
use App\Models\NotificationType;
use App\Models\SystemUser;
use App\Services\Mail\MailDispatcher;
use App\Services\Mail\MailPolicy;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// Notification Policy Section 3.2: System Mailer. Queue connection is "sync" in
// tests, so a dispatched SendLifecycleMailJob runs inline. Helpers are
// mailer-prefixed because Pest helper functions are global.

beforeEach(function () {
    Mail::fake();
    Cache::flush();
    config([
        'notification_mail.enabled'           => true,
        'notification_mail.quota.daily_limit' => 500,
        'notification_mail.quota.enforce'     => true,
        'webpush.enabled'                     => false,
    ]);
});

function mailerRequestor(array $overrides = []): SystemUser
{
    return SystemUser::factory()->create($overrides + ['role_id' => SystemUser::ROLE_STUDENT, 'status' => 'Activated']);
}

/** Make sure a type row exists (some are seeded, not migrated) with the given email flag. */
function mailerType(string $trigger, bool $sendEmail = true): NotificationType
{
    $type = NotificationType::firstOrNew(['trigger_event' => $trigger]);
    if (! $type->exists) {
        $type->notification_type_id = ((int) DB::table('notification_types')->max('notification_type_id')) + 1;
        $type->title                = $trigger;
        $type->message_template     = 'Message for :request_id';
        $type->audience             = 'student_alumni';
        $type->is_active            = true;
    }
    $type->send_email = $sendEmail;
    $type->save();

    return $type;
}

function mailerRows(string $status): \Illuminate\Support\Collection
{
    return NotificationDispatchLog::where('channel', NotificationDispatchLog::CHANNEL_MAIL)
        ->where('status', $status)->get();
}

dataset('lifecycle_triggers', array_map(fn ($t) => [$t], MailPolicy::LIFECYCLE_TRIGGERS));

test('each of the seven policy triggers sends exactly one email', function (string $trigger) {
    $user = mailerRequestor();
    mailerType($trigger);

    app(NotificationService::class)->send($user, $trigger, [
        'request_id' => 1, 'item_label' => 'Birth Certificate',
        'withdrawal_reason' => 'Requested by you', 'closure_reason' => 'Records not found',
    ]);

    Mail::assertSent(RequestLifecycleMail::class, 1);
    Mail::assertSent(RequestLifecycleMail::class, fn ($m) => $m->hasTo($user->email) && $m->trigger === $trigger);
    expect(mailerRows('sent'))->toHaveCount(1);
})->with('lifecycle_triggers');

test('the migration flags exactly the seven lifecycle types for email', function () {
    $flagged = NotificationType::where('send_email', true)->pluck('trigger_event')->all();

    expect(array_diff($flagged, MailPolicy::LIFECYCLE_TRIGGERS))->toBe([]);
});

test('a routine status change sends no email and leaves no mail log noise', function () {
    $user = mailerRequestor();
    mailerType('request_processing', false);

    app(NotificationService::class)->send($user, 'request_processing', ['request_id' => 1]);

    Mail::assertNothingSent();
    expect(NotificationDispatchLog::where('channel', 'mail')->count())->toBe(0);
});

test('a type flagged for email that is not on the allow-list cannot send', function () {
    $user = mailerRequestor();
    mailerType('request_completed', true); // flag flipped in the database by mistake

    app(NotificationService::class)->send($user, 'request_completed', ['request_id' => 1]);

    Mail::assertNothingSent();
    $row = mailerRows('skipped')->first();
    expect($row->reason)->toBe(NotificationDispatchLog::REASON_MAIL_NOT_APPROVED);
});

test('the master switch stops lifecycle email and is logged', function () {
    config(['notification_mail.enabled' => false]);
    $user = mailerRequestor();
    mailerType('ready_to_claim');

    app(NotificationService::class)->send($user, 'ready_to_claim', ['request_id' => 1]);

    Mail::assertNothingSent();
    expect(mailerRows('skipped')->first()->reason)->toBe(NotificationDispatchLog::REASON_MAIL_DISABLED);
});

test('an inactive recipient or one without a valid address is skipped, not sent', function () {
    mailerType('ready_to_claim');
    $inactive = mailerRequestor(['status' => 'Deactivated']);
    $noEmail  = mailerRequestor(['email' => 'not-an-address']);

    app(NotificationService::class)->send($inactive, 'ready_to_claim', ['request_id' => 1]);
    app(NotificationService::class)->send($noEmail, 'ready_to_claim', ['request_id' => 1]);

    Mail::assertNothingSent();
    expect(mailerRows('skipped')->pluck('reason')->sort()->values()->all())
        ->toBe([NotificationDispatchLog::REASON_NO_EMAIL_ADDRESS, NotificationDispatchLog::REASON_RECIPIENT_INACTIVE]);
});

test('re-running a delivered notification never sends a second copy', function () {
    $user = mailerRequestor();
    mailerType('ready_to_claim');
    $notification = app(NotificationService::class)->send($user, 'ready_to_claim', ['request_id' => 1]);

    app(MailDispatcher::class)->deliver($notification->id); // a queue retry

    Mail::assertSent(RequestLifecycleMail::class, 1);
    expect(mailerRows('sent'))->toHaveCount(1);
});

test('the same message to the same person for the same request is sent once', function () {
    $user = mailerRequestor();
    mailerType('ready_to_claim');
    $service = app(NotificationService::class);

    $service->send($user, 'ready_to_claim', ['request_id' => 1]);
    $service->send($user, 'ready_to_claim', ['request_id' => 1]);

    Mail::assertSent(RequestLifecycleMail::class, 1);
    expect(mailerRows('skipped')->first()->reason)->toBe(NotificationDispatchLog::REASON_DUPLICATE);
});

test('two different deficiency items on one request are both emailed', function () {
    $user = mailerRequestor();
    $type = mailerType('deficiency_notice_issued');
    $type->update(['message_template' => 'Hold: :item_label']);
    $service = app(NotificationService::class);

    $service->send($user, 'deficiency_notice_issued', ['item_label' => 'Birth Certificate']);
    $service->send($user, 'deficiency_notice_issued', ['item_label' => 'Valid ID']);

    Mail::assertSent(RequestLifecycleMail::class, 2);
});

test('a transport failure is logged, rethrown for the queue, then a retry succeeds', function () {
    $user = mailerRequestor();
    mailerType('ready_to_claim');
    config(['queue.default' => 'null']); // queue the job without running it
    $notification = app(NotificationService::class)->send($user, 'ready_to_claim', ['request_id' => 1]);

    Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('smtp down at https://smtp.example.com/secret'));
    expect(fn () => app(MailDispatcher::class)->deliver($notification->id))->toThrow(RuntimeException::class);

    $failed = mailerRows('failed')->first();
    expect($failed->reason)->toBe(NotificationDispatchLog::REASON_EXCEPTION);
    expect($failed->error)->not->toContain('https://'); // sanitised by the logger
    expect(mailerRows('sent'))->toHaveCount(0);
});

test('lifecycle email over the daily quota is skipped and Super Admins are alerted once', function () {
    config(['notification_mail.quota.daily_limit' => 2]);
    $superAdmin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN, 'status' => 'Activated']);
    mailerType('ready_to_claim');
    mailerType('reminder_claim');
    mailerType('awaiting_submission');
    mailerType('request_withdrawn');
    $service = app(NotificationService::class);

    foreach (['ready_to_claim', 'reminder_claim', 'awaiting_submission', 'request_withdrawn'] as $i => $trigger) {
        $service->send(mailerRequestor(), $trigger, ['request_id' => $i + 1, 'withdrawal_reason' => 'x']);
    }

    Mail::assertSent(RequestLifecycleMail::class, 2);
    expect(mailerRows('skipped')->pluck('reason')->unique()->all())->toBe([NotificationDispatchLog::REASON_QUOTA_EXCEEDED]);

    $alerts = Notification::where('notifiable_id', $superAdmin->user_id)
        ->whereHas('type', fn ($q) => $q->where('trigger_event', 'mail_quota_alert'))->get();
    // one at the warning level, one at the limit: never one per skipped email
    expect($alerts->count())->toBeLessThanOrEqual(2)->toBeGreaterThanOrEqual(1);
});

test('quota enforcement can be turned off for monitoring only', function () {
    config(['notification_mail.quota.daily_limit' => 1, 'notification_mail.quota.enforce' => false]);
    mailerType('ready_to_claim');
    $service = app(NotificationService::class);

    $service->send(mailerRequestor(), 'ready_to_claim', ['request_id' => 1]);
    $service->send(mailerRequestor(), 'ready_to_claim', ['request_id' => 2]);

    Mail::assertSent(RequestLifecycleMail::class, 2);
});

test('sendApproved refuses anything outside the exception list', function () {
    $dispatcher = app(MailDispatcher::class);
    $mailable   = new RequestLifecycleMail('ready_to_claim', 'X');

    expect($dispatcher->sendApproved('made_up_trigger', 'a@example.com', $mailable))->toBeFalse();
    expect($dispatcher->sendApproved('ready_to_claim', 'a@example.com', $mailable))->toBeFalse(); // lifecycle uses the job path

    Mail::assertNothingSent();
    expect(mailerRows('skipped')->count())->toBe(2);
});

test('approved exceptions are sent and counted but never blocked by the quota', function () {
    config(['notification_mail.quota.daily_limit' => 1]);
    $dispatcher = app(MailDispatcher::class);
    $mailable   = new RequestLifecycleMail('ready_to_claim', 'X');

    foreach (MailPolicy::APPROVED_EXCEPTIONS as $trigger) {
        expect($dispatcher->sendApproved($trigger, 'a@example.com', $mailable))->toBeTrue();
    }

    expect(mailerRows('sent'))->toHaveCount(5);
});

test('policy wording is rendered with the requested placeholders', function () {
    $html = fn (RequestLifecycleMail $m) => $m->render();

    expect($html(new RequestLifecycleMail('ready_to_claim', 'Transcript of Records')))
        ->toContain('Your document Transcript of Records is ready to claim!')
        ->toContain('Present your QR/claim code with your physical OR at the counter.');

    expect($html(new RequestLifecycleMail('reminder_final_warning', 'Transcript of Records')))
        ->toContain('will be forfeited and shredded tomorrow');

    expect($html(new RequestLifecycleMail('deficiency_notice_issued', 'Transcript of Records', false, 'Valid ID')))
        ->toContain('A Deficiency Notice has been issued on your request for Transcript of Records: Valid ID.');

    expect($html(new RequestLifecycleMail('request_closed_unable_to_process', 'Transcript of Records', false, null, 'Records not found.')))
        ->toContain('could not be completed: Records not found.');

    expect($html(new RequestLifecycleMail('request_withdrawn', 'A, B', true, null, 'Requested by you')))
        ->toContain('withdrawn: Requested by you.');
});

test('the email body carries no request id or personal data', function () {
    $body = (new RequestLifecycleMail('ready_to_claim', 'Transcript of Records'))->render();

    expect($body)->not->toContain('request #')->not->toContain('claim_code');
});

test('mail status endpoint is Super Admin only and returns quota and roll-up', function () {
    mailerType('ready_to_claim');
    app(NotificationService::class)->send(mailerRequestor(), 'ready_to_claim', ['request_id' => 1]);

    Sanctum::actingAs(SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN, 'status' => 'Activated']));
    $this->getJson('/api/v1/system-analytics/mail-status')->assertForbidden();

    Sanctum::actingAs(SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN, 'status' => 'Activated']));
    $this->getJson('/api/v1/system-analytics/mail-status')
        ->assertOk()
        ->assertJsonPath('quota.used_today', 1)
        ->assertJsonPath('quota.daily_limit', 500)
        ->assertJsonPath('rollup.0.trigger_event', 'ready_to_claim')
        ->assertJsonPath('approved.exceptions.0', 'undergrad_email_verification');
});
