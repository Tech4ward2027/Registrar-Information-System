<?php

use App\Models\DocumentRequest;
use App\Models\Notification;
use App\Models\RequestRemark;
use App\Models\SystemUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

// ═════════════════════════════════════════════════════════════════════════════
// Threshold behavior
// ═════════════════════════════════════════════════════════════════════════════

test('escalates an open notice issued 30+ days ago', function () {
    $admin  = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN, 'status' => 'Activated']);
    $docReq = DocumentRequest::factory()->create();
    $notice = RequestRemark::factory()->create([
        'request_id' => $docReq->request_id,
        'issued_at'  => now()->subDays(31),
    ]);

    Artisan::call('notifications:escalate-stale-deficiency-notices');

    $notice->refresh();
    expect($notice->escalated_at)->not->toBeNull();

    $notification = Notification::where('notifiable_id', $admin->user_id)
        ->where('notifiable_type', SystemUser::class)
        ->where('request_id', $docReq->request_id)
        ->first();

    expect($notification)->not->toBeNull();
});

test('does not escalate a notice younger than 30 days', function () {
    $docReq = DocumentRequest::factory()->create();
    $notice = RequestRemark::factory()->create([
        'request_id' => $docReq->request_id,
        'issued_at'  => now()->subDays(10),
    ]);

    Artisan::call('notifications:escalate-stale-deficiency-notices');

    $notice->refresh();
    expect($notice->escalated_at)->toBeNull();
});

test('does not escalate a notice that is exactly at 30 days minus one hour', function () {
    $docReq = DocumentRequest::factory()->create();
    $notice = RequestRemark::factory()->create([
        'request_id' => $docReq->request_id,
        'issued_at'  => now()->subDays(30)->addHour(),
    ]);

    Artisan::call('notifications:escalate-stale-deficiency-notices');

    $notice->refresh();
    expect($notice->escalated_at)->toBeNull();
});

// ═════════════════════════════════════════════════════════════════════════════
// Status guards
// ═════════════════════════════════════════════════════════════════════════════

test('does not escalate a cleared notice even if old enough', function () {
    $docReq = DocumentRequest::factory()->create();
    $notice = RequestRemark::factory()->cleared()->create([
        'request_id' => $docReq->request_id,
        'issued_at'  => now()->subDays(60),
    ]);

    Artisan::call('notifications:escalate-stale-deficiency-notices');

    $notice->refresh();
    expect($notice->escalated_at)->toBeNull();
});

test('does not escalate a voided notice even if old enough', function () {
    $docReq = DocumentRequest::factory()->create();
    $notice = RequestRemark::factory()->voided()->create([
        'request_id' => $docReq->request_id,
        'issued_at'  => now()->subDays(60),
    ]);

    Artisan::call('notifications:escalate-stale-deficiency-notices');

    $notice->refresh();
    expect($notice->escalated_at)->toBeNull();
});

// ═════════════════════════════════════════════════════════════════════════════
// Idempotency — never re-escalates or re-notifies
// ═════════════════════════════════════════════════════════════════════════════

test('does not re-escalate or re-notify a notice that was already escalated', function () {
    SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN, 'status' => 'Activated']);
    $docReq = DocumentRequest::factory()->create();
    $originalEscalation = now()->subDays(5);
    $notice = RequestRemark::factory()->create([
        'request_id'   => $docReq->request_id,
        'issued_at'    => now()->subDays(60),
        'escalated_at' => $originalEscalation,
    ]);

    Notification::query()->delete(); // clear anything from factory setup noise

    Artisan::call('notifications:escalate-stale-deficiency-notices');

    $notice->refresh();
    expect($notice->escalated_at->toDateTimeString())->toBe($originalEscalation->toDateTimeString());
    expect(Notification::count())->toBe(0);
});

test('running the command twice in a row only escalates each qualifying notice once', function () {
    SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN, 'status' => 'Activated']);
    $docReq = DocumentRequest::factory()->create();
    RequestRemark::factory()->create([
        'request_id' => $docReq->request_id,
        'issued_at'  => now()->subDays(45),
    ]);

    Artisan::call('notifications:escalate-stale-deficiency-notices');
    $firstRunNotificationCount = Notification::count();

    Artisan::call('notifications:escalate-stale-deficiency-notices');
    $secondRunNotificationCount = Notification::count();

    expect($firstRunNotificationCount)->toBeGreaterThan(0);
    expect($secondRunNotificationCount)->toBe($firstRunNotificationCount);
});

// ═════════════════════════════════════════════════════════════════════════════
// Multiple independent notices
// ═════════════════════════════════════════════════════════════════════════════

test('escalates multiple independent stale notices in one run', function () {
    SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN, 'status' => 'Activated']);

    $docReqOne   = DocumentRequest::factory()->create();
    $docReqTwo   = DocumentRequest::factory()->create();
    $docReqThree = DocumentRequest::factory()->create();

    $noticeOne   = RequestRemark::factory()->create(['request_id' => $docReqOne->request_id,   'issued_at' => now()->subDays(31)]);
    $noticeTwo   = RequestRemark::factory()->create(['request_id' => $docReqTwo->request_id,   'issued_at' => now()->subDays(40)]);
    $freshNotice = RequestRemark::factory()->create(['request_id' => $docReqThree->request_id, 'issued_at' => now()->subDays(2)]);

    Artisan::call('notifications:escalate-stale-deficiency-notices');

    expect($noticeOne->refresh()->escalated_at)->not->toBeNull();
    expect($noticeTwo->refresh()->escalated_at)->not->toBeNull();
    expect($freshNotice->refresh()->escalated_at)->toBeNull();
});
