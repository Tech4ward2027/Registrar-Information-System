<?php

use App\Enums\RequestStatusEnum;
use App\Mail\RequestLifecycleMail;
use App\Models\DocumentRequest;
use App\Models\Notification;
use App\Models\NotificationType;
use App\Models\RequestHistory;
use App\Models\SystemUser;
use App\Services\UnclaimedPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

// Notification Policy v1.2.0 §3.5: Day 89 final notice, Day 90 forfeiture.
// Day 1 = the Manila calendar day the request became Ready to Claim.
// Clock for most tests: 2026-11-02 in Manila, so
//   ready Aug 5 = Day 90, Aug 6 = Day 89, Aug 7 = Day 88 (Aug 5 + 89 = Nov 2).

beforeEach(function () {
    Mail::fake();
    Cache::flush();
    config(['mail.default' => 'array', 'notification_mail.enabled' => true, 'webpush.enabled' => false]);
});

function ffAt(string $manila): Carbon
{
    return Carbon::parse($manila, 'Asia/Manila');
}

function ffReadyRequest(string $readyAtManila): DocumentRequest
{
    $owner = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT, 'status' => 'Activated']);

    $request = DocumentRequest::factory()->create([
        'user_id'      => $owner->user_id,
        'status_id'    => RequestStatusEnum::ReadyToClaim->value,
        'requested_at' => ffAt($readyAtManila)->subDays(5),
    ]);

    RequestHistory::create([
        'request_id'         => $request->request_id,
        'old_status_id'      => RequestStatusEnum::Processing->value,
        'new_status_id'      => RequestStatusEnum::ReadyToClaim->value,
        'changed_at'         => ffAt($readyAtManila)->utc(),
        'changed_by'         => null,
        'processed_by_email' => 'system-test',
        'minutes_processed'  => 60,
    ]);

    return $request;
}

function ffNotices(DocumentRequest $request)
{
    return Notification::withTrashed()
        ->where('request_id', $request->request_id)
        ->whereHas('type', fn ($t) => $t->where('trigger_event', 'reminder_final_warning'))
        ->get();
}

function ffDispositions(DocumentRequest $request)
{
    return Notification::withTrashed()
        ->where('request_id', $request->request_id)
        ->whereHas('type', fn ($t) => $t->where('trigger_event', 'request_forfeited'))
        ->get();
}

function ffRunDay(string $manila): void
{
    test()->travelTo(ffAt($manila));
    test()->artisan('notifications:send-forfeiture-notices')->assertExitCode(0);
    test()->artisan('notifications:shred-expired-requests')->assertExitCode(0);
}

test('the day boundary is the Manila calendar day, not 24-hour blocks', function () {
    $this->travelTo(ffAt('2026-11-02 00:05'));

    // Ready at 23:59 on Aug 6 is Day 89 five minutes into Nov 2.
    // 5 Jan 2026 23:59 UTC would be wrong: the rule uses Manila dates.
    expect(ffAt('2026-08-06 23:59')->lt(UnclaimedPolicy::cutoff(89)))->toBeTrue()
        ->and(ffAt('2026-08-07 00:00')->lt(UnclaimedPolicy::cutoff(89)))->toBeFalse()
        ->and(ffAt('2026-08-05 23:59')->lt(UnclaimedPolicy::cutoff(90)))->toBeTrue()
        ->and(ffAt('2026-08-06 00:00')->lt(UnclaimedPolicy::cutoff(90)))->toBeFalse();
});

test('Day 88 gets no notice, Day 89 gets exactly one', function () {
    $day88 = ffReadyRequest('2026-08-07 23:00');
    $day89 = ffReadyRequest('2026-08-06 00:10');

    $this->travelTo(ffAt('2026-11-02 07:30'));
    $this->artisan('notifications:send-forfeiture-notices')->assertExitCode(0);
    $this->artisan('notifications:send-forfeiture-notices')->assertExitCode(0); // second run: no double-send

    expect(ffNotices($day88))->toHaveCount(0)
        ->and(ffNotices($day89))->toHaveCount(1);
});

test('the notice wording says tomorrow and names the document', function () {
    $request = ffReadyRequest('2026-08-06 09:00');

    $this->travelTo(ffAt('2026-11-02 07:30'));
    $this->artisan('notifications:send-forfeiture-notices')->assertExitCode(0);

    $message = ffNotices($request)->first()->data['message'];
    expect($message)->toContain('will be forfeited and shredded tomorrow')
        ->and($message)->not->toContain(':document')
        ->and($message)->not->toContain('today');
});

test('the notice goes out by email, and the type has push and email switched on', function () {
    $request = ffReadyRequest('2026-08-06 09:00');

    $this->travelTo(ffAt('2026-11-02 07:30'));
    $this->artisan('notifications:send-forfeiture-notices')->assertExitCode(0);

    Mail::assertSent(RequestLifecycleMail::class, 1);

    $type = NotificationType::where('trigger_event', 'reminder_final_warning')->firstOrFail();
    expect((bool) $type->send_push)->toBeTrue()
        ->and((bool) $type->send_email)->toBeTrue();
    expect((bool) NotificationType::where('trigger_event', 'request_forfeited')->value('send_push'))->toBeTrue();
});

test('a deleted inbox notice is not re-sent', function () {
    $request = ffReadyRequest('2026-08-06 09:00');

    $this->travelTo(ffAt('2026-11-02 07:30'));
    $this->artisan('notifications:send-forfeiture-notices')->assertExitCode(0);
    ffNotices($request)->first()->delete();

    $this->artisan('notifications:send-forfeiture-notices')->assertExitCode(0);

    expect(ffNotices($request))->toHaveCount(1);
});

test('full lifecycle: notice on Day 89, forfeiture on Day 90, nothing twice', function () {
    $request = ffReadyRequest('2026-08-05 15:00'); // Day 89 = Nov 1, Day 90 = Nov 2

    ffRunDay('2026-10-31 07:30'); // Day 88
    expect(ffNotices($request))->toHaveCount(0)
        ->and($request->fresh()->status_id)->toBe(RequestStatusEnum::ReadyToClaim->value);

    // Day 89: notice, and the 08:00 forfeiture job leaves it alone.
    ffRunDay('2026-11-01 07:30');
    $this->travelTo(ffAt('2026-11-01 08:00'));
    $this->artisan('notifications:shred-expired-requests')->assertExitCode(0);
    expect(ffNotices($request))->toHaveCount(1)
        ->and($request->fresh()->status_id)->toBe(RequestStatusEnum::ReadyToClaim->value)
        ->and(ffDispositions($request))->toHaveCount(0);

    // Day 90: forfeited, disposition logged once.
    ffRunDay('2026-11-02 07:30');
    $this->travelTo(ffAt('2026-11-02 08:00'));
    $this->artisan('notifications:shred-expired-requests')->assertExitCode(0);
    expect($request->fresh()->status_id)->toBe(RequestStatusEnum::Forfeited->value)
        ->and(ffNotices($request))->toHaveCount(1)
        ->and(ffDispositions($request))->toHaveCount(1);

    // Day 91: nothing more happens.
    ffRunDay('2026-11-03 07:30');
    expect(ffNotices($request))->toHaveCount(1)
        ->and(ffDispositions($request))->toHaveCount(1);
});

test('a request already past Day 90 gets its notice first and is forfeited the next day', function () {
    $request = ffReadyRequest('2026-07-01 10:00'); // long overdue at deploy time

    ffRunDay('2026-11-02 07:30');
    expect(ffNotices($request))->toHaveCount(1)
        ->and($request->fresh()->status_id)->toBe(RequestStatusEnum::ReadyToClaim->value);

    ffRunDay('2026-11-03 07:30');
    expect($request->fresh()->status_id)->toBe(RequestStatusEnum::Forfeited->value)
        ->and(ffNotices($request))->toHaveCount(1);
});

test('a notice that predates the latest Ready to Claim does not authorise forfeiture', function () {
    $request = ffReadyRequest('2026-08-05 10:00'); // Day 90 on Nov 2

    // A stale notice from an earlier cycle, created before the request last became ready.
    $this->travelTo(ffAt('2026-08-01 09:00'));
    app(\App\Contracts\NotificationServiceInterface::class)->send(
        recipient:    $request->user,
        triggerEvent: 'reminder_final_warning',
        data:         ['request_id' => $request->request_id, 'document_name' => 'x', 'document_noun' => 'document'],
        requestId:    $request->request_id,
    );

    $this->travelTo(ffAt('2026-11-02 08:00'));
    $this->artisan('notifications:shred-expired-requests')->assertExitCode(0);

    expect($request->fresh()->status_id)->toBe(RequestStatusEnum::ReadyToClaim->value);
});

test('a request that left Ready to Claim is never noticed or forfeited', function () {
    $request = ffReadyRequest('2026-08-05 10:00');
    $request->update(['status_id' => RequestStatusEnum::Processing->value]);

    ffRunDay('2026-11-02 07:30');

    expect(ffNotices($request))->toHaveCount(0)
        ->and($request->fresh()->status_id)->toBe(RequestStatusEnum::Processing->value);
});

test('the 7-day reminder and the Day 89 notice are independent and never duplicate', function () {
    $request = ffReadyRequest('2026-10-26 07:00'); // inside the reminder's rolling 7-day window
    $this->travelTo(ffAt('2026-11-02 08:05'));
    $this->artisan('notifications:send-unclaimed-reminders')->assertExitCode(0);
    $this->artisan('notifications:send-forfeiture-notices')->assertExitCode(0);

    $types = Notification::where('request_id', $request->request_id)->with('type')->get()
        ->pluck('type.trigger_event')->all();

    expect($types)->toBe(['reminder_claim']);
});
