<?php

use App\Models\SecurityEvent;
use App\Models\SystemUser;
use App\Models\UndergradRequestorProfile;
use App\Support\EncryptedPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Cache;

/**
 * Undergrad Requestor Registration — Phase 6 (security & compliance).
 *
 * Covers the four things this phase added that nothing else tests:
 * encryption at rest, Data Privacy Act consent, the per-email rate-limit
 * bucket, and the security_events rows that make abuse of the public
 * endpoints visible rather than silent.
 */
uses(RefreshDatabase::class);

function phase6Payload(array $overrides = []): array
{
    return array_merge([
        'email'                      => 'phase6-' . uniqid() . '@example.com',
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
        'data_privacy_consent'       => true,
    ], $overrides);
}

beforeEach(function () {
    // Rate-limit counters live in the cache, which — unlike the database
    // — RefreshDatabase does not reset between tests. Without this, the
    // fifth test to POST /register inherits the fourth test's counter and
    // fails for a reason that has nothing to do with what it asserts.
    // Flushing rather than clearing individual keys because
    // ThrottleRequests hashes its own cache keys internally; reproducing
    // that hashing here would couple the test to a framework detail.
    Cache::flush();
});

// ═════════════════════════════════════════════════════════════════════
// Encryption at rest
// ═════════════════════════════════════════════════════════════════════

test('self-declared PII is encrypted in the database but reads back as plaintext', function () {
    Mail::fake();

    $payload = phase6Payload();

    $this->postJson('/api/undergrad-requestors/register', $payload)->assertCreated();

    $user    = SystemUser::where('email', $payload['email'])->firstOrFail();
    $profile = UndergradRequestorProfile::where('user_id', $user->user_id)->firstOrFail();

    // Through the model: the caller sees exactly what was submitted, and
    // date_of_birth is still a Carbon instance, so existing callers such
    // as the Admin detail resource are unaffected by the change.
    expect($profile->present_address)->toBe($payload['present_address']);
    expect($profile->phone)->toBe($payload['phone']);
    expect($profile->date_of_birth->toDateString())->toBe($payload['date_of_birth']);

    // Straight out of the database, bypassing the casts: nothing
    // recognisable is sitting on disk.
    $raw = DB::table('undergrad_requestor_profiles')
        ->where('user_id', $user->user_id)
        ->first();

    expect($raw->present_address)->not->toBe($payload['present_address']);
    expect($raw->phone)->not->toBe($payload['phone']);
    expect($raw->date_of_birth)->not->toBe($payload['date_of_birth']);
    expect(EncryptedPayload::looksEncrypted($raw->present_address))->toBeTrue();
    expect(EncryptedPayload::looksEncrypted($raw->phone))->toBeTrue();
    expect(EncryptedPayload::looksEncrypted($raw->date_of_birth))->toBeTrue();

    // Columns the queue searches, matches or indexes on MUST stay
    // readable — encrypting any of these would break the Admin queue's
    // search, the duplicate-student-number flag, and the D4 email match
    // that is the entire provisioning mechanism.
    expect($raw->student_number)->toBe($payload['student_number']);
    expect($raw->first_name)->toBe($payload['first_name']);
    expect($raw->last_name)->toBe($payload['last_name']);
    expect($user->email)->toBe($payload['email']);
});

test('a row written before encryption existed still reads back correctly', function () {
    // The migration backfills existing rows, but a row could also be
    // written directly (a data fix, a restored backup taken mid-rollout).
    // The read path must tolerate plaintext rather than throwing, which
    // is the whole reason this feature uses custom casts instead of
    // Laravel's built-in 'encrypted'.
    $user = SystemUser::factory()->undergradRequestor()->create();

    DB::table('undergrad_requestor_profiles')->insert([
        'user_id'                   => $user->user_id,
        'first_name'                => 'Legacy',
        'last_name'                 => 'Row',
        'student_number'            => '2015-99999-MN-0',
        'program'                   => 'BS Computer Science',
        'last_school_year_attended' => '2018-2019',
        'date_of_birth'             => '1995-01-01',
        'present_address'           => '1 Plaintext Ave',
        'phone'                     => '09990001111',
        'created_at'                => now(),
        'updated_at'                => now(),
    ]);

    $profile = UndergradRequestorProfile::where('user_id', $user->user_id)->firstOrFail();

    expect($profile->present_address)->toBe('1 Plaintext Ave');
    expect($profile->phone)->toBe('09990001111');
    expect($profile->date_of_birth->toDateString())->toBe('1995-01-01');
});

test('re-saving an encrypted value does not double-wrap it', function () {
    Mail::fake();

    $payload = phase6Payload();
    $this->postJson('/api/undergrad-requestors/register', $payload)->assertCreated();

    $user    = SystemUser::where('email', $payload['email'])->firstOrFail();
    $profile = UndergradRequestorProfile::where('user_id', $user->user_id)->firstOrFail();

    // Touch an unrelated column and save. A naive encrypt-on-write would
    // re-encrypt the already-encrypted value it just read back, and the
    // second read would return ciphertext.
    $profile->update(['program' => 'BS Accountancy']);

    expect($profile->fresh()->phone)->toBe($payload['phone']);
});

// ═════════════════════════════════════════════════════════════════════
// Data Privacy Act consent (RA 10173)
// ═════════════════════════════════════════════════════════════════════

test('registration is refused without explicit data privacy consent', function () {
    Mail::fake();

    $this->postJson('/api/undergrad-requestors/register', phase6Payload([
        'data_privacy_consent' => false,
    ]))->assertStatus(422)->assertJsonValidationErrors('data_privacy_consent');

    $this->postJson('/api/undergrad-requestors/register', array_diff_key(
        phase6Payload(),
        ['data_privacy_consent' => null],
    ))->assertStatus(422)->assertJsonValidationErrors('data_privacy_consent');

    expect(SystemUser::where('role_id', SystemUser::ROLE_UNDERGRAD_REQUESTOR)->count())->toBe(0);
});

test('consent is recorded server-side with the configured notice version', function () {
    Mail::fake();
    config()->set('undergrad_requestor.data_privacy.consent_version', '2.7');

    $payload = phase6Payload();
    $this->postJson('/api/undergrad-requestors/register', $payload)->assertCreated();

    $user    = SystemUser::where('email', $payload['email'])->firstOrFail();
    $profile = UndergradRequestorProfile::where('user_id', $user->user_id)->firstOrFail();

    expect($profile->hasRecordedDataPrivacyConsent())->toBeTrue();
    // The version comes from OUR config, never from the request — the
    // client is trusted for one bit ("I agree") and nothing more.
    expect($profile->data_privacy_consent_version)->toBe('2.7');
    expect($profile->data_privacy_consent_at)->not->toBeNull();
    expect($profile->data_privacy_consent_ip)->not->toBeNull();
});

test('the registration notice endpoint serves the same version that gets recorded', function () {
    config()->set('undergrad_requestor.data_privacy.consent_version', '3.1');

    $this->getJson('/api/undergrad-requestors/registration-notice')
        ->assertOk()
        ->assertJsonPath('data.consent_version', '3.1')
        ->assertJsonPath('data.retention.unverified_submission_days', 14)
        ->assertJsonPath('data.retention.rejected_record_days', 90)
        ->assertJsonStructure(['data' => ['notice', 'notice_url']]);
});

// ═════════════════════════════════════════════════════════════════════
// Rate limiting
// ═════════════════════════════════════════════════════════════════════

test('the per-email bucket blocks repeat submissions for one address across IPs', function () {
    Mail::fake();
    config()->set('undergrad_requestor.rate_limits.register_per_email_per_day', 2);

    $victim = 'victim-' . uniqid() . '@example.com';

    // First attempt succeeds and creates the account; subsequent attempts
    // fail validation (duplicate email) but still CONSUME the bucket —
    // which is the point. A failed attempt is exactly as expensive to
    // serve as a successful one, and mail-bombing does not care whether
    // the submission was valid.
    $this->postJson('/api/undergrad-requestors/register', phase6Payload(['email' => $victim]))
        ->assertCreated();

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])
        ->postJson('/api/undergrad-requestors/register', phase6Payload(['email' => $victim]))
        ->assertStatus(422);

    // Third attempt, third distinct IP — the per-IP buckets are nowhere
    // near their ceilings, so only the per-email bucket can catch this.
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.42'])
        ->postJson('/api/undergrad-requestors/register', phase6Payload(['email' => $victim]))
        ->assertStatus(429);
});

test('a tripped rate limit is recorded to security_events exactly once per window', function () {
    Mail::fake();
    config()->set('undergrad_requestor.rate_limits.register_per_ip_per_minute', 1);

    $this->postJson('/api/undergrad-requestors/register', phase6Payload())->assertCreated();

    // Several refusals in the same window.
    $this->postJson('/api/undergrad-requestors/register', phase6Payload())->assertStatus(429);
    $this->postJson('/api/undergrad-requestors/register', phase6Payload())->assertStatus(429);
    $this->postJson('/api/undergrad-requestors/register', phase6Payload())->assertStatus(429);

    $events = SecurityEvent::where('event_type', SecurityEvent::EVENT_TYPE_ONBOARDING_THROTTLED)
        ->where('reason', SecurityEvent::REASON_THROTTLED_REGISTER)
        ->get();

    // Deduplicated: the signal ("this bucket was hammered") is preserved,
    // the write amplification is not. A limiter that writes a row per
    // blocked request turns our own defence into the attack.
    expect($events)->toHaveCount(1);
    expect($events->first()->metadata['bucket'])->toContain('ip:');
});

// ═════════════════════════════════════════════════════════════════════
// Security events on the confirmation endpoint
// ═════════════════════════════════════════════════════════════════════

test('a bad confirmation token is vague to the caller and specific in security_events', function () {
    Mail::fake();

    $payload = phase6Payload();
    $this->postJson('/api/undergrad-requestors/register', $payload)->assertCreated();

    $this->postJson('/api/undergrad-requestors/confirm-email', [
        'email' => $payload['email'],
        'token' => str_repeat('a', 40),
    ])->assertStatus(422);

    $event = SecurityEvent::where('event_type', SecurityEvent::EVENT_TYPE_ONBOARDING_CONFIRM_FAILED)
        ->latest('security_event_id')
        ->first();

    expect($event)->not->toBeNull();
    expect($event->reason)->toBe(SecurityEvent::REASON_TOKEN_INVALID);
    expect($event->email)->toBe($payload['email']);
});

test('confirming an unknown address is indistinguishable from a bad token to the caller', function () {
    $unknown = 'nobody-' . uniqid() . '@example.com';

    $response = $this->postJson('/api/undergrad-requestors/confirm-email', [
        'email' => $unknown,
        'token' => str_repeat('b', 40),
    ]);

    $response->assertStatus(422);

    // Same user-facing message as a wrong token against a REAL account —
    // otherwise this endpoint becomes an oracle for "which addresses have
    // registered".
    expect($response->json('errors.token.0'))
        ->toContain('invalid or has expired');

    $event = SecurityEvent::where('event_type', SecurityEvent::EVENT_TYPE_ONBOARDING_CONFIRM_FAILED)
        ->latest('security_event_id')
        ->first();

    expect($event->reason)->toBe(SecurityEvent::REASON_UNKNOWN_ACCOUNT);
});

// ═════════════════════════════════════════════════════════════════════
// Input normalisation
// ═════════════════════════════════════════════════════════════════════

test('email is canonicalised so one address cannot become two accounts', function () {
    Mail::fake();

    $this->postJson('/api/undergrad-requestors/register', phase6Payload([
        'email' => '  Juan.Dela.Cruz@Example.COM ',
    ]))->assertCreated();

    expect(SystemUser::where('email', 'juan.dela.cruz@example.com')->exists())->toBeTrue();

    // The same address in different clothes is rejected as a duplicate,
    // not accepted as a second account that could never be reconciled
    // with the first at IDP-login time (D4 matches on email).
    $this->postJson('/api/undergrad-requestors/register', phase6Payload([
        'email' => 'JUAN.DELA.CRUZ@example.com',
    ]))->assertStatus(422)->assertJsonValidationErrors('email');
});

test('control characters are rejected in free-text fields', function () {
    Mail::fake();

    $this->postJson('/api/undergrad-requestors/register', phase6Payload([
        'first_name' => "Juan\r\nBcc: someone@evil.test",
    ]))->assertStatus(422)->assertJsonValidationErrors('first_name');
});
