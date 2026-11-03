<?php

use App\Enums\RequestChannelEnum;
use App\Enums\RequestStatusEnum;
use App\Jobs\SendBulkNotificationJob;
use App\Jobs\SendCalendarChangeNoticeJob;
use App\Models\AuditLog;
use App\Models\BusinessCalendar;
use App\Models\DocumentRequest;
use App\Models\Notification;
use App\Models\NotificationType;
use App\Models\SystemUser;
use App\Services\CalendarChangeNotifier;
use App\Services\CalendarExceptionService;
use App\Services\DocumentRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    config(['webpush.enabled' => false, 'notification_matrix.calendar_notice_debounce_minutes' => 10]);
});

function p5Staff(): SystemUser
{
    $user = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN, 'status' => 'Activated']);
    grantFullDashboardAccess($user);
    Sanctum::actingAs($user);

    return $user;
}

function p5Request(): DocumentRequest
{
    $owner = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT, 'status' => 'Activated']);

    return DocumentRequest::factory()->create([
        'user_id'   => $owner->user_id,
        'status_id' => RequestStatusEnum::Processing->value,
        'or_number' => 'OR-777',
    ]);
}

// ── Types and routing ───────────────────────────────────────────────────

test('the three new types exist with the intended audience and ids', function () {
    $rows = NotificationType::whereIn('trigger_event', [
        'business_calendar_modified', 'free_request_filed', 'mismatched_receipt_flagged',
    ])->get()->keyBy('trigger_event');

    expect($rows)->toHaveCount(3)
        ->and($rows['business_calendar_modified']->audience->value)->toBe('all')
        ->and($rows['free_request_filed']->audience->value)->toBe('student_alumni')
        ->and($rows['mismatched_receipt_flagged']->audience->value)->toBe('super_admin')
        ->and($rows->every(fn ($r) => (bool) $r->send_push && ! (bool) $r->send_email))->toBeTrue();
});

test('Technical/IT alerts are routed to Super Admin only, as one config entry', function () {
    expect(config('notification_matrix.technical_it_role_ids'))->toBe([SystemUser::ROLE_SUPER_ADMIN]);
});

test('an admin-filed free request sends Free Request Filed, every other channel keeps Request Submitted', function () {
    expect(DocumentRequestService::submissionTrigger(RequestChannelEnum::AdminFiledFree))->toBe('free_request_filed');

    foreach (RequestChannelEnum::cases() as $channel) {
        if ($channel !== RequestChannelEnum::AdminFiledFree) {
            expect(DocumentRequestService::submissionTrigger($channel))->toBe('request_submitted');
        }
    }
});

test('the Account Verification rows reach the requestor with the policy wording and no reason leak', function () {
    $user = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_UNDERGRAD_REQUESTOR, 'status' => 'Pending Activation']);
    $service = app(\App\Contracts\NotificationServiceInterface::class);

    $approved = $service->send($user, 'undergrad_requestor_approved', ['full_name' => 'Juan']);
    $rejected = $service->send($user, 'undergrad_requestor_rejected', ['full_name' => 'Juan', 'reason' => 'Blurry ID']);

    expect($approved->data['message'])->toBe('Your account has been verified. You may now log in and request documents through RIS.')
        ->and($rejected->data['message'])->toBe("Unable to verify submitted onboarding details. Please visit the Registrar's Office to complete verification.")
        ->and($rejected->data['message'])->not->toContain('Blurry');
});

// ── Business calendar modification ─────────────────────────────────────

test('several calendar edits inside the window become one scheduled notice', function () {
    Queue::fake();

    CalendarChangeNotifier::record('Holiday added: A (Dec 24, 2026)');
    CalendarChangeNotifier::record('Holiday added: B (Dec 25, 2026)');
    CalendarChangeNotifier::record('Holiday added: C (Dec 30, 2026)');

    Queue::assertPushed(SendCalendarChangeNoticeJob::class, 1);
});

test('the flush sends one bulk notice to every requestor role, then nothing on a repeat', function () {
    Queue::fake();
    CalendarChangeNotifier::record('Holiday added: A (Dec 24, 2026)');
    CalendarChangeNotifier::record('Holiday added: B (Dec 25, 2026)');

    (new SendCalendarChangeNoticeJob())->handle();

    Queue::assertPushed(SendBulkNotificationJob::class, function ($job) {
        return $job->triggerEvent === 'business_calendar_modified'
            && $job->onlyRoleIds === [SystemUser::ROLE_STUDENT, SystemUser::ROLE_ALUMNI, SystemUser::ROLE_UNDERGRAD_REQUESTOR]
            && $job->data['details'] === '2 updates: Holiday added: A (Dec 24, 2026); Holiday added: B (Dec 25, 2026)';
    });

    (new SendCalendarChangeNoticeJob())->handle();
    Queue::assertPushed(SendBulkNotificationJob::class, 1);
});

test('a single change is announced as itself, and a later change starts a new window', function () {
    Queue::fake();
    CalendarChangeNotifier::record('Suspension added: Typhoon (Mar 11, 2026)');
    (new SendCalendarChangeNoticeJob())->handle();

    Queue::assertPushed(SendBulkNotificationJob::class, fn ($j) => $j->data['details'] === 'Suspension added: Typhoon (Mar 11, 2026)');

    CalendarChangeNotifier::record('Holiday added: Later (Dec 1, 2026)');
    Queue::assertPushed(SendCalendarChangeNoticeJob::class, 2);
});

test('creating a holiday through the service records a change', function () {
    Queue::fake();
    $calendar = BusinessCalendar::where('is_default', true)->firstOrFail();
    $actor    = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN, 'status' => 'Activated']);

    app(CalendarExceptionService::class)->create([
        'calendar_id' => $calendar->calendar_id, 'type' => 'holiday',
        'label' => 'Bonifacio Day', 'date' => '2026-11-30',
    ], $actor, Request::create('/'));

    Queue::assertPushed(SendCalendarChangeNoticeJob::class, 1);
    expect(Cache::get(CalendarChangeNotifier::PENDING_KEY))->toBe(['Holiday added: Bonifacio Day (Nov 30, 2026)']);
});

test('a failing cache never breaks the calendar edit', function () {
    Cache::shouldReceive('lock')->andThrow(new RuntimeException('cache down'));

    CalendarChangeNotifier::record('anything');

    expect(true)->toBeTrue(); // reaching here is the assertion: no exception escaped
});

test('the calendar notice reaches students, alumni and undergrad requestors but not staff', function () {
    $student  = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT, 'status' => 'Activated']);
    $undergrad = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_UNDERGRAD_REQUESTOR, 'status' => 'Activated']);
    $admin    = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN, 'status' => 'Activated']);

    CalendarChangeNotifier::record('Holiday added: Test (Dec 24, 2026)');
    (new SendCalendarChangeNoticeJob())->handle();

    $got = fn (SystemUser $u) => Notification::where('notifiable_id', $u->user_id)
        ->whereHas('type', fn ($t) => $t->where('trigger_event', 'business_calendar_modified'))->first();

    expect($got($student)?->data['message'])->toBe('Notice: Registrar office hours/calendar updated: Holiday added: Test (Dec 24, 2026)')
        ->and($got($undergrad))->not->toBeNull()
        ->and($got($admin))->toBeNull();
});

// ── Mismatched receipt flag ────────────────────────────────────────────

test('staff can flag a receipt: audited, Super Admin alerted, request untouched', function () {
    Queue::fake();
    p5Staff();
    $request = p5Request();

    $this->postJson("/api/v1/document-requests/{$request->request_id}/flag-receipt", ['reason' => 'OR number looks overwritten'])
        ->assertOk()->assertJson(['flagged' => true, 'notified' => true]);

    Queue::assertPushed(SendBulkNotificationJob::class, fn ($j) => $j->triggerEvent === 'mismatched_receipt_flagged'
        && $j->onlyRoleIds === [SystemUser::ROLE_SUPER_ADMIN]
        && $j->requestId === $request->request_id);

    $audit = AuditLog::where('action', AuditLog::ACTION_RECEIPT_FLAGGED)->first();
    expect($audit)->not->toBeNull()
        ->and($audit->metadata['request_id'] ?? null)->toBe($request->request_id)
        ->and($request->fresh()->status_id)->toBe(RequestStatusEnum::Processing->value);
});

test('flagging twice audits twice but alerts Super Admin once', function () {
    Queue::fake();
    p5Staff();
    $request = p5Request();
    $url = "/api/v1/document-requests/{$request->request_id}/flag-receipt";

    $this->postJson($url, ['reason' => 'First look: altered'])->assertOk()->assertJson(['notified' => true]);
    $this->postJson($url, ['reason' => 'Second look: altered'])->assertOk()->assertJson(['notified' => false]);

    Queue::assertPushed(SendBulkNotificationJob::class, 1);
    expect(AuditLog::where('action', AuditLog::ACTION_RECEIPT_FLAGGED)->count())->toBe(2);
});

test('the flag needs a reason, and students cannot flag', function () {
    Queue::fake();
    $request = p5Request();

    Sanctum::actingAs(SystemUser::find($request->user_id));
    $this->postJson("/api/v1/document-requests/{$request->request_id}/flag-receipt", ['reason' => 'sneaky'])->assertForbidden();

    p5Staff();
    $this->postJson("/api/v1/document-requests/{$request->request_id}/flag-receipt", [])->assertStatus(422);
    Queue::assertNothingPushed();
});

test('the alert carries the request number and nothing else, and only Super Admin receives it', function () {
    $superAdmin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN, 'status' => 'Activated']);
    $admin      = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN, 'status' => 'Activated']);
    $request    = p5Request();

    (new SendBulkNotificationJob(
        triggerEvent: 'mismatched_receipt_flagged',
        data: ['request_id' => $request->request_id],
        onlyRoleIds: [SystemUser::ROLE_SUPER_ADMIN],
        requestId: $request->request_id,
    ))->handle(app(\App\Contracts\NotificationServiceInterface::class));

    $note = Notification::where('notifiable_id', $superAdmin->user_id)->first();

    expect($note->data['message'])->toBe("Security Flag: Request #{$request->request_id} flagged for mismatched or altered Official Receipt.")
        ->and(Notification::where('notifiable_id', $admin->user_id)->count())->toBe(0);
});
