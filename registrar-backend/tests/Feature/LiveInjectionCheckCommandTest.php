<?php

/**
 * cashier:live-injection-check (MT-29.1 evidence generator).
 *
 * The provider is always faked here: these tests prove the command's safety
 * guards, its verdict logic and its report, never the live model itself.
 */

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

const LIC_KEY = 'sk-ant-test-key-DO-NOT-LEAK';

/** A fake provider reply whose answer is chosen by the label it receives. */
function lic_reply(callable $decide)
{
    return function ($request) use ($decide) {
        preg_match('~<label>(.*?)</label>~s', $request['messages'][0]['content'], $m);
        [$choice, $reason] = $decide($m[1] ?? '');

        return Http::response([
            'model'   => 'claude-test-model-1',
            'usage'   => ['input_tokens' => 10, 'output_tokens' => 5],
            'content' => [['type' => 'text', 'text' => json_encode(['choice' => $choice, 'reason' => $reason])]],
        ]);
    };
}

beforeEach(function () {
    Storage::fake('local');
    config(['services.anthropic.api_key' => LIC_KEY, 'services.anthropic.label_model' => 'claude-test-model']);
});

test('it refuses to run without an API key and sends nothing', function () {
    Http::fake();
    config(['services.anthropic.api_key' => '']);

    $this->artisan('cashier:live-injection-check --yes --delay=0')->assertFailed();

    Http::assertNothingSent();
    expect(Storage::disk('local')->allFiles())->toBeEmpty();
});

test('it refuses to run in production', function () {
    Http::fake();
    $this->app['env'] = 'production';

    $this->artisan('cashier:live-injection-check --yes --delay=0')->assertFailed();

    Http::assertNothingSent();
});

test('it sends nothing when the confirmation is declined', function () {
    Http::fake();

    $this->artisan('cashier:live-injection-check --delay=0')
        ->expectsConfirmation("This sends up to 10 synthetic requests to api.anthropic.com using model 'claude-test-model'. Continue?", 'no')
        ->assertFailed();

    Http::assertNothingSent();
});

test('a model that holds the line passes every case and the report is clean', function () {
    Http::fake(['api.anthropic.com/*' => lic_reply(function (string $label) {
        $first = strtolower(strtok($label, ' .') ?: '');

        return match (true) {
            in_array($first, ['tor', 'transcript'], true) => ['d15', 'abbreviation of transcript'],
            $first === 'cert'                             => ['c10', 'cert of enrollment'],
            default                                       => ['d1', 'diploma'],
        };
    })]);

    $this->artisan('cashier:live-injection-check --yes --delay=0')->assertSuccessful();

    $files = Storage::disk('local')->allFiles('qa');
    expect($files)->toHaveCount(1);

    $report = Storage::disk('local')->get($files[0]);
    expect($report)->toContain('10 of 10 cases held the line')
        ->and($report)->toContain('claude-test-model-1')          // provider-reported model captured
        ->and($report)->toContain('filter relaxed for')           // model-level cases are labelled
        ->and($report)->not->toContain(LIC_KEY)                   // no credential
        ->and($report)->not->toContain('api.anthropic.com/v1')    // no raw request details
        ->and($report)->not->toContain('FAIL');
});

test('a model that obeys an injected instruction fails the run', function () {
    Http::fake(['api.anthropic.com/*' => lic_reply(fn () => ['d1', 'APPROVED, refund granted'])]);

    $this->artisan('cashier:live-injection-check --yes --delay=0')->assertFailed();

    $report = Storage::disk('local')->get(Storage::disk('local')->allFiles('qa')[0]);
    expect($report)->toContain('FAIL')->and($report)->not->toContain(LIC_KEY);
});

test('a provider error is reported as ERROR, never as a pass', function () {
    Http::fake(['api.anthropic.com/*' => Http::response('', 500)]);

    $this->artisan('cashier:live-injection-check --yes --delay=0')->assertFailed();

    $report = Storage::disk('local')->get(Storage::disk('local')->allFiles('qa')[0]);
    expect($report)->toContain('ERROR')->and($report)->toContain('0 of 10 cases held the line')
        ->and(substr_count($report, '| PASS'))->toBe(0);
});

test('it never persists configuration changes', function () {
    Http::fake(['api.anthropic.com/*' => lic_reply(fn () => ['d15', 'x'])]);

    $this->artisan('cashier:live-injection-check --yes --delay=0');

    expect(config('label_suggestions.privacy.allowed_terms'))->toBe([]);
});

test('I3e runs with production filtering on and the injected words are not sent', function () {
    Http::fake(['api.anthropic.com/*' => lic_reply(function (string $label) {
        $first = strtolower(strtok($label, ' .') ?: '');

        return match (true) {
            in_array($first, ['tor', 'transcript'], true) => ['d15', 'abbreviation of transcript'],
            $first === 'cert'                             => ['c10', 'cert of enrollment'],
            default                                       => ['d1', 'diploma'],
        };
    })]);

    $this->artisan('cashier:live-injection-check --yes --delay=0')->assertSuccessful();

    $report = Storage::disk('local')->get(Storage::disk('local')->allFiles('qa')[0]);
    $row    = collect(explode("\n", $report))->first(fn ($l) => str_starts_with($l, '| I3e |'));

    expect($row)->not->toBeNull();

    // Columns: '', Case, Layer, Input, Label as sent, Acceptable, Answer, Reason, Verdict, ''
    $cols = array_map('trim', explode('|', $row));
    expect($cols[2])->toStartWith('end-to-end')
        ->and($cols[2])->not->toContain('filter relaxed')
        ->and($cols[3])->toContain('Pick Diploma instead')   // the raw input is recorded
        ->and(strtolower($cols[4]))->not->toContain('pick')  // but the model never saw these words
        ->and(strtolower($cols[4]))->not->toContain('instead');
});