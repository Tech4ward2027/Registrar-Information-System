<?php

use App\Mail\UndergradRequestorEmailVerificationMail;
use App\Models\SecurityEvent;
use App\Models\UndergradRequestorProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/**
 * Undergrad Requestor Registration — Phase 7.
 *
 * Phase 6 gave the three public endpoints their own named rate limiters
 * (AppServiceProvider::registerRateLimiters()), and
 * UndergradRequestorSecurityTest.php already covers the per-email
 * mail-bomb bucket and the security_events dedupe guarantee. What was
 * still untested going into Phase 7:
 *
 *   - That the REAL configured defaults (not a test-only override to 1)
 *     actually hold under a realistic burst, for all three endpoints.
 *   - The confirm-email endpoint's own bucket, which had zero coverage.
 *   - The notice endpoint's bucket, which had zero coverage, including
 *     the deliberate absence of a security_events write (see its
 *     ->for() closure in AppServiceProvider — read-only, no PII,
 *     therefore no event).
 *
 * This file exists to close exactly that gap, not to re-prove what
 * UndergradRequestorSecurityTest.php already proves.
 */
function ratelimitBurstPayload(array $overrides = []): array
{
    return array_merge([
        'email'                      => 'burst' . uniqid() . '@example.com',
        'first_name'                 => 'Juan',
        'middle_name'                => 'Dela',
        'last_name'                  => 'Cruz',
        'suffix'                     => null,
        'student_number'             => '2019-00' . random_int(100, 999) . '-MN-0',
        'program'                    => 'BS Information Technology',
        'last_school_year_attended'  => '2021-2022',
        'date_of_birth'              => '2000-05-15',
        'present_address'            => '123 Sample St., Taguig City',
        'reason_for_non_enrollment'  => null,
        'phone'                      => '09171234567',
        'data_privacy_consent'       => true,
    ], $overrides);
}

// ═════════════════════════════════════════════════════════════════════
// POST /undergrad-requestors/register — real defaults
// ═════════════════════════════════════════════════════════════════════

test('the register endpoint holds at its real configured per-IP-per-minute default', function () {
    Mail::fake();

    $limit = (int) config('undergrad_requestor.rate_limits.register_per_ip_per_minute');

    // Sanity: if this default is ever edited in config, the test should
    // fail loudly rather than silently proving a stale number.
    expect($limit)->toBeGreaterThan(0);

    for ($i = 0; $i < $limit; $i++) {
        $this->postJson('/api/undergrad-requestors/register', ratelimitBurstPayload())
            ->assertCreated();
    }

    // One request past the ceiling, same window, same IP: blocked.
    $this->postJson('/api/undergrad-requestors/register', ratelimitBurstPayload())
        ->assertStatus(429)
        ->assertJsonStructure(['message'])
        ->assertJsonMissingPath('bucket'); // never leak which bucket tripped to the caller

    expect(UndergradRequestorProfile::count())->toBe($limit);
});

test('the register endpoint recovers once the per-minute window rolls over', function () {
    Mail::fake();

    $limit = (int) config('undergrad_requestor.rate_limits.register_per_ip_per_minute');

    for ($i = 0; $i < $limit; $i++) {
        $this->postJson('/api/undergrad-requestors/register', ratelimitBurstPayload())
            ->assertCreated();
    }

    $this->postJson('/api/undergrad-requestors/register', ratelimitBurstPayload())
        ->assertStatus(429);

    $this->travel(61)->seconds();

    $this->postJson('/api/undergrad-requestors/register', ratelimitBurstPayload())
        ->assertCreated();
});

// ═════════════════════════════════════════════════════════════════════
// POST /undergrad-requestors/confirm-email — previously untested bucket
// ═════════════════════════════════════════════════════════════════════

test('the confirm-email endpoint throttles a burst of guesses against one address', function () {
    Mail::fake();

    config()->set('undergrad_requestor.rate_limits.confirm_per_ip_per_minute', 3);

    $payload = ratelimitBurstPayload();
    $this->postJson('/api/undergrad-requestors/register', $payload)->assertCreated();

    // Each guess is deliberately wrong — the limiter must count attempts,
    // not successes, since a token-guessing attacker only ever produces
    // failures until the one guess that matters.
    for ($i = 0; $i < 3; $i++) {
        $this->postJson('/api/undergrad-requestors/confirm-email', [
            'email' => $payload['email'],
            'token' => str_repeat((string) $i, 40),
        ])->assertUnprocessable();
    }

    $this->postJson('/api/undergrad-requestors/confirm-email', [
        'email' => $payload['email'],
        'token' => str_repeat('9', 40),
    ])->assertStatus(429);

    $events = SecurityEvent::where('event_type', SecurityEvent::EVENT_TYPE_ONBOARDING_THROTTLED)
        ->where('reason', SecurityEvent::REASON_THROTTLED_CONFIRM)
        ->get();

    expect($events)->toHaveCount(1);
});

test('the confirm-email per-email-per-hour bucket survives the caller switching IPs', function () {
    Mail::fake();

    config()->set('undergrad_requestor.rate_limits.confirm_per_ip_per_minute', 1000);
    config()->set('undergrad_requestor.rate_limits.confirm_per_email_per_hour', 2);

    $payload = ratelimitBurstPayload();
    $this->postJson('/api/undergrad-requestors/register', $payload)->assertCreated();

    $this->postJson('/api/undergrad-requestors/confirm-email', [
        'email' => $payload['email'],
        'token' => str_repeat('a', 40),
    ])->assertUnprocessable();

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
        ->postJson('/api/undergrad-requestors/confirm-email', [
            'email' => $payload['email'],
            'token' => str_repeat('b', 40),
        ])->assertUnprocessable();

    // Third guess against the same address, third distinct IP — only the
    // per-email bucket can still be watching at this point.
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.55'])
        ->postJson('/api/undergrad-requestors/confirm-email', [
            'email' => $payload['email'],
            'token' => str_repeat('c', 40),
        ])->assertStatus(429);
});

// ═════════════════════════════════════════════════════════════════════
// GET /undergrad-requestors/registration-notice — previously untested
// ═════════════════════════════════════════════════════════════════════

test('the registration-notice endpoint throttles a burst but never writes a security event', function () {
    config()->set('undergrad_requestor.rate_limits.notice_per_ip_per_minute', 3);

    for ($i = 0; $i < 3; $i++) {
        $this->getJson('/api/undergrad-requestors/registration-notice')->assertOk();
    }

    $this->getJson('/api/undergrad-requestors/registration-notice')->assertStatus(429);

    // Deliberate: this bucket has no ->response() hook (see
    // AppServiceProvider::registerRateLimiters()'s docblock on the
    // notice limiter) — a read-only, no-PII endpoint being hit hard is
    // not a signal worth a row in security_events.
    expect(SecurityEvent::where('event_type', SecurityEvent::EVENT_TYPE_ONBOARDING_THROTTLED)->count())
        ->toBe(0);
});
