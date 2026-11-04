<?php

use App\Enums\ClosureReasonEnum;
use App\Enums\RequestStatusEnum;
use App\Mail\RequestLifecycleMail;
use App\Models\AuditLog;
use App\Models\DocumentRequest;
use App\Models\NotificationDispatchLog;
use App\Models\NotificationType;
use App\Models\RequestorStatusFlag;
use App\Models\RequestRepresentative;
use App\Models\StudentProfile;
use App\Models\SystemUser;
use App\Services\Mail\MailDispatcher;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// Notification Policy §3.6: deceased or permanently incapacitated requestor.
// Queue connection is "sync" in tests, so the lifecycle mail job runs inline.
// Helpers are rep-prefixed because Pest helper functions are global.

const REP_CLOSURE = 'request_closed_unable_to_process';

beforeEach(function () {
    Mail::fake();
    Cache::flush();
    Storage::fake('local');
    config([
        'mail.default'                                              => 'array',
        'notification_mail.enabled'                                 => true,
        'notification_mail.quota.daily_limit'                       => 500,
        'notification_mail.representative.proof_disk'               => 'local',
        'notification_mail.representative.require_distinct_verifier' => true,
        'webpush.enabled'                                           => false,
    ]);
});

// ── Fixtures ────────────────────────────────────────────────────────────

function repOwner(array $overrides = []): SystemUser
{
    $user = SystemUser::factory()->create($overrides + ['role_id' => SystemUser::ROLE_STUDENT, 'status' => 'Activated']);
    StudentProfile::factory()->create(['user_id' => $user->user_id, 'first_name' => 'Juan', 'last_name' => 'Dela Cruz']);

    return $user;
}

function repAdmin(): SystemUser
{
    $user = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN, 'status' => 'Activated']);
    grantFullDashboardAccess($user);

    return $user;
}

function repSuperAdmin(): SystemUser
{
    return SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN, 'status' => 'Activated']);
}

function repRequest(SystemUser $owner, ?SystemUser $closedBy = null, ?string $reason = 'other'): DocumentRequest
{
    return DocumentRequest::factory()->create([
        'user_id'        => $owner->user_id,
        'status_id'      => RequestStatusEnum::ClosedUnableToProcess->value,
        'closure_reason' => $reason,
        'closed_by'      => $closedBy?->user_id,
    ]);
}

function repFlag(SystemUser $user, string $status = 'deceased'): RequestorStatusFlag
{
    return RequestorStatusFlag::create(['user_id' => $user->user_id, 'status' => $status, 'flagged_by' => 1]);
}

function repRow(DocumentRequest $request, string $status, string $email = 'rep@example.org', array $overrides = []): RequestRepresentative
{
    return RequestRepresentative::create($overrides + [
        'request_id'        => $request->request_id,
        'full_name'         => 'Maria Dela Cruz',
        'email'             => $email,
        'relationship'      => 'parent',
        'proofs'            => array_map(fn ($k) => ['kind' => $k, 'disk' => 'local', 'path' => "p/{$k}.bin", 'mime' => 'application/pdf', 'size' => 1, 'sha256' => 'x'], RequestRepresentative::REQUIRED_PROOFS),
        'status'            => $status,
        'active_request_id' => $status === RequestRepresentative::STATUS_REVOKED ? null : $request->request_id,
        'created_by'        => 9999,
    ]);
}

function repType(string $trigger): void
{
    $type = NotificationType::firstOrNew(['trigger_event' => $trigger]);
    if (! $type->exists) {
        $type->notification_type_id = ((int) DB::table('notification_types')->max('notification_type_id')) + 1;
        $type->title                = $trigger;
        $type->message_template     = 'Message for :request_id';
        $type->audience             = 'student_alumni';
        $type->is_active            = true;
    }
    $type->send_email = true;
    $type->save();
}

/** Fires the closure notification, which queues (and, in tests, runs) the mail job. */
function repSendClosure(SystemUser $owner, DocumentRequest $request): void
{
    repType(REP_CLOSURE);
    app(NotificationService::class)->send($owner, REP_CLOSURE, [
        'request_id'     => $request->request_id,
        'closure_reason' => 'Requestor deceased',
    ], $request->request_id);
}

function repText(RequestLifecycleMail $mail): string
{
    return html_entity_decode($mail->render(), ENT_QUOTES);
}

function repFiles(): array
{
    return [
        RequestRepresentative::PROOF_REPRESENTATIVE_ID => UploadedFile::fake()->createWithContent('id.pdf', '%PDF-1.4 ID-SCAN-BYTES'),
        RequestRepresentative::PROOF_RELATIONSHIP      => UploadedFile::fake()->createWithContent('rel.pdf', '%PDF-1.4 BIRTH-CERT-BYTES'),
        RequestRepresentative::PROOF_EVENT             => UploadedFile::fake()->createWithContent('death.pdf', '%PDF-1.4 DEATH-CERT-BYTES'),
    ];
}

function repPayload(array $overrides = []): array
{
    return $overrides + [
        'full_name'    => 'Maria Dela Cruz',
        'email'        => 'Rep@Example.org',
        'relationship' => 'parent',
    ] + repFiles();
}

// ── Routing: the three §3.6 paths ───────────────────────────────────────

test('normal closure still goes to the requestor own address', function () {
    $owner   = repOwner();
    $request = repRequest($owner, null, ClosureReasonEnum::Other->value);

    repSendClosure($owner, $request);

    Mail::assertSent(RequestLifecycleMail::class, 1);
    Mail::assertSent(RequestLifecycleMail::class, fn ($m) => $m->hasTo($owner->email) && $m->studentName === null);
    expect(NotificationDispatchLog::where('reason', NotificationDispatchLog::REASON_SENT_ON_FILE)->count())->toBe(0);
});

test('a verified representative receives the closure notice and the requestor address receives nothing', function () {
    $admin   = repAdmin();
    $owner   = repOwner();
    $request = repRequest($owner, $admin, ClosureReasonEnum::RequestorDeceased->value);
    repFlag($owner);
    repRow($request, RequestRepresentative::STATUS_VERIFIED, 'rep@example.org');

    repSendClosure($owner, $request);

    Mail::assertSent(RequestLifecycleMail::class, 1);
    Mail::assertSent(RequestLifecycleMail::class, fn ($m) => $m->hasTo('rep@example.org') && ! $m->hasTo($owner->email));

    $sent = Mail::sent(RequestLifecycleMail::class)->first();
    expect(repText($sent))
        ->toContain("We regret to inform you that the request filed for Juan Dela Cruz could not be completed: Requestor deceased. Please contact the Registrar's Office for assistance regarding records and accounts.")
        ->not->toContain('Sign in to PUPT-Registrar');

    $row = NotificationDispatchLog::where('channel', 'mail')->where('status', 'sent')->first();
    expect($row->reason)->toBe(NotificationDispatchLog::REASON_VIA_REPRESENTATIVE);
});

test('a deceased requestor whose account is deactivated still routes to the verified representative', function () {
    $owner   = repOwner(['status' => 'Deactivated']);
    $request = repRequest($owner, null, ClosureReasonEnum::RequestorDeceased->value);
    repFlag($owner);
    repRow($request, RequestRepresentative::STATUS_VERIFIED, 'rep@example.org');

    repSendClosure($owner, $request);

    Mail::assertSent(RequestLifecycleMail::class, fn ($m) => $m->hasTo('rep@example.org'));
});

test('with no representative the closure email is suppressed and a sent-on-file audit entry is written once', function () {
    $admin   = repAdmin();
    $owner   = repOwner();
    $request = repRequest($owner, $admin, ClosureReasonEnum::RequestorDeceased->value);
    repFlag($owner);

    repSendClosure($owner, $request);

    Mail::assertNothingSent();
    $log = NotificationDispatchLog::where('channel', 'mail')->first();
    expect($log->status)->toBe('skipped')->and($log->reason)->toBe(NotificationDispatchLog::REASON_SENT_ON_FILE);

    $audit = AuditLog::where('action', AuditLog::ACTION_CLOSURE_NOTICE_SENT_ON_FILE)->get();
    expect($audit)->toHaveCount(1)
        ->and($audit->first()->user_id)->toBe($admin->user_id)        // the closer, never the requestor
        ->and($audit->first()->target_user_id)->toBe($owner->user_id)
        ->and($audit->first()->metadata['request_id'])->toBe($request->request_id);

    // The job running again for the same notification must not write a second entry.
    $notificationId = \App\Models\Notification::where('request_id', $request->request_id)->value('id');
    app(MailDispatcher::class)->deliver($notificationId);
    expect(AuditLog::where('action', AuditLog::ACTION_CLOSURE_NOTICE_SENT_ON_FILE)->count())->toBe(1);
});

test('a pending or revoked representative counts as no representative', function (string $status) {
    $owner   = repOwner();
    $request = repRequest($owner, repAdmin(), ClosureReasonEnum::RequestorDeceased->value);
    repFlag($owner);
    repRow($request, $status);

    repSendClosure($owner, $request);

    Mail::assertNothingSent();
    expect(AuditLog::where('action', AuditLog::ACTION_CLOSURE_NOTICE_SENT_ON_FILE)->count())->toBe(1);
})->with([RequestRepresentative::STATUS_PENDING, RequestRepresentative::STATUS_REVOKED]);

test('a closure for a deceased requestor is never emailed to their own address even if nobody flagged the account', function () {
    $owner   = repOwner();
    $request = repRequest($owner, repAdmin(), ClosureReasonEnum::RequestorIncapacitated->value);

    repSendClosure($owner, $request);

    Mail::assertNothingSent();
    expect(AuditLog::where('action', AuditLog::ACTION_CLOSURE_NOTICE_SENT_ON_FILE)->count())->toBe(1);
});

test('every other lifecycle email to a flagged requestor is suppressed, even with a verified representative', function () {
    $owner   = repOwner();
    $request = repRequest($owner);
    repFlag($owner);
    repRow($request, RequestRepresentative::STATUS_VERIFIED);
    repType('ready_to_claim');

    app(NotificationService::class)->send($owner, 'ready_to_claim', ['request_id' => $request->request_id], $request->request_id);

    Mail::assertNothingSent();
    $log = NotificationDispatchLog::where('channel', 'mail')->first();
    expect($log->status)->toBe('skipped')->and($log->reason)->toBe(NotificationDispatchLog::REASON_REQUESTOR_FLAGGED);
});

test('the in-app notice is still created for a flagged requestor, it is the record', function () {
    $owner   = repOwner();
    $request = repRequest($owner, repAdmin(), ClosureReasonEnum::RequestorDeceased->value);
    repFlag($owner);

    repSendClosure($owner, $request);

    expect(\App\Models\Notification::where('notifiable_id', $owner->user_id)->where('request_id', $request->request_id)->count())->toBe(1);
});

test('representative wording appears only in the representative variant of the closure mail', function () {
    $normal = repText(new RequestLifecycleMail('request_closed_unable_to_process', 'Transcript of Records', false, null, 'Records not found'));
    $rep    = repText(new RequestLifecycleMail('request_closed_unable_to_process', 'Transcript of Records', false, null, 'Records not found', 'Juan Dela Cruz'));

    expect($normal)->toContain('the request for Transcript of Records could not be completed')->toContain('Sign in to PUPT-Registrar')
        ->and($rep)->toContain('the request filed for Juan Dela Cruz')->not->toContain('Transcript of Records');
});

// ── Adding a representative ─────────────────────────────────────────────

test('staff add a pending representative; details are encrypted and proofs are private', function () {
    $admin = repAdmin();
    $owner = repOwner();
    $request = repRequest($owner);
    repFlag($owner);
    Sanctum::actingAs($admin);

    $response = $this->postJson("/api/v1/document-requests/{$request->request_id}/representative", repPayload());

    $response->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.email', null)                       // staff never see the full address back
        ->assertJsonPath('data.email_masked', 'r***@example.org')
        ->assertJsonMissingPath('data.proofs.0.path');

    $raw = DB::table('request_representatives')->first();
    expect($raw->email)->not->toContain('example.org')
        ->and($raw->full_name)->not->toContain('Maria')
        ->and($raw->active_request_id)->toBe($request->request_id);

    $model = RequestRepresentative::first();
    expect($model->email)->toBe('rep@example.org');                // normalised to lower case

    foreach ($model->proofs as $proof) {
        Storage::disk('local')->assertExists($proof['path']);
        expect(Storage::disk('local')->get($proof['path']))->not->toContain('%PDF');   // encrypted at rest
        expect($proof['path'])->not->toContain('id.pdf');                              // random name
    }

    $audit = AuditLog::where('action', AuditLog::ACTION_REPRESENTATIVE_ADDED)->first();
    expect($audit->target_user_id)->toBe($owner->user_id)
        ->and(json_encode($audit->getAttributes()))->not->toContain('rep@example.org')->not->toContain('Maria');
});

test('a representative cannot be added until the requestor is flagged', function () {
    $owner = repOwner();
    $request = repRequest($owner);
    Sanctum::actingAs(repAdmin());

    $this->postJson("/api/v1/document-requests/{$request->request_id}/representative", repPayload())->assertStatus(422);
    expect(RequestRepresentative::count())->toBe(0);
});

test('the representative email must differ from the requestor own address', function () {
    $owner = repOwner(['email' => 'student@example.org']);
    $request = repRequest($owner);
    repFlag($owner);
    Sanctum::actingAs(repAdmin());

    $this->postJson("/api/v1/document-requests/{$request->request_id}/representative", repPayload(['email' => 'Student@Example.org']))
        ->assertStatus(422);
});

test('all three proofs are required and only pdf or images under the size limit are accepted', function () {
    $owner = repOwner();
    $request = repRequest($owner);
    repFlag($owner);
    Sanctum::actingAs(repAdmin());
    $url = "/api/v1/document-requests/{$request->request_id}/representative";

    $payload = repPayload();
    unset($payload[RequestRepresentative::PROOF_EVENT]);
    $this->postJson($url, $payload)->assertStatus(422)->assertJsonValidationErrors(RequestRepresentative::PROOF_EVENT);

    $this->postJson($url, repPayload([RequestRepresentative::PROOF_EVENT => UploadedFile::fake()->createWithContent('x.txt', 'plain text')]))
        ->assertStatus(422)->assertJsonValidationErrors(RequestRepresentative::PROOF_EVENT);

    config(['notification_mail.representative.max_proof_kb' => 1]);
    $big = UploadedFile::fake()->createWithContent('big.pdf', '%PDF-1.4 ' . str_repeat('A', 5000));
    $this->postJson($url, repPayload([RequestRepresentative::PROOF_RELATIONSHIP => $big]))
        ->assertStatus(422)->assertJsonValidationErrors(RequestRepresentative::PROOF_RELATIONSHIP);

    expect(RequestRepresentative::count())->toBe(0)->and(Storage::disk('local')->allFiles())->toBe([]);
});

test('only one live representative per request, and a revoked one can be replaced', function () {
    $owner = repOwner();
    $request = repRequest($owner);
    repFlag($owner);
    Sanctum::actingAs(repAdmin());
    $url = "/api/v1/document-requests/{$request->request_id}/representative";

    $this->postJson($url, repPayload())->assertCreated();
    $this->postJson($url, repPayload(['email' => 'other@example.org']))->assertStatus(409);
    expect(RequestRepresentative::count())->toBe(1)->and(count(Storage::disk('local')->allFiles()))->toBe(3);  // the failed attempt left no files

    Sanctum::actingAs(repSuperAdmin());
    $this->postJson('/api/v1/representatives/' . RequestRepresentative::first()->id . '/revoke', ['reason' => 'Wrong person entered'])->assertOk();

    Sanctum::actingAs(repAdmin());
    $this->postJson($url, repPayload(['email' => 'other@example.org']))->assertCreated();
    expect(RequestRepresentative::count())->toBe(2);
});

test('a public proof disk is refused', function () {
    config(['notification_mail.representative.proof_disk' => 'public']);
    $owner = repOwner();
    $request = repRequest($owner);
    repFlag($owner);
    Sanctum::actingAs(repAdmin());

    $this->postJson("/api/v1/document-requests/{$request->request_id}/representative", repPayload())->assertStatus(500);
    expect(RequestRepresentative::count())->toBe(0);
});

// ── Authorization ───────────────────────────────────────────────────────

test('requestors can reach none of the representative or flag endpoints', function () {
    $student = repOwner();
    $other   = repOwner();
    $request = repRequest($other);
    $rep     = repRow($request, 'pending');
    Sanctum::actingAs($student);

    $this->postJson("/api/v1/document-requests/{$request->request_id}/representative", repPayload())->assertForbidden();
    $this->getJson("/api/v1/document-requests/{$request->request_id}/representative")->assertForbidden();
    $this->postJson("/api/v1/requestors/{$other->user_id}/status-flag", ['status' => 'deceased'])->assertForbidden();
    $this->deleteJson("/api/v1/requestors/{$other->user_id}/status-flag", ['reason' => 'mistake entered'])->assertForbidden();
    $this->getJson('/api/v1/representatives')->assertForbidden();
    $this->postJson("/api/v1/representatives/{$rep->id}/verify")->assertForbidden();
    $this->getJson("/api/v1/representatives/{$rep->id}/proofs/event_proof")->assertForbidden();
});

test('an admin can add and view a representative but cannot verify, revoke, list or read proofs', function () {
    $owner = repOwner();
    $request = repRequest($owner);
    $rep = repRow($request, 'pending');
    Sanctum::actingAs(repAdmin());

    $this->getJson("/api/v1/document-requests/{$request->request_id}/representative")
        ->assertOk()->assertJsonPath('data.id', $rep->id)->assertJsonPath('data.email', null);

    $this->postJson("/api/v1/representatives/{$rep->id}/verify")->assertForbidden();
    $this->postJson("/api/v1/representatives/{$rep->id}/revoke", ['reason' => 'not allowed'])->assertForbidden();
    $this->getJson('/api/v1/representatives')->assertForbidden();
    $this->getJson("/api/v1/representatives/{$rep->id}/proofs/event_proof")->assertForbidden();
    expect($rep->fresh()->status)->toBe('pending');
});

// ── Verification ────────────────────────────────────────────────────────

test('a Super Admin verifies a representative added by staff and the email then routes', function () {
    $admin = repAdmin();
    $owner = repOwner();
    $request = repRequest($owner, $admin, ClosureReasonEnum::RequestorDeceased->value);
    repFlag($owner);
    $rep = repRow($request, 'pending', 'rep@example.org', ['created_by' => $admin->user_id]);
    Sanctum::actingAs($sa = repSuperAdmin());

    $this->postJson("/api/v1/representatives/{$rep->id}/verify")
        ->assertOk()->assertJsonPath('data.status', 'verified')->assertJsonPath('data.email', 'rep@example.org');

    expect($rep->fresh())->verified_by->toBe($sa->user_id)
        ->and(AuditLog::where('action', AuditLog::ACTION_REPRESENTATIVE_VERIFIED)->count())->toBe(1);

    repSendClosure($owner, $request);
    Mail::assertSent(RequestLifecycleMail::class, fn ($m) => $m->hasTo('rep@example.org'));
});

test('whoever added a representative cannot verify it unless the office turns that rule off', function () {
    $sa = repSuperAdmin();
    $owner = repOwner();
    $request = repRequest($owner);
    repFlag($owner);
    $rep = repRow($request, 'pending', 'rep@example.org', ['created_by' => $sa->user_id]);
    Sanctum::actingAs($sa);

    $this->postJson("/api/v1/representatives/{$rep->id}/verify")->assertForbidden();
    expect($rep->fresh()->status)->toBe('pending');

    config(['notification_mail.representative.require_distinct_verifier' => false]);
    $this->postJson("/api/v1/representatives/{$rep->id}/verify")->assertOk();
});

test('verification is refused without all proofs, after the flag is lifted, or twice', function () {
    $owner = repOwner();
    $request = repRequest($owner);
    $flag = repFlag($owner);
    Sanctum::actingAs(repSuperAdmin());

    $noProofs = repRow($request, 'pending', 'a@example.org', ['proofs' => []]);
    $this->postJson("/api/v1/representatives/{$noProofs->id}/verify")->assertStatus(422);
    $noProofs->update(['status' => 'revoked', 'active_request_id' => null]);

    $rep = repRow($request, 'pending', 'b@example.org');
    $flag->update(['lifted_at' => now()]);
    $this->postJson("/api/v1/representatives/{$rep->id}/verify")->assertStatus(422);

    $flag->update(['lifted_at' => null]);
    $this->postJson("/api/v1/representatives/{$rep->id}/verify")->assertOk();
    $this->postJson("/api/v1/representatives/{$rep->id}/verify")->assertStatus(409);
});

test('the Super Admin sees the full address and the pending queue, and revoking stops mail', function () {
    $owner = repOwner();
    $request = repRequest($owner, repAdmin(), ClosureReasonEnum::RequestorDeceased->value);
    repFlag($owner);
    $rep = repRow($request, 'verified', 'rep@example.org');
    $pending = repRow(repRequest(repOwner()), 'pending', 'later@example.org');
    Sanctum::actingAs(repSuperAdmin());

    $this->getJson('/api/v1/representatives')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $pending->id);
    $this->postJson("/api/v1/representatives/{$rep->id}/revoke", ['reason' => 'Authority withdrawn'])
        ->assertOk()->assertJsonPath('data.status', 'revoked');
    expect($rep->fresh()->active_request_id)->toBeNull();

    repSendClosure($owner, $request);
    Mail::assertNothingSent();
});

// ── Proof documents ─────────────────────────────────────────────────────

test('a Super Admin can read a proof, it decrypts to the original bytes, and the view is audited', function () {
    $owner = repOwner();
    $request = repRequest($owner);
    repFlag($owner);
    Sanctum::actingAs(repAdmin());
    $this->postJson("/api/v1/document-requests/{$request->request_id}/representative", repPayload())->assertCreated();
    $rep = RequestRepresentative::first();

    Sanctum::actingAs($sa = repSuperAdmin());
    $response = $this->getJson("/api/v1/representatives/{$rep->id}/proofs/event_proof");

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->getContent())->toBe('%PDF-1.4 DEATH-CERT-BYTES')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');

    $audit = AuditLog::where('action', AuditLog::ACTION_REPRESENTATIVE_PROOF_VIEWED)->first();
    expect($audit->user_id)->toBe($sa->user_id)->and($audit->metadata['kind'])->toBe('event_proof');

    $this->getJson("/api/v1/representatives/{$rep->id}/proofs/not_a_kind")->assertNotFound();
});

// ── Flagging a requestor ────────────────────────────────────────────────

test('staff can flag a requestor, only requestor accounts can be flagged, and the flag is audited', function () {
    $owner = repOwner();
    $admin = repAdmin();
    Sanctum::actingAs($admin);

    $this->postJson("/api/v1/requestors/{$owner->user_id}/status-flag", ['status' => 'incapacitated', 'note' => 'Medical certificate seen'])
        ->assertOk()->assertJsonPath('active', true)->assertJsonPath('status', 'incapacitated');
    expect(RequestorStatusFlag::active()->where('user_id', $owner->user_id)->exists())->toBeTrue()
        ->and(AuditLog::where('action', AuditLog::ACTION_REQUESTOR_FLAGGED)->first()->target_user_id)->toBe($owner->user_id);

    $this->postJson("/api/v1/requestors/{$owner->user_id}/status-flag", ['status' => 'not-a-status'])->assertStatus(422);
    $this->postJson("/api/v1/requestors/{$admin->user_id}/status-flag", ['status' => 'deceased'])->assertStatus(422);
});

test('lifting a flag is Super Admin only, restores normal routing and revokes the representatives', function () {
    $owner = repOwner();
    $request = repRequest($owner);
    repFlag($owner);
    $verified = repRow($request, 'verified');
    $url = "/api/v1/requestors/{$owner->user_id}/status-flag";

    Sanctum::actingAs(repAdmin());
    $this->deleteJson($url, ['reason' => 'entered in error'])->assertForbidden();

    Sanctum::actingAs(repSuperAdmin());
    $this->deleteJson($url, ['reason' => 'entered in error'])->assertOk()->assertJsonPath('active', false);
    $this->deleteJson($url, ['reason' => 'entered in error'])->assertNotFound();

    expect(RequestorStatusFlag::active()->where('user_id', $owner->user_id)->exists())->toBeFalse()
        ->and($verified->fresh()->status)->toBe('revoked')
        ->and(AuditLog::where('action', AuditLog::ACTION_REQUESTOR_FLAG_LIFTED)->count())->toBe(1)
        ->and(AuditLog::where('action', AuditLog::ACTION_REPRESENTATIVE_REVOKED)->count())->toBe(1);

    repType('ready_to_claim');
    app(NotificationService::class)->send($owner, 'ready_to_claim', ['request_id' => $request->request_id], $request->request_id);
    Mail::assertSent(RequestLifecycleMail::class, fn ($m) => $m->hasTo($owner->email));
});