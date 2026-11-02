<?php

/**
 * Row-level diagnostics written by cashier:eval-label-suggestions.
 *
 * Helpers are prefixed `evd_` because Pest helper functions are global and
 * EvaluateLabelSuggestionsTest already defines `ev_doc`.
 */

use App\Models\CertificationType;
use App\Models\DocumentType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function evd_doc(string $name, array $patterns): DocumentType
{
    return DocumentType::create([
        'document_name' => $name, 'document_description' => '', 'document_process_period' => 5,
        'access_id' => 1, 'cashier_document_patterns' => $patterns,
    ]);
}

/** @return list<array<string,string>> CSV rows keyed by header name. */
function evd_rows(string $path): array
{
    $lines  = array_values(array_filter(
        explode("\n", str_replace("\r\n", "\n", Storage::disk('local')->get($path))),
        static fn (string $l) => $l !== ''
    ));
    $header = str_getcsv(array_shift($lines), ',', '"', '\\');

    return array_map(
        static fn (string $l) => array_combine($header, str_getcsv($l, ',', '"', '\\')),
        $lines
    );
}

beforeEach(function () {
    Storage::fake('local');
    DocumentType::query()->delete();
    CertificationType::query()->delete();
    evd_doc('Informative Copy of Grades', ['Informative Copy of Grades', 'Certification Fee - Informative Copy of Grades']);
    evd_doc('Diploma - 2nd copy', ['Diploma - 2nd copy', 'Diploma -2nd Copy']);
    evd_doc('Transcript of Records', ['Transcript of Records']);
});

test('the CSV exposes the diagnostic columns after the original ones', function () {
    Artisan::call('cashier:eval-label-suggestions', ['--output' => 'eval/d1.csv']);

    expect(Storage::disk('local')->get('eval/d1.csv'))
        ->toStartWith('source,label,truth_key,rules_top1,rules_top3,ambiguous,llm_top1,')
        ->toContain('llm_top1,llm_sent,label_sent,candidate_keys,llm_choice,llm_reason');
});

test('without --with-llm no row is marked as sent and candidate keys are still recorded', function () {
    Artisan::call('cashier:eval-label-suggestions', ['--output' => 'eval/d2.csv']);

    $rows = evd_rows('eval/d2.csv');

    expect($rows)->not->toBeEmpty();
    foreach ($rows as $row) {
        expect($row['llm_sent'])->toBe('0')
            ->and($row['label_sent'])->toBe('')
            ->and($row['llm_choice'])->toBe('')
            ->and($row['llm_reason'])->toBe('');
    }
    expect(collect($rows)->contains(fn (array $r) => $r['candidate_keys'] !== ''))->toBeTrue();
});

test('with --with-llm sent rows record the scrubbed label, the choice and the reason', function () {
    config(['services.anthropic.api_key' => 'k', 'label_suggestions.low_threshold' => 1.01]);
    Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text',
        'text' => '{"choice":"none","reason":"no clear match"}']]])]);

    $exit = Artisan::call('cashier:eval-label-suggestions', ['--with-llm' => true, '--limit' => 3, '--output' => 'eval/d3.csv']);
    $rows = evd_rows('eval/d3.csv');
    $sent = array_values(array_filter($rows, fn (array $r) => $r['llm_sent'] === '1'));

    expect($exit)->toBe(0)->and($sent)->toHaveCount(3);

    foreach ($sent as $row) {
        // "none" is not a usable key, so the rules result stands and is flagged as such.
        expect($row['llm_choice'])->toBe('none_or_failed')
            ->and($row['label_sent'])->not->toBe('')
            ->and($row['label_sent'])->not->toMatch('/[<>@]|https?:/')
            ->and($row['llm_top1'])->toBe($row['rules_top1']);
    }
});

test('a usable choice is recorded and scored against the truth key', function () {
    config(['services.anthropic.api_key' => 'k', 'label_suggestions.low_threshold' => 1.01]);
    $target = DocumentType::query()->where('document_name', 'Transcript of Records')->firstOrFail();
    $key    = 'd' . $target->document_type_id;
    Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text',
        'text' => json_encode(['choice' => $key, 'reason' => 'wording match'])]]])]);

    Artisan::call('cashier:eval-label-suggestions', ['--with-llm' => true, '--output' => 'eval/d4.csv']);

    $picked = array_values(array_filter(evd_rows('eval/d4.csv'), fn (array $r) => $r['llm_choice'] === $key));

    expect($picked)->not->toBeEmpty();
    foreach ($picked as $row) {
        expect($row['llm_reason'])->toBe('wording match')
            ->and($row['llm_top1'])->toBe($row['truth_key'] === $key ? '1' : '0');
    }
});

test('--limit leaves the remaining eligible rows unsent and the run warns about it', function () {
    config(['services.anthropic.api_key' => 'k', 'label_suggestions.low_threshold' => 1.01]);
    Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text',
        'text' => '{"choice":"none","reason":"x"}']]])]);

    Artisan::call('cashier:eval-label-suggestions', ['--with-llm' => true, '--limit' => 2, '--output' => 'eval/d5.csv']);
    $output = Artisan::output();

    expect($output)->toContain('Rows sent to the LLM: 2')
        ->and($output)->toContain('not sent because of --limit');
    expect(collect(evd_rows('eval/d5.csv'))->where('llm_sent', '1'))->toHaveCount(2);
});

test('spreadsheet formula triggers are neutralised in the label column', function () {
    evd_doc('=Formula Doc', ['=Formula Doc', '+Formula Copy']);
    Artisan::call('cashier:eval-label-suggestions', ['--output' => 'eval/d6.csv']);

    $labels = array_column(evd_rows('eval/d6.csv'), 'label');

    expect($labels)->not->toBeEmpty();
    foreach ($labels as $label) {
        expect($label)->not->toMatch('/^[=+\-@\t\r]/');
    }
});
