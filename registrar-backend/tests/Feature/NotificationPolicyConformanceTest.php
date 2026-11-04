<?php

use App\Models\NotificationType;
use App\Services\Mail\MailPolicy;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

// Notification Policy v1.2.0 sign-off checks (Phase 7).
//
// NotificationMatrixTest guards drift between config/notification_matrix.php
// and the database. These tests guard the policy's own promises, so the
// Registrar can sign off against a short list that fails loudly if any of
// them is ever broken by a later change:
//
//   1. Email is limited to the seven lifecycle triggers plus five approved
//      exceptions, and nothing else.
//   2. Every row of the §3.4 matrix is live in the database, in-app and push.
//   3. The Day 89 notice and Day 90 forfeiture run daily on Manila time, in
//      that order.
//
// Day 7 / 89 / 90 behaviour is covered end to end by ForfeitureFlowTest;
// mail quota and throttling by SystemMailerTest; push suppression after
// logout or expiry by PushSessionBindingTest; the §3.6 routing by
// DeceasedRequestorTest.

beforeEach(function () {
    config(['webpush.enabled' => false]);
    Cache::flush();
});

test('the email allow-list is exactly 7 lifecycle triggers plus 5 approved exceptions', function () {
    expect(MailPolicy::LIFECYCLE_TRIGGERS)->toHaveCount(7)
        ->and(MailPolicy::APPROVED_EXCEPTIONS)->toHaveCount(5)
        ->and(array_intersect(MailPolicy::LIFECYCLE_TRIGGERS, MailPolicy::APPROVED_EXCEPTIONS))->toBe([])
        ->and(MailPolicy::all())->toHaveCount(12);

    foreach (MailPolicy::all() as $trigger) {
        expect(MailPolicy::isApproved($trigger))->toBeTrue();
    }

    // Routine status changes must never be approved for email.
    foreach (['request_submitted', 'request_processing', 'deficiency_notice_cleared', 'announcement_published', 'business_calendar_modified', 'free_request_filed', 'mismatched_receipt_flagged'] as $routine) {
        expect(MailPolicy::isApproved($routine))->toBeFalse("{$routine} must not be able to send email");
    }
});

test('only allow-listed types are flagged for email in the database', function () {
    $flagged = NotificationType::where('send_email', true)->pluck('trigger_event')->all();

    expect($flagged)->not->toBeEmpty();
    foreach ($flagged as $trigger) {
        expect(MailPolicy::isLifecycle($trigger))->toBeTrue("{$trigger} is flagged for email but is not a lifecycle trigger");
    }
});

test('every matrix row is live in the database with a title, an audience and a message', function () {
    foreach (config('notification_matrix.types') as $trigger => $row) {
        $type = NotificationType::where('trigger_event', $trigger)->first();

        expect($type)->not->toBeNull("{$trigger} is missing from notification_types");
        expect((bool) $type->is_active)->toBeTrue("{$trigger} must be active");
        expect(trim((string) $type->title))->not->toBe('', "{$trigger} needs a title");
        expect(trim((string) $type->audience))->not->toBe('', "{$trigger} needs an audience");
        expect(trim((string) $type->message_template))->not->toBe('', "{$trigger} needs a message");
        expect((bool) $type->send_push)->toBeTrue("{$trigger} must have push on");
    }
});

test('the Day 89 notice is worded for tomorrow, never today', function () {
    $template = config('notification_matrix.types.reminder_final_warning.template');

    expect($template)->toContain('tomorrow')
        ->and($template)->not->toContain('today')
        ->and($template)->toContain(':document_name');
});

test('the Request Closed message carries the document and the closure reason', function () {
    $closed = config('notification_matrix.types.request_closed_unable_to_process.template');

    expect($closed)->toContain(':closure_reason')->and($closed)->toContain(':document_name');
});

test('the forfeiture notice and forfeiture run daily on Manila time, notice first', function () {
    Artisan::call('list');   // boots routes/console.php, which registers the schedule

    $events = collect(app(Schedule::class)->events());
    $notice = $events->first(fn ($e) => str_contains((string) $e->command, 'notifications:send-forfeiture-notices'));
    $shred  = $events->first(fn ($e) => str_contains((string) $e->command, 'notifications:shred-expired-requests'));

    expect($notice)->not->toBeNull('forfeiture notice job is not scheduled')
        ->and($shred)->not->toBeNull('shred job is not scheduled');

    $tz = config('app.display_timezone', 'Asia/Manila');
    expect((string) $notice->timezone)->toBe($tz)
        ->and((string) $shred->timezone)->toBe($tz)
        ->and($notice->expression)->toBe('30 7 * * *')   // 07:30 daily
        ->and($shred->expression)->toBe('0 8 * * *');    // 08:00 daily, after the notice
});
