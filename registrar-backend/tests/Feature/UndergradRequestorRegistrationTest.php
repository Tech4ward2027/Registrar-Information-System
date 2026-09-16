<?php

use App\Mail\UndergradRequestorEmailVerificationMail;
use App\Models\AuditLog;
use App\Models\SystemUser;
use App\Models\UndergradRequestorProfile;
use App\Models\UndergradRequestorVerification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

function undergradRegistrationPayload(array $overrides = []): array
{
    return array_merge([
        'email'                      => 'requestor' . uniqid() . '@example.com',
        'first_name'                 => 'Juan',
        'middle_name'                => 'Dela',
        'last_name'                  => 'Cruz',
        'suffix'                     => null,
        'student_number'             => '2019-00123-MN-0',
        'program'                    => 'BS Information Technology',
        'last_school_year_attended'  => '2021-2022',
        'date_of_birth'              => '2000-05-15',
        'present_address'            => '123 Sample St., Taguig City',
        'reason_for_non_enrollment'  => null,
        'phone'                      => '09171234567',
        // Phase 6 (RA 10173) — the onboarding form now requires explicit
        // Data Privacy Act consent, validated as 'accepted'. Part of the
        // default payload rather than a per-test override because a
        // submission WITHOUT it is no longer a valid submission at all;
        // the tests that exercise its absence pass a false/missing value
        // explicitly instead.
        'data_privacy_consent'       => true,
    ], $overrides);
}

// ═════════════════════════════════════════════════════════════════════════════
// register() — happy path
// ═════════════════════════════════════════════════════════════════════════════

test('register creates a Pending Verification user, profile, and verification row', function () {
    Mail::fake();

    $payload = undergradRegistrationPayload();

    $response = $this->postJson('/api/undergrad-requestors/register', $payload);

    $response->assertCreated();
    $response->assertJsonPath('email', $payload['email']);
    $response->assertJsonPath('status', 'Pending Verification');
    $response->assertJsonPath('email_verified', false);

    $user = SystemUser::where('email', $payload['email'])->first();
    expect($user)->not->toBeNull();
    expect($user->role_id)->toBe(SystemUser::ROLE_UNDERGRAD_REQUESTOR);
    expect($user->status)->toBe('Pending Verification');
    expect($user->password)->toBeNull();
    expect($user->idp_user_id)->toBeNull();

    $profile = UndergradRequestorProfile::where('user_id', $user->user_id)->first();
    expect($profile)->not->toBeNull();
    expect($profile->student_number)->toBe($payload['student_number']);
    expect($profile->email_verified_at)->toBeNull();
    expect($profile->email_verification_token_hash)->not->toBeNull();

    $verification = UndergradRequestorVerification::where('user_id', $user->user_id)->first();
    expect($verification)->not->toBeNull();
    expect($verification->status->value)->toBe('Pending');

    Mail::assertQueued(UndergradRequestorEmailVerificationMail::class, function ($mail) use ($payload) {
        return $mail->hasTo($payload['email']);
    });

    expect(AuditLog::where('action', AuditLog::ACTION_UNDERGRAD_REQUESTOR_REGISTERED)->count())->toBe(1);
});

test('register never exposes the email verification token hash in the response', function () {
    Mail::fake();

    $response = $this->postJson('/api/undergrad-requestors/register', undergradRegistrationPayload());

    $response->assertCreated();
    $response->assertJsonMissing(['email_verification_token_hash']);
});

// ═════════════════════════════════════════════════════════════════════════════
// register() — validation
// ═════════════════════════════════════════════════════════════════════════════

test('register rejects a duplicate email with a 422', function () {
    Mail::fake();

    $existing = SystemUser::factory()->create(['email' => 'taken@example.com']);

    $response = $this->postJson('/api/undergrad-requestors/register', undergradRegistrationPayload([
        'email' => $existing->email,
    ]));

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['email']);

    expect(UndergradRequestorProfile::count())->toBe(0);
});

test('register rejects an implausible date of birth', function () {
    Mail::fake();

    $response = $this->postJson('/api/undergrad-requestors/register', undergradRegistrationPayload([
        'date_of_birth' => now()->subYears(5)->toDateString(),
    ]));

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['date_of_birth']);
});

test('register allows a duplicate student number but does not block submission', function () {
    Mail::fake();

    $first = $this->postJson('/api/undergrad-requestors/register', undergradRegistrationPayload([
        'student_number' => '2019-00999-MN-0',
    ]));
    $first->assertCreated();

    $second = $this->postJson('/api/undergrad-requestors/register', undergradRegistrationPayload([
        'student_number' => '2019-00999-MN-0',
    ]));
    $second->assertCreated();

    expect(UndergradRequestorProfile::where('student_number', '2019-00999-MN-0')->count())->toBe(2);

    $secondLog = AuditLog::where('action', AuditLog::ACTION_UNDERGRAD_REQUESTOR_REGISTERED)
        ->latest('id')
        ->first();

    expect($secondLog->metadata['duplicate_student_number_count'])->toBe(1);
});

// ═════════════════════════════════════════════════════════════════════════════
// register() — rate limiting
// ═════════════════════════════════════════════════════════════════════════════
//
// Phase 7 (QA sweep): this file used to assert a hardcoded, anonymous
// 'throttle:10,1' — 10 requests succeeding, the 11th blocked. Phase 6
// replaced that with named, configurable limiters (see
// AppServiceProvider::registerRateLimiters()) whose real default is 5
// per IP per minute, not 10 — so this test was failing on its 6th
// iteration (assertCreated() on an already-429'd request) rather than
// verifying anything. Left here as a pointer instead of silently
// deleting the coverage:
//
//   - per-IP/per-minute + per-IP/per-day burst behaviour, against the
//     real configured ceiling, plus the confirm-email and notice
//     endpoints' own buckets:
//     tests/Feature/UndergradRequestorRateLimitBurstTest.php
//   - per-EMAIL/per-day bucket (mail-bomb protection) and the
//     security_events dedupe-on-trip guarantee:
//     tests/Feature/UndergradRequestorSecurityTest.php
//
// ═════════════════════════════════════════════════════════════════════════════
// confirmEmail()
// ═════════════════════════════════════════════════════════════════════════════

test('confirmEmail marks the profile verified and makes it eligible for the Admin queue', function () {
    Mail::fake();

    $payload = undergradRegistrationPayload();
    $this->postJson('/api/undergrad-requestors/register', $payload)->assertCreated();

    // Capture the actual token the way the person would — from the sent
    // mail's verification URL — rather than reaching into
    // email_verification_token_hash (which is a one-way hash and cannot
    // be reversed into the plaintext token, by design).
    $capturedUrl = null;
    Mail::assertQueued(UndergradRequestorEmailVerificationMail::class, function ($mail) use (&$capturedUrl) {
        $capturedUrl = $mail->verificationUrl;
        return true;
    });

    parse_str(parse_url($capturedUrl, PHP_URL_QUERY), $query);

    $response = $this->postJson('/api/undergrad-requestors/confirm-email', [
        'email' => $query['email'],
        'token' => $query['token'],
    ]);

    $response->assertOk();
    $response->assertJsonPath('email_verified', true);

    $profile = UndergradRequestorProfile::where('student_number', $payload['student_number'])->first();
    expect($profile->email_verified_at)->not->toBeNull();
    expect($profile->email_verification_token_hash)->toBeNull();

    // The exact predicate Phase 4's Admin queue will filter on.
    expect(UndergradRequestorProfile::emailVerified()->count())->toBe(1);

    expect(AuditLog::where('action', AuditLog::ACTION_UNDERGRAD_REQUESTOR_EMAIL_VERIFIED)->count())->toBe(1);
});

test('confirmEmail rejects an invalid token without revealing which part was wrong', function () {
    Mail::fake();

    $payload = undergradRegistrationPayload();
    $this->postJson('/api/undergrad-requestors/register', $payload)->assertCreated();

    $response = $this->postJson('/api/undergrad-requestors/confirm-email', [
        'email' => $payload['email'],
        'token' => str_repeat('x', 40),
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['token']);

    $profile = UndergradRequestorProfile::where('student_number', $payload['student_number'])->first();
    expect($profile->email_verified_at)->toBeNull();
});

test('confirmEmail rejects an expired token', function () {
    Mail::fake();

    $payload = undergradRegistrationPayload();
    $this->postJson('/api/undergrad-requestors/register', $payload)->assertCreated();

    $profile = UndergradRequestorProfile::where('student_number', $payload['student_number'])->first();
    $profile->update(['email_verification_expires_at' => now()->subMinute()]);

    $capturedUrl = null;
    Mail::assertQueued(UndergradRequestorEmailVerificationMail::class, function ($mail) use (&$capturedUrl) {
        $capturedUrl = $mail->verificationUrl;
        return true;
    });
    parse_str(parse_url($capturedUrl, PHP_URL_QUERY), $query);

    $response = $this->postJson('/api/undergrad-requestors/confirm-email', [
        'email' => $query['email'],
        'token' => $query['token'],
    ]);

    $response->assertUnprocessable();

    expect($profile->fresh()->email_verified_at)->toBeNull();
});

test('unverified submissions are excluded from the emailVerified() scope', function () {
    Mail::fake();

    $this->postJson('/api/undergrad-requestors/register', undergradRegistrationPayload())->assertCreated();

    expect(UndergradRequestorProfile::count())->toBe(1);
    expect(UndergradRequestorProfile::emailVerified()->count())->toBe(0);
});