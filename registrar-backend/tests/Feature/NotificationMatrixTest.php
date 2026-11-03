<?php

use App\Models\DocumentRequest;
use App\Models\NotificationType;
use App\Models\SystemUser;
use App\Services\Mail\MailPolicy;
use App\Services\NotificationMatrixSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// Policy §3.4: config/notification_matrix.php is the source of truth and the
// database must match it exactly. These tests are the drift alarm.

beforeEach(function () {
    config(['webpush.enabled' => false]);
    Cache::flush();
});

test('every matrix row exists in the database with the approved flags and wording', function () {
    foreach (config('notification_matrix.types') as $trigger => $row) {
        $type = NotificationType::where('trigger_event', $trigger)->first();

        expect($type)->not->toBeNull("matrix trigger {$trigger} has no notification_types row");
        expect((bool) $type->send_email)->toBe($row['email'], "{$trigger}: email flag");
        expect((bool) $type->send_push)->toBe($row['push'], "{$trigger}: push flag");
        expect($type->message_template)->toBe($row['template'], "{$trigger}: template");
    }
});

test('email is on for exactly the seven lifecycle triggers and nothing else', function () {
    $matrixEmail = collect(config('notification_matrix.types'))
        ->filter(fn ($row) => $row['email'])->keys()->sort()->values()->all();

    expect($matrixEmail)->toBe(collect(MailPolicy::LIFECYCLE_TRIGGERS)->sort()->values()->all());

    $dbEmail = NotificationType::where('send_email', true)->pluck('trigger_event')->sort()->values()->all();
    expect($dbEmail)->toBe($matrixEmail);
});

test('every matrix row has push on, as §3.4 requires', function () {
    foreach (config('notification_matrix.types') as $trigger => $row) {
        expect($row['push'])->toBeTrue("{$trigger} must be push YES in §3.4");
    }
});

test('templates only use placeholders the system knows how to fill', function () {
    $known = config('notification_matrix.placeholders');

    foreach (config('notification_matrix.types') as $trigger => $row) {
        preg_match_all('/:([a-z_]+)/', $row['template'], $m);
        foreach ($m[1] as $token) {
            expect(in_array($token, $known, true))->toBeTrue("{$trigger} uses unknown placeholder :{$token}");
        }
    }
});

test('pending Phase 5 triggers are not silently missing from the plan', function () {
    expect(config('notification_matrix.pending'))->toHaveCount(5);
    foreach (config('notification_matrix.pending') as $trigger) {
        expect(config("notification_matrix.types.{$trigger}"))->toBeNull();
    }
});

test('applying the matrix twice changes nothing', function () {
    NotificationMatrixSync::apply();
    $first = DB::table('notification_types')->orderBy('notification_type_id')->get()->toJson();
    NotificationMatrixSync::apply();

    expect(DB::table('notification_types')->orderBy('notification_type_id')->get()->toJson())->toBe($first);
});

test('the sync repairs a drifted database', function () {
    DB::table('notification_types')->where('trigger_event', 'ready_to_claim')
        ->update(['send_email' => 0, 'send_push' => 0, 'message_template' => 'Your document is ready for claiming.']);

    NotificationMatrixSync::apply();

    $type = NotificationType::where('trigger_event', 'ready_to_claim')->first();
    expect((bool) $type->send_email)->toBeTrue()
        ->and((bool) $type->send_push)->toBeTrue()
        ->and($type->message_template)->toBe(config('notification_matrix.types.ready_to_claim.template'));
});

test('the document name and OR number are filled in without the caller supplying them', function () {
    $owner   = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT, 'status' => 'Activated']);
    $request = DocumentRequest::factory()->create(['user_id' => $owner->user_id, 'or_number' => 'OR-12345']);

    $notification = app(\App\Contracts\NotificationServiceInterface::class)->send(
        recipient: $owner,
        triggerEvent: 'request_submitted',
        data: ['request_id' => $request->request_id],
        requestId: $request->request_id,
    );

    expect($notification->data['message'])
        ->toContain('OR #: OR-12345')
        ->not->toContain(':document_name')
        ->not->toContain(':or_number');
});

test('a request with no OR number says N/A instead of leaking the placeholder', function () {
    $owner   = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT, 'status' => 'Activated']);
    $request = DocumentRequest::factory()->create(['user_id' => $owner->user_id, 'or_number' => null]);

    $notification = app(\App\Contracts\NotificationServiceInterface::class)->send(
        recipient: $owner, triggerEvent: 'request_submitted',
        data: ['request_id' => $request->request_id], requestId: $request->request_id,
    );

    expect($notification->data['message'])->toContain('OR #: N/A');
});

test('announcement details are derived from the announcement content and capped', function () {
    $owner = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT, 'status' => 'Activated']);

    $notification = app(\App\Contracts\NotificationServiceInterface::class)->send(
        recipient: $owner,
        triggerEvent: 'announcement_published',
        data: [
            'announcement_title'   => 'Office closed',
            'announcement_content' => '<p>' . str_repeat('Long text. ', 80) . '</p>',
        ],
    );

    $message = $notification->data['message'];
    expect($message)->toStartWith('Important Notice: Office closed - ')
        ->and($message)->not->toContain('<p>')
        ->and(mb_strlen($message))->toBeLessThan(360);
});

test('values the caller supplies are never overwritten by enrichment', function () {
    $owner = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT, 'status' => 'Activated']);

    $notification = app(\App\Contracts\NotificationServiceInterface::class)->send(
        recipient: $owner, triggerEvent: 'request_submitted',
        data: ['document_name' => 'Transcript of Records', 'or_number' => 'OR-9'],
    );

    expect($notification->data['message'])->toBe('Request for Transcript of Records validated (OR #: OR-9). Track progress in your dashboard.');
});
