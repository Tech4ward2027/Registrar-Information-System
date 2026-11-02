<?php

use App\DTOs\PushDispatchResult;
use App\Jobs\SendWebPushJob;
use App\Models\Notification;
use App\Models\NotificationDispatchLog;
use App\Models\PushSubscription;
use App\Models\SystemUser;
use App\Services\NotificationDispatchLogger;
use App\Services\NotificationService;
use App\Services\WebPushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

// Notification Policy §4: every dispatch attempt is written to an audit trail.

beforeEach(function () {
    config([
        'webpush.enabled'           => true,
        'webpush.vapid.subject'     => 'mailto:test@example.com',
        'webpush.vapid.public_key'  => 'test-public',
        'webpush.vapid.private_key' => 'test-private',
    ]);
});

function dispatchLogTestUser(): SystemUser
{
    return SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT, 'status' => 'Activated']);
}

test('an in-app notification writes a sent row with its trigger, recipient and notification id', function () {
    Queue::fake();
    $user = dispatchLogTestUser();

    $notification = app(NotificationService::class)->send($user, 'request_submitted', ['request_id' => 1]);

    $row = NotificationDispatchLog::where('channel', NotificationDispatchLog::CHANNEL_IN_APP)->firstOrFail();
    expect($row->status)->toBe(NotificationDispatchLog::STATUS_SENT);
    expect($row->trigger_event)->toBe('request_submitted');
    expect($row->user_id)->toBe($user->user_id);
    expect($row->notification_id)->toBe($notification->id);
    expect($row->delivered)->toBe(1);
});

test('an unknown trigger writes a failed row instead of vanishing', function () {
    $user = dispatchLogTestUser();

    $result = app(NotificationService::class)->send($user, 'definitely_not_a_trigger');

    expect($result)->toBeNull();
    $row = NotificationDispatchLog::firstOrFail();
    expect($row->status)->toBe(NotificationDispatchLog::STATUS_FAILED);
    expect($row->reason)->toBe(NotificationDispatchLog::REASON_UNKNOWN_TRIGGER);
    expect($row->trigger_event)->toBe('definitely_not_a_trigger');
});

test('a failure while writing the dispatch log never breaks notification delivery', function () {
    Queue::fake();
    $user = dispatchLogTestUser();

    // Simulate the log store being unavailable.
    \Illuminate\Support\Facades\Schema::drop('notification_dispatch_logs');

    $notification = app(NotificationService::class)->send($user, 'request_submitted', ['request_id' => 1]);

    expect($notification)->not->toBeNull();
    expect(Notification::count())->toBe(1);
});

test('the push job records skipped when the notification was already read', function () {
    $user = dispatchLogTestUser();
    $type = \App\Models\NotificationType::where('trigger_event', 'request_submitted')->firstOrFail();

    $notification = Notification::create([
        'notification_type_id' => $type->notification_type_id,
        'notifiable_type'      => SystemUser::class,
        'notifiable_id'        => $user->user_id,
        'data'                 => ['message' => 'x'],
        'read_at'              => now(),
    ]);

    (new SendWebPushJob($notification->id))->handle(app(WebPushService::class), app(NotificationDispatchLogger::class));

    $row = NotificationDispatchLog::where('channel', NotificationDispatchLog::CHANNEL_PUSH)->firstOrFail();
    expect($row->status)->toBe(NotificationDispatchLog::STATUS_SKIPPED);
    expect($row->reason)->toBe(NotificationDispatchLog::REASON_NOTIFICATION_GONE);
    expect($row->notification_id)->toBe($notification->id);
});

test('the push job records skipped when the recipient is not active', function () {
    $user = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT, 'status' => 'Deactivated']);
    $type = \App\Models\NotificationType::where('trigger_event', 'request_submitted')->firstOrFail();

    $notification = Notification::create([
        'notification_type_id' => $type->notification_type_id,
        'notifiable_type'      => SystemUser::class,
        'notifiable_id'        => $user->user_id,
        'data'                 => ['message' => 'x'],
    ]);

    (new SendWebPushJob($notification->id))->handle(app(WebPushService::class), app(NotificationDispatchLogger::class));

    $row = NotificationDispatchLog::where('channel', NotificationDispatchLog::CHANNEL_PUSH)->firstOrFail();
    expect($row->reason)->toBe(NotificationDispatchLog::REASON_RECIPIENT_INACTIVE);
});

test('the push job records the real outcome from the push service (sent, partial, failed, no session)', function () {
    $user = dispatchLogTestUser();
    $type = \App\Models\NotificationType::where('trigger_event', 'request_submitted')->firstOrFail();

    $make = fn () => Notification::create([
        'notification_type_id' => $type->notification_type_id,
        'notifiable_type'      => SystemUser::class,
        'notifiable_id'        => $user->user_id,
        'data'                 => ['message' => 'x'],
    ]);

    $outcomes = [
        [PushDispatchResult::attempted(2, 2), NotificationDispatchLog::STATUS_SENT, null],
        [PushDispatchResult::attempted(2, 1), NotificationDispatchLog::STATUS_PARTIAL, NotificationDispatchLog::REASON_PROVIDER_REJECTED],
        [PushDispatchResult::attempted(2, 0), NotificationDispatchLog::STATUS_FAILED, NotificationDispatchLog::REASON_PROVIDER_REJECTED],
        [PushDispatchResult::skipped(NotificationDispatchLog::REASON_NO_ACTIVE_SESSION), NotificationDispatchLog::STATUS_SKIPPED, NotificationDispatchLog::REASON_NO_ACTIVE_SESSION],
        [PushDispatchResult::failed('bad vapid'), NotificationDispatchLog::STATUS_FAILED, NotificationDispatchLog::REASON_EXCEPTION],
    ];

    foreach ($outcomes as [$result, $status, $reason]) {
        NotificationDispatchLog::query()->delete();

        $push = Mockery::mock(WebPushService::class);
        $push->shouldReceive('isConfigured')->andReturnTrue();
        $push->shouldReceive('dispatchToUser')->once()->andReturn($result);

        (new SendWebPushJob($make()->id))->handle($push, app(NotificationDispatchLogger::class));

        $row = NotificationDispatchLog::where('channel', NotificationDispatchLog::CHANNEL_PUSH)->firstOrFail();
        expect($row->status)->toBe($status)
            ->and($row->reason)->toBe($reason)
            ->and($row->user_id)->toBe($user->user_id)
            ->and($row->trigger_event)->toBe('request_submitted');
    }
});

test('the dispatch log never stores URLs from error text and truncates long errors', function () {
    app(NotificationDispatchLogger::class)->record(
        NotificationDispatchLog::CHANNEL_PUSH,
        NotificationDispatchLog::STATUS_FAILED,
        ['error' => 'cURL error for https://fcm.googleapis.com/fcm/send/SECRET-TOKEN-123 timed out ' . str_repeat('x', 400)],
    );

    $row = NotificationDispatchLog::firstOrFail();
    expect($row->error)->not->toContain('SECRET-TOKEN-123')
        ->and($row->error)->toContain('[url]')
        ->and(mb_strlen($row->error))->toBeLessThanOrEqual(255);
});

test('dispatch log rows are append-only', function () {
    app(NotificationDispatchLogger::class)->record(NotificationDispatchLog::CHANNEL_IN_APP, NotificationDispatchLog::STATUS_SENT);
    $row = NotificationDispatchLog::firstOrFail();

    expect(fn () => $row->update(['status' => 'failed']))->toThrow(RuntimeException::class);
    expect(fn () => $row->delete())->toThrow(RuntimeException::class);
});

test('the retention command removes only rows older than the window', function () {
    config(['notification_dispatch.retention_days' => 30]);

    NotificationDispatchLog::create([
        'channel' => 'push', 'status' => 'sent', 'created_at' => now()->subDays(31),
    ]);
    NotificationDispatchLog::create([
        'channel' => 'push', 'status' => 'sent', 'created_at' => now()->subDays(5),
    ]);

    $this->artisan('notifications:prune-dispatch-logs')->assertExitCode(0);

    expect(NotificationDispatchLog::count())->toBe(1);
});
