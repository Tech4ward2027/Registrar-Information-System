<?php

use App\Mail\UndergradRequestorEmailVerificationMail;
use App\Models\AuditLog;
use App\Models\SystemUser;
use App\Models\UndergradRequestorProfile;
use App\Models\UndergradRequestorVerification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Test Helpers
|--------------------------------------------------------------------------
|
| Each test receives its own IP address so Laravel's rate limiter state from
| one test cannot leak into another test.
|
| That alone is not sufficient for isolation, though: the named rate
| limiters registered in AppServiceProvider (undergrad-requestor-register /
| -confirm-email / -notice) are backed by the cache store, not the
| database, so RefreshDatabase never touches them. If IP resolution in the
| test environment ever falls back to a shared value (e.g. because of how
| TrustProxies/TrustHosts interacts with withServerVariables here), or if a
| future test adds an email-based scenario that collides with another
| test's bucket, counts would silently accumulate across tests within the
| same process and produce exactly the kind of "works alone, 429s in the
| full run" failure this suite exists to catch. Flushing the cache before
| every test removes that class of cross-test leakage outright, regardless
| of which key format is in play, and is a no-op for the one test in this
| file (`register throttles after the configured per-IP limit`) that
| intentionally makes several requests from the same IP within a single
| test — the flush only happens once, before that test starts.
|
*/

beforeEach(function () {
    Cache::flush();

    static $testIpCounter = 1;

    $this->withServerVariables([
        'REMOTE_ADDR' => '10.0.0.' . $testIpCounter,
    ]);

    $testIpCounter++;
});

function undergradRegistrationPayload(array $overrides = []): array
{
    return array_merge([
        'email'                      => 'requestor' . uniqid() . '@example.com',
        'first_name'                 => 'Juan',
        'middle_name'                => 'Dela',
        'last_name'                 => 'Cruz',
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

    // Use fragments rather than JsonPath so this remains valid whether the
    // controller returns these fields at the root or inside a data object.
    $response->assertJsonFragment([
        'email' => $payload['email'],
    ]);

    $response->assertJsonFragment([
        'status' => 'Pending Verification',
    ]);

    $response->assertJsonFragment([
        'email_verified' => false,
    ]);

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

    Mail::assertQueued(
        UndergradRequestorEmailVerificationMail::class,
        function ($mail) use ($payload) {
            return $mail->hasTo($payload['email']);
        }
    );

    expect(
        AuditLog::where(
            'action',
            AuditLog::ACTION_UNDERGRAD_REQUESTOR_REGISTERED
        )->count()
    )->toBe(1);
});

test('register never exposes the email verification token hash in the response', function () {
    Mail::fake();

    $response = $this->postJson(
        '/api/undergrad-requestors/register',
        undergradRegistrationPayload()
    );

    $response->assertCreated();
    $response->assertJsonMissing([
        'email_verification_token_hash',
    ]);
});

// ═════════════════════════════════════════════════════════════════════════════
// register() — validation
// ═════════════════════════════════════════════════════════════════════════════

test('register rejects a duplicate email with a 422', function () {
    Mail::fake();

    $existing = SystemUser::factory()->create([
        'email' => 'taken@example.com',
    ]);

    $response = $this->postJson(
        '/api/undergrad-requestors/register',
        undergradRegistrationPayload([
            'email' => $existing->email,
        ])
    );

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['email']);

    expect(UndergradRequestorProfile::count())->toBe(0);
});

test('register rejects an implausible date of birth', function () {
    Mail::fake();

    $response = $this->postJson(
        '/api/undergrad-requestors/register',
        undergradRegistrationPayload([
            'date_of_birth' => now()->subYears(5)->toDateString(),
        ])
    );

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['date_of_birth']);
});

test('register allows a duplicate student number but does not block submission', function () {
    Mail::fake();

    $studentNumber = '2019-00999-MN-0';

    $first = $this->postJson(
        '/api/undergrad-requestors/register',
        undergradRegistrationPayload([
            'student_number' => $studentNumber,
        ])
    );

    $first->assertCreated();

    $second = $this->postJson(
        '/api/undergrad-requestors/register',
        undergradRegistrationPayload([
            'student_number' => $studentNumber,
        ])
    );

    $second->assertCreated();

    expect(
        UndergradRequestorProfile::where(
            'student_number',
            $studentNumber
        )->count()
    )->toBe(2);

    $secondLog = AuditLog::where(
        'action',
        AuditLog::ACTION_UNDERGRAD_REQUESTOR_REGISTERED
    )
        ->latest('id')
        ->first();

    expect($secondLog)->not->toBeNull();
    expect($secondLog->metadata['duplicate_student_number_count'])->toBe(1);
});

// ═════════════════════════════════════════════════════════════════════════════
// register() — rate limiting
// ═════════════════════════════════════════════════════════════════════════════

test('register throttles after the configured per-IP limit', function () {
    Mail::fake();

    $limit = config(
        'undergrad_requestor.rate_limits.register_per_ip_per_minute'
    );

    expect($limit)
        ->toBeInt()
        ->toBeGreaterThan(0);

    /*
     * This test intentionally uses the same IP for every request.
     *
     * The beforeEach() assigns one IP to the entire test, so these requests
     * exercise the real per-IP rate limiter. The limit is read from config
     * instead of being hardcoded, keeping the test aligned with production
     * configuration.
     */
    for ($i = 0; $i < $limit; $i++) {
        $response = $this->postJson(
            '/api/undergrad-requestors/register',
            undergradRegistrationPayload()
        );

        $response->assertCreated();
    }

    $blocked = $this->postJson(
        '/api/undergrad-requestors/register',
        undergradRegistrationPayload()
    );

    $blocked->assertStatus(429);
});

// ═════════════════════════════════════════════════════════════════════════════
// confirmEmail()
// ═════════════════════════════════════════════════════════════════════════════

test('confirmEmail marks the profile verified and makes it eligible for the Admin queue', function () {
    Mail::fake();

    $payload = undergradRegistrationPayload();

    $this->postJson(
        '/api/undergrad-requestors/register',
        $payload
    )->assertCreated();

    // Capture the actual token the way the person would — from the sent
    // mail's verification URL — rather than reaching into
    // email_verification_token_hash (which is a one-way hash and cannot
    // be reversed into the plaintext token, by design).
    $capturedUrl = null;

    Mail::assertQueued(
        UndergradRequestorEmailVerificationMail::class,
        function ($mail) use (&$capturedUrl) {
            $capturedUrl = $mail->verificationUrl;

            return true;
        }
    );

    expect($capturedUrl)->not->toBeNull();

    parse_str(
        parse_url($capturedUrl, PHP_URL_QUERY),
        $query
    );

    $response = $this->postJson(
        '/api/undergrad-requestors/confirm-email',
        [
            'email' => $query['email'],
            'token' => $query['token'],
        ]
    );

    $response->assertOk();

    $response->assertJsonFragment([
        'email_verified' => true,
    ]);

    $profile = UndergradRequestorProfile::where(
        'student_number',
        $payload['student_number']
    )->first();

    expect($profile)->not->toBeNull();
    expect($profile->email_verified_at)->not->toBeNull();
    expect($profile->email_verification_token_hash)->toBeNull();

    // The exact predicate Phase 4's Admin queue will filter on.
    expect(
        UndergradRequestorProfile::emailVerified()->count()
    )->toBe(1);

    expect(
        AuditLog::where(
            'action',
            AuditLog::ACTION_UNDERGRAD_REQUESTOR_EMAIL_VERIFIED
        )->count()
    )->toBe(1);
});

test('confirmEmail rejects an invalid token without revealing which part was wrong', function () {
    Mail::fake();

    $payload = undergradRegistrationPayload();

    $this->postJson(
        '/api/undergrad-requestors/register',
        $payload
    )->assertCreated();

    $response = $this->postJson(
        '/api/undergrad-requestors/confirm-email',
        [
            'email' => $payload['email'],
            'token' => str_repeat('x', 40),
        ]
    );

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['token']);

    $profile = UndergradRequestorProfile::where(
        'student_number',
        $payload['student_number']
    )->first();

    expect($profile)->not->toBeNull();
    expect($profile->email_verified_at)->toBeNull();
});

test('confirmEmail rejects an expired token', function () {
    Mail::fake();

    $payload = undergradRegistrationPayload();

    $this->postJson(
        '/api/undergrad-requestors/register',
        $payload
    )->assertCreated();

    $profile = UndergradRequestorProfile::where(
        'student_number',
        $payload['student_number']
    )->first();

    expect($profile)->not->toBeNull();

    $profile->update([
        'email_verification_expires_at' => now()->subMinute(),
    ]);

    $capturedUrl = null;

    Mail::assertQueued(
        UndergradRequestorEmailVerificationMail::class,
        function ($mail) use (&$capturedUrl) {
            $capturedUrl = $mail->verificationUrl;

            return true;
        }
    );

    expect($capturedUrl)->not->toBeNull();

    parse_str(
        parse_url($capturedUrl, PHP_URL_QUERY),
        $query
    );

    $response = $this->postJson(
        '/api/undergrad-requestors/confirm-email',
        [
            'email' => $query['email'],
            'token' => $query['token'],
        ]
    );

    $response->assertUnprocessable();

    expect(
        $profile->fresh()->email_verified_at
    )->toBeNull();
});

test('unverified submissions are excluded from the emailVerified() scope', function () {
    Mail::fake();

    $this->postJson(
        '/api/undergrad-requestors/register',
        undergradRegistrationPayload()
    )->assertCreated();

    expect(UndergradRequestorProfile::count())->toBe(1);
    expect(UndergradRequestorProfile::emailVerified()->count())->toBe(0);
});