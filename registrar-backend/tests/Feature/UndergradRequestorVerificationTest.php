<?php

use App\Contracts\UndergradEnrollmentLookupClientInterface;
use App\DTOs\Ogos\OgosEnrollmentLookupResult;
use App\DTOs\Ogos\OgosStudentDTO;
use App\Enums\UndergradRequestorVerificationStatusEnum;
use App\Mail\UndergradRequestorDecisionMail;
use App\Models\AuditLog;
use App\Models\StudentAcademicRecord;
use App\Models\StudentProfile;
use App\Models\SystemUser;
use App\Models\UndergradRequestorProfile;
use App\Models\UndergradRequestorVerification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Undergrad Requestor Registration — Phase 4 feature tests
|--------------------------------------------------------------------------
| Covers the Admin verification queue, both decisions, the advisory
| checks' three-state behaviour (D6), and both retention sweeps (D9).
|
| The OGOS lookup is ALWAYS faked here. Binding the interface rather than
| mocking the HTTP layer is the point of
| UndergradEnrollmentLookupClientInterface existing separately from
| OgosStudentService — see that interface's docblock.
|--------------------------------------------------------------------------
*/

// ── Helpers ──────────────────────────────────────────────────────────

function urvReviewer(): SystemUser
{
    // Super Admin: hasModuleAccess() short-circuits to true for role 4,
    // so these tests exercise the verification workflow itself rather
    // than re-testing policy attachment, which has its own coverage.
    return SystemUser::factory()->create([
        'role_id' => SystemUser::ROLE_SUPER_ADMIN,
        'status'  => 'Activated',
    ]);
}

/**
 * A complete, email-verified, pending submission — the state the Admin
 * queue is designed to show.
 */
function urvSubmission(array $userOverrides = [], array $profileOverrides = []): SystemUser
{
    $user = SystemUser::factory()->create(array_merge([
        'role_id'            => SystemUser::ROLE_UNDERGRAD_REQUESTOR,
        'status'             => 'Pending Verification',
        'idp_user_id'        => null,
        'password'           => null,
        'local_auth_enabled' => 0,
        'pending_expires_at' => now()->addDays(14),
    ], $userOverrides));

    UndergradRequestorProfile::factory()->create(array_merge([
        'user_id'           => $user->user_id,
        'student_number'    => '2019-00123-TG-0',
        'email_verified_at' => now(),
    ], $profileOverrides));

    UndergradRequestorVerification::factory()->create([
        'user_id' => $user->user_id,
        'status'  => UndergradRequestorVerificationStatusEnum::Pending,
    ]);

    return $user;
}

/** Bind a fake OGOS lookup returning a fixed result. */
function urvFakeLookup(OgosEnrollmentLookupResult $result): void
{
    app()->instance(
        UndergradEnrollmentLookupClientInterface::class,
        new class ($result) implements UndergradEnrollmentLookupClientInterface {
            public function __construct(private OgosEnrollmentLookupResult $result) {}

            public function lookup(?string $studentNumber, ?string $email): OgosEnrollmentLookupResult
            {
                return $this->result;
            }
        }
    );
}

beforeEach(function () {
    Mail::fake();
    urvFakeLookup(OgosEnrollmentLookupResult::noMatch());
});

// ═══════════════════════════════════════════════════════════════════════
// Queue
// ═══════════════════════════════════════════════════════════════════════

test('the queue lists email-verified pending submissions oldest first', function () {
    $older = urvSubmission([], ['created_at' => now()->subDays(5)]);
    $newer = urvSubmission([], ['created_at' => now()->subDay()]);

    Sanctum::actingAs(urvReviewer());

    $response = $this->getJson('/api/admin/undergrad-requestors?status=pending')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    // Oldest first: this is an SLA-bearing work queue, not a newsfeed.
    expect($response->json('data.0.user_id'))->toBe($older->user_id);
    expect($response->json('data.1.user_id'))->toBe($newer->user_id);
});

test('a submission whose email is unconfirmed never appears in the queue (D10)', function () {
    urvSubmission([], ['email_verified_at' => null]);

    Sanctum::actingAs(urvReviewer());

    $this->getJson('/api/admin/undergrad-requestors?status=pending')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('an unknown status filter is rejected rather than silently defaulting', function () {
    Sanctum::actingAs(urvReviewer());

    $this->getJson('/api/admin/undergrad-requestors?status=bogus')
        ->assertStatus(422);
});

test('a non-admin cannot reach the queue at all', function () {
    $student = SystemUser::factory()->create([
        'role_id' => SystemUser::ROLE_STUDENT,
        'status'  => 'Activated',
    ]);

    Sanctum::actingAs($student);

    $this->getJson('/api/admin/undergrad-requestors')->assertForbidden();
});

// ═══════════════════════════════════════════════════════════════════════
// Advisory checks (D6) — all three OGOS states
// ═══════════════════════════════════════════════════════════════════════

test('opening a record persists both advisory checks', function () {
    $user = urvSubmission();

    $profile = StudentProfile::factory()->create();
    StudentAcademicRecord::factory()->create([
        'student_profile_id' => $profile->student_profile_id,
        'student_number'     => '2019-00123-TG-0',
    ]);

    Sanctum::actingAs(urvReviewer());

    $this->getJson("/api/admin/undergrad-requestors/{$user->user_id}")
        ->assertOk()
        ->assertJsonPath('advisory_checks.local_records_check.match_found', true)
        ->assertJsonPath('advisory_checks.ogos_enrollment_check.performed', true)
        ->assertJsonPath('advisory_checks.ogos_enrollment_check.match_found', false)
        ->assertJsonPath('advisory_checks.ogos_enrollment_check.severity', 'info');

    $verification = UndergradRequestorVerification::where('user_id', $user->user_id)->first();
    expect($verification->local_match_found)->toBeTrue();
    expect($verification->matched_student_profile_id)->toBe($profile->student_profile_id);
    expect($verification->ogos_lookup_performed_at)->not->toBeNull();
    expect($verification->ogos_match_found)->toBeFalse();
});

test('an OGOS hit is surfaced as a warning, not a confirmation', function () {
    $user = urvSubmission();

    urvFakeLookup(OgosEnrollmentLookupResult::match(
        OgosStudentDTO::fromArray([
            'studentNumber' => '2019-00123-TG-0',
            'email'         => $user->email,
            'firstName'     => 'Juan',
            'lastName'      => 'Dela Cruz',
        ])
    ));

    Sanctum::actingAs(urvReviewer());

    $response = $this->getJson("/api/admin/undergrad-requestors/{$user->user_id}")
        ->assertOk()
        ->assertJsonPath('advisory_checks.ogos_enrollment_check.match_found', true)
        ->assertJsonPath('advisory_checks.ogos_enrollment_check.severity', 'warning');

    expect($response->json('advisory_checks.ogos_enrollment_check.interpretation'))
        ->toContain('CURRENTLY ENROLLED');
});

test('an unreachable OGOS is recorded as "not performed", never as "no match"', function () {
    $user = urvSubmission();

    urvFakeLookup(OgosEnrollmentLookupResult::unavailable('OGOS was unreachable at review time.'));

    Sanctum::actingAs(urvReviewer());

    $this->getJson("/api/admin/undergrad-requestors/{$user->user_id}")
        ->assertOk()
        ->assertJsonPath('advisory_checks.ogos_enrollment_check.performed', false)
        ->assertJsonPath('advisory_checks.ogos_enrollment_check.severity', 'unavailable');

    // The load-bearing distinction: NULL (never checked), not false
    // (checked, found nothing).
    $verification = UndergradRequestorVerification::where('user_id', $user->user_id)->first();
    expect($verification->ogos_lookup_performed_at)->toBeNull();
    expect($verification->ogos_match_found)->toBeNull();
});

test('OGOS being unreachable does not block a decision', function () {
    $user = urvSubmission();
    urvFakeLookup(OgosEnrollmentLookupResult::unavailable('down'));

    Sanctum::actingAs(urvReviewer());

    $this->postJson("/api/admin/undergrad-requestors/{$user->user_id}/approve")->assertOk();

    expect($user->fresh()->status)->toBe('Pending Activation');
});

test('duplicate student numbers are flagged, never blocked', function () {
    $first = urvSubmission();
    urvSubmission([], ['student_number' => '2019-00123-TG-0']);

    Sanctum::actingAs(urvReviewer());

    $this->getJson("/api/admin/undergrad-requestors/{$first->user_id}")
        ->assertOk()
        ->assertJsonPath('advisory_checks.duplicate_student_number.count', 1);
});

// ═══════════════════════════════════════════════════════════════════════
// Approve
// ═══════════════════════════════════════════════════════════════════════

test('approving moves the account to Pending Activation and clears the abandonment window', function () {
    $user     = urvSubmission();
    $reviewer = urvReviewer();

    Sanctum::actingAs($reviewer);

    $this->postJson("/api/admin/undergrad-requestors/{$user->user_id}/approve")->assertOk();

    $user->refresh();
    // NOT 'Activated': only a real IDP login can link idp_user_id (D4).
    expect($user->status)->toBe('Pending Activation');
    expect($user->idp_user_id)->toBeNull();
    // Someone acted, so the abandonment window no longer applies —
    // otherwise the nightly sweep would expire an approved account.
    expect($user->pending_expires_at)->toBeNull();

    $verification = UndergradRequestorVerification::where('user_id', $user->user_id)->first();
    expect($verification->status)->toBe(UndergradRequestorVerificationStatusEnum::Approved);
    expect($verification->reviewed_by)->toBe($reviewer->user_id);
    expect($verification->reviewed_at)->not->toBeNull();

    $this->assertDatabaseHas('audit_logs', [
        'action'         => AuditLog::ACTION_UNDERGRAD_REQUESTOR_APPROVED,
        'user_id'        => $reviewer->user_id,
        'target_user_id' => $user->user_id,
    ]);

    Mail::assertQueued(UndergradRequestorDecisionMail::class);
    $this->assertDatabaseHas('notifications', ['notifiable_id' => $user->user_id]);
});

test('an approved requestor then auto-activates on first IDP login', function () {
    $user = urvSubmission();

    Sanctum::actingAs(urvReviewer());
    $this->postJson("/api/admin/undergrad-requestors/{$user->user_id}/approve")->assertOk();

    // The Phase 3 handoff: matched by email, no prior idp_user_id.
    $result = app(\App\Services\Sso\UserProvisioningService::class)->provision([
        'id'    => 'idp-approved-undergrad',
        'email' => $user->email,
    ], \Illuminate\Http\Request::create('/api/auth/callback', 'POST'));

    $user->refresh();
    expect($user->status)->toBe('Activated');
    expect($user->idp_user_id)->toBe('idp-approved-undergrad');
    expect($result->user->user_id)->toBe($user->user_id);
});

// ═══════════════════════════════════════════════════════════════════════
// Reject
// ═══════════════════════════════════════════════════════════════════════

test('rejecting requires a substantive reason', function () {
    $user = urvSubmission();
    Sanctum::actingAs(urvReviewer());

    $this->postJson("/api/admin/undergrad-requestors/{$user->user_id}/reject", [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('rejection_reason');

    $this->postJson("/api/admin/undergrad-requestors/{$user->user_id}/reject", ['rejection_reason' => 'no'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('rejection_reason');

    expect($user->fresh()->status)->toBe('Pending Verification');
});

test('rejecting marks the account Rejected and records the reason', function () {
    $user     = urvSubmission();
    $reviewer = urvReviewer();

    Sanctum::actingAs($reviewer);

    $this->postJson("/api/admin/undergrad-requestors/{$user->user_id}/reject", [
        'rejection_reason' => 'Student number does not match any historical record and no supporting context given.',
    ])->assertOk();

    $user->refresh();
    expect($user->status)->toBe('Rejected');
    expect($user->pending_expires_at)->toBeNull();

    $verification = UndergradRequestorVerification::where('user_id', $user->user_id)->first();
    expect($verification->status)->toBe(UndergradRequestorVerificationStatusEnum::Rejected);
    expect($verification->rejection_reason)->not->toBeNull();

    $this->assertDatabaseHas('audit_logs', [
        'action'         => AuditLog::ACTION_UNDERGRAD_REQUESTOR_REJECTED,
        'user_id'        => $reviewer->user_id,
        'target_user_id' => $user->user_id,
    ]);
});

// ═══════════════════════════════════════════════════════════════════════
// Decision guards
// ═══════════════════════════════════════════════════════════════════════

test('a submission cannot be decided twice', function () {
    $user = urvSubmission();
    Sanctum::actingAs(urvReviewer());

    $this->postJson("/api/admin/undergrad-requestors/{$user->user_id}/approve")->assertOk();

    $this->postJson("/api/admin/undergrad-requestors/{$user->user_id}/reject", [
        'rejection_reason' => 'Changed my mind after approving.',
    ])->assertStatus(422);
});

test('an unconfirmed-email submission cannot be approved by guessing its URL (D10)', function () {
    $user = urvSubmission([], ['email_verified_at' => null]);
    Sanctum::actingAs(urvReviewer());

    $this->postJson("/api/admin/undergrad-requestors/{$user->user_id}/approve")->assertStatus(422);

    expect($user->fresh()->status)->toBe('Pending Verification');
});

test('a submission past its abandonment window cannot be decided before the sweep runs', function () {
    // Mirrors AccessRequestService's QA #11 guard: the sweep is nightly,
    // so the stored status lags reality for up to ~24h.
    $user = urvSubmission(['pending_expires_at' => now()->subDay()]);
    Sanctum::actingAs(urvReviewer());

    $this->postJson("/api/admin/undergrad-requestors/{$user->user_id}/approve")->assertStatus(422);
});

test('an account of another role is not reviewable through this endpoint', function () {
    $admin = SystemUser::factory()->create([
        'role_id' => SystemUser::ROLE_ADMIN,
        'status'  => 'Activated',
    ]);

    Sanctum::actingAs(urvReviewer());

    $this->getJson("/api/admin/undergrad-requestors/{$admin->user_id}")->assertStatus(422);
});

// ═══════════════════════════════════════════════════════════════════════
// Retention sweeps (D9)
// ═══════════════════════════════════════════════════════════════════════

test('the abandonment sweep expires un-actioned submissions without deleting them', function () {
    $stale = urvSubmission(['pending_expires_at' => now()->subDay()]);
    $fresh = urvSubmission();

    $this->artisan('provisioning:expire-stale')->assertExitCode(0);

    expect($stale->fresh()->status)->toBe('Expired');
    expect($fresh->fresh()->status)->toBe('Pending Verification');

    // Distinct from ACTION_ADMIN_EXPIRED: an abandoned public onboarding
    // submission is a different event from a lapsed staff invite.
    $this->assertDatabaseHas('audit_logs', [
        'action'         => AuditLog::ACTION_UNDERGRAD_REQUESTOR_EXPIRED,
        'target_user_id' => $stale->user_id,
    ]);
});

test('the purge disposes of rejected PII while leaving the audit trail intact', function () {
    $user = urvSubmission();
    Sanctum::actingAs(urvReviewer());

    $this->postJson("/api/admin/undergrad-requestors/{$user->user_id}/reject", [
        'rejection_reason' => 'Could not establish prior enrollment from the information provided.',
    ])->assertOk();

    $originalEmail = $user->fresh()->email;

    // Age the decision past the retention window.
    UndergradRequestorVerification::where('user_id', $user->user_id)
        ->update(['reviewed_at' => now()->subDays(91)]);

    $this->artisan('undergrad-requestors:purge-rejected-pii')->assertExitCode(0);

    // Self-declared PII: gone.
    $this->assertDatabaseMissing('undergrad_requestor_profiles', ['user_id' => $user->user_id]);

    $verification = UndergradRequestorVerification::where('user_id', $user->user_id)->first();
    expect($verification->rejection_reason)->toBeNull();
    expect($verification->pii_purged_at)->not->toBeNull();
    // The decision record itself survives — that is the point.
    expect($verification->status)->toBe(UndergradRequestorVerificationStatusEnum::Rejected);
    expect($verification->reviewed_by)->not->toBeNull();

    // Email pseudonymized, freeing the real address for a future
    // application rather than locking the person out forever.
    expect($user->fresh()->email)->not->toBe($originalEmail);
    expect($user->fresh()->email)->toContain('purged.invalid');

    // audit_logs is never touched by retention.
    $this->assertDatabaseHas('audit_logs', [
        'action'         => AuditLog::ACTION_UNDERGRAD_REQUESTOR_REJECTED,
        'target_user_id' => $user->user_id,
    ]);
    $this->assertDatabaseHas('audit_logs', [
        'action'         => AuditLog::ACTION_UNDERGRAD_REQUESTOR_PII_PURGED,
        'target_user_id' => $user->user_id,
        'target_email'   => $originalEmail,
    ]);
});

test('the purge is idempotent and leaves in-window rejections alone', function () {
    $recent = urvSubmission();
    Sanctum::actingAs(urvReviewer());
    $this->postJson("/api/admin/undergrad-requestors/{$recent->user_id}/reject", [
        'rejection_reason' => 'Rejected today, well inside the retention window.',
    ])->assertOk();

    $this->artisan('undergrad-requestors:purge-rejected-pii')->assertExitCode(0);
    $this->assertDatabaseHas('undergrad_requestor_profiles', ['user_id' => $recent->user_id]);

    // Age it, purge, then purge again — the second run must be a no-op
    // rather than re-processing or erroring on an already-purged row.
    UndergradRequestorVerification::where('user_id', $recent->user_id)
        ->update(['reviewed_at' => now()->subDays(91)]);

    $this->artisan('undergrad-requestors:purge-rejected-pii')->assertExitCode(0);
    $purgedAt = UndergradRequestorVerification::where('user_id', $recent->user_id)->first()->pii_purged_at;

    $this->artisan('undergrad-requestors:purge-rejected-pii')->assertExitCode(0);
    expect(UndergradRequestorVerification::where('user_id', $recent->user_id)->first()->pii_purged_at->toIso8601String())
        ->toBe($purgedAt->toIso8601String());
});

test('an approved submission is never touched by either sweep', function () {
    $user = urvSubmission();
    Sanctum::actingAs(urvReviewer());
    $this->postJson("/api/admin/undergrad-requestors/{$user->user_id}/approve")->assertOk();

    $this->travel(120)->days();

    $this->artisan('provisioning:expire-stale')->assertExitCode(0);
    $this->artisan('undergrad-requestors:purge-rejected-pii')->assertExitCode(0);

    expect($user->fresh()->status)->toBe('Pending Activation');
    $this->assertDatabaseHas('undergrad_requestor_profiles', ['user_id' => $user->user_id]);
});
