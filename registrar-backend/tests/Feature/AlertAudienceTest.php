<?php

use App\Jobs\SendBulkNotificationJob;
use App\Models\SystemUser;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

// Policy v1.2.0: break-glass and 30-day deficiency-escalation alerts go to
// Super Admin only, through an allow-list that fails closed.

test('sendToRoles targets exactly the given roles and removes duplicates', function () {
    Bus::fake();

    app(NotificationService::class)->sendToRoles([4, 4], 'local_auth_login_used', ['email' => 'a@b.c']);

    Bus::assertDispatched(SendBulkNotificationJob::class, fn ($job) => $job->onlyRoleIds === [4]
        && $job->excludedRoleIds === []
        && $job->triggerEvent === 'local_auth_login_used');
});

test('sendToRoles with an empty list sends nothing instead of everyone', function () {
    Bus::fake();

    app(NotificationService::class)->sendToRoles([], 'local_auth_login_used');

    Bus::assertNotDispatched(SendBulkNotificationJob::class);
});

test('the configured audience for both alerts is Super Admin only', function () {
    expect(config('notification_matrix.alert_audience_role_ids'))->toBe([
        'local_auth_login_used'       => [SystemUser::ROLE_SUPER_ADMIN],
        'deficiency_notice_escalated' => [SystemUser::ROLE_SUPER_ADMIN],
    ]);
});

test('the notification types carry the matching audience', function () {
    foreach (['local_auth_login_used', 'deficiency_notice_escalated'] as $trigger) {
        expect(DB::table('notification_types')->where('trigger_event', $trigger)->value('audience'))
            ->toBe('super_admin');
    }
});