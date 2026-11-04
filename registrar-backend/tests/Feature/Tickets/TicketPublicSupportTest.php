<?php

use App\Console\Commands\PruneTicketPublicSubmissions;
use App\Models\TicketPublicSubmission;
use App\Services\Tickets\Captcha\NullCaptchaVerifier;
use App\Services\Tickets\Captcha\TurnstileCaptchaVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

require_once __DIR__ . '/../../Support/TicketPublicTestHelpers.php';

uses(RefreshDatabase::class);

/**
 * Replace the HTTP client with a fresh fake. Http::fake() only ADDS stubs, and
 * the first stub matching a URL wins, so re-faking the same URL inside one
 * test silently keeps answering with the first response.
 */
function tkFakeCaptchaHttp(mixed $stub): void
{
    Http::swap(new \Illuminate\Http\Client\Factory());
    Http::fake($stub);
}

// ═════════════════════════════════════════════════════════════════════════════
// CAPTCHA verifier
// ═════════════════════════════════════════════════════════════════════════════

test('Turnstile accepts only an explicit success from the provider', function () {
    config(['tickets.public.captcha.secret' => 's3cret', 'tickets.public.captcha.verify_url' => 'https://captcha.test/verify']);

    tkFakeCaptchaHttp(['captcha.test/*' => Http::response(['success' => true])]);
    expect((new TurnstileCaptchaVerifier())->verify('token-1', '203.0.113.9'))->toBeTrue();

    Http::assertSent(fn ($r) => $r['secret'] === 's3cret' && $r['response'] === 'token-1' && $r['remoteip'] === '203.0.113.9');

    tkFakeCaptchaHttp(['captcha.test/*' => Http::response(['success' => false, 'error-codes' => ['invalid-input-response']])]);
    expect((new TurnstileCaptchaVerifier())->verify('token-1', null))->toBeFalse();
});

test('Turnstile fails closed: no token, no secret, a provider error or an outage all refuse', function () {
    config(['tickets.public.captcha.secret' => 's3cret', 'tickets.public.captcha.verify_url' => 'https://captcha.test/verify']);
    Log::spy();

    expect((new TurnstileCaptchaVerifier())->verify(null, null))->toBeFalse()
        ->and((new TurnstileCaptchaVerifier())->verify('  ', null))->toBeFalse();

    tkFakeCaptchaHttp(['captcha.test/*' => Http::response('oops', 500)]);
    expect((new TurnstileCaptchaVerifier())->verify('t', null))->toBeFalse();

    tkFakeCaptchaHttp(['captcha.test/*' => Http::response('not json', 200)]);
    expect((new TurnstileCaptchaVerifier())->verify('t', null))->toBeFalse();

    tkFakeCaptchaHttp(fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'));
    expect((new TurnstileCaptchaVerifier())->verify('t', null))->toBeFalse();

    // Only a literal boolean true counts: a truthy string is not a success.
    tkFakeCaptchaHttp(['captcha.test/*' => Http::response(['success' => 'true'])]);
    expect((new TurnstileCaptchaVerifier())->verify('t', null))->toBeFalse();
});

test('Turnstile without a configured secret refuses and never calls the provider', function () {
    config(['tickets.public.captcha.secret' => null, 'tickets.public.captcha.verify_url' => 'https://captcha.test/verify']);
    Log::spy();
    Http::fake(['captcha.test/*' => Http::response(['success' => true])]);

    expect((new TurnstileCaptchaVerifier())->verify('t', null))->toBeFalse();
    Http::assertNothingSent();
});

test('the container picks the real verifier when enabled and the null one when disabled', function () {
    config(['tickets.public.captcha_enabled' => true]);
    expect(app(\App\Services\Tickets\Captcha\CaptchaVerifier::class))->toBeInstanceOf(TurnstileCaptchaVerifier::class);

    config(['tickets.public.captcha_enabled' => false]);
    expect(app(\App\Services\Tickets\Captcha\CaptchaVerifier::class))->toBeInstanceOf(NullCaptchaVerifier::class);
});

// ═════════════════════════════════════════════════════════════════════════════
// Retention of pending submissions
// ═════════════════════════════════════════════════════════════════════════════

test('the prune command deletes old pending submissions, keeps recent ones, and is safe to run twice', function () {
    $make = fn (string $email, $createdAt) => TicketPublicSubmission::create([
        'token_hash' => hash('sha256', $email), 'email' => $email, 'category_id' => 1,
        'subject' => 's', 'description' => 'd', 'created_at' => $createdAt, 'expires_at' => $createdAt->copy()->addMinutes(30),
    ]);

    $make('old-unconfirmed@example.com', now()->subDays(8));
    $make('old-confirmed@example.com', now()->subDays(30));
    $make('recent@example.com', now()->subDays(2));

    $this->artisan('tickets:prune-public-submissions')->assertExitCode(0);
    expect(TicketPublicSubmission::pluck('email')->all())->toBe(['recent@example.com']);

    $this->artisan('tickets:prune-public-submissions')->assertExitCode(0);
    expect(TicketPublicSubmission::count())->toBe(1);
});

test('the prune run is recorded in job_run_logs', function () {
    $this->artisan('tickets:prune-public-submissions')->assertExitCode(0);

    $this->assertDatabaseHas('job_run_logs', ['job_name' => 'tickets:prune-public-submissions', 'status' => 'success']);
});

test('the prune command is registered with the job monitor', function () {
    expect(\App\Models\JobRunLog::JOBS)->toHaveKey('tickets:prune-public-submissions')
        ->and(\App\Models\JobRunLog::EXPECTED_INTERVAL_MINUTES)->toHaveKey('tickets:prune-public-submissions');
});