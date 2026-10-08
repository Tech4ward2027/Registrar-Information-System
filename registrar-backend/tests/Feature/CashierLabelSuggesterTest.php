<?php

use App\Models\CertificationType;
use App\Models\DocumentType;
use App\Services\CashierLabelSuggester;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function ls_doc(string $name, array $patterns = [], array $extra = []): DocumentType
{
    return DocumentType::create(array_merge([
        'document_name'             => $name,
        'document_description'      => '',
        'document_process_period'   => 5,
        'access_id'                 => 1,
        'cashier_document_patterns' => $patterns,
    ], $extra));
}

beforeEach(function () {
    // Migrations seed real types; isolate each test from them.
    DocumentType::query()->delete();
    CertificationType::query()->delete();
});

test('drifted label ranks the intended type first', function (string $label) {
    $grades = ls_doc('Informative Copy of Grades', ['Informative Copy of Grades']);
    ls_doc('Diploma', ['Diploma']);
    ls_doc('Transcript of Records', ['Transcript of Records']);

    $result = app(CashierLabelSuggester::class)->suggest($label);

    expect($result['suggestions'])->not->toBeEmpty()
        ->and($result['suggestions'][0]['id'])->toBe($grades->document_type_id)
        ->and($result['suggestions'][0]['type'])->toBe('document');
})->with([
    'abbreviation'  => 'Info. Copy of Grades',
    'case/spacing'  => '  INFORMATIVE   COPY OF GRADES ',
    'fee prefix'    => 'Fee - Informative Copy of Grades',
    'typo'          => 'Informatve Copy of Grades',
]);

test('abbreviation TOR expands to transcript of records', function () {
    ls_doc('Informative Copy of Grades');
    $tor = ls_doc('Transcript of Records');

    $result = app(CashierLabelSuggester::class)->suggest('TOR');

    expect($result['suggestions'][0]['id'])->toBe($tor->document_type_id);
});

test('certificate types are suggested too', function () {
    $cert = CertificationType::create([
        'certificate_name' => 'Good Moral Certificate', 'certificate_requirements' => '',
        'certificate_process_period' => '3 days', 'access_id' => 1, 'cashier_document_patterns' => [],
    ]);
    ls_doc('Diploma');

    $result = app(CashierLabelSuggester::class)->suggest('Good Moral Cert');

    expect($result['suggestions'][0]['type'])->toBe('certificate')
        ->and($result['suggestions'][0]['id'])->toBe($cert->certificate_type_id);
});

test('archived types are never suggested', function () {
    ls_doc('Informative Copy of Grades', [], ['is_archived' => true]);

    expect(app(CashierLabelSuggester::class)->suggest('Informative Copy of Grades')['suggestions'])->toBeEmpty();
});

test('a clear single winner is not ambiguous', function () {
    ls_doc('Diploma', ['Diploma']);
    ls_doc('Transcript of Records');

    expect(app(CashierLabelSuggester::class)->suggest('Diploma')['is_ambiguous'])->toBeFalse();
});

test('two near-equal candidates are flagged ambiguous', function () {
    ls_doc('Certified True Copy of Diploma');
    ls_doc('Certified True Copy of Grades');

    expect(app(CashierLabelSuggester::class)->suggest('Certified True Copy')['is_ambiguous'])->toBeTrue();
});

test('no match at all is ambiguous with no suggestions', function () {
    ls_doc('Diploma');

    $r = app(CashierLabelSuggester::class)->suggest('Locker rental');

    expect($r['suggestions'])->toBeEmpty()->and($r['is_ambiguous'])->toBeTrue();
});

test('empty label yields nothing', function () {
    $r = app(CashierLabelSuggester::class)->suggest('   ');

    expect($r['suggestions'])->toBeEmpty();
});

test('when another type already owns the exact label only that owner is suggested', function () {
    ls_doc('Owner Type', ['Rush Fee Diploma']);
    ls_doc('Rush Fee Diploma Lookalike');

    $r = app(CashierLabelSuggester::class)->suggest('Rush Fee Diploma');

    expect(collect($r['suggestions'])->pluck('name')->unique()->all())->toBe(['Owner Type']);
});

test('stored suggestions never include pattern lists, candidates do', function () {
    ls_doc('Diploma', ['Diploma']);

    $r = app(CashierLabelSuggester::class)->suggest('Diplomas');

    expect($r['suggestions'][0])->not->toHaveKey('patterns')
        ->and($r['candidates'][0])->toHaveKey('patterns');
});

test('an abbreviation that expands to a phrase containing stopwords scores as an exact match', function () {
    $tor = ls_doc('Transcript of Records');
    ls_doc('Diploma');

    $top = app(CashierLabelSuggester::class)->suggest('TOR')['suggestions'][0];

    expect($top['id'])->toBe($tor->document_type_id)->and($top['score'])->toBe(1.0);
});

test('a catalogue override is used instead of the live catalogue and skips the ownership rule', function () {
    ls_doc('Diploma');
    $suggester = app(CashierLabelSuggester::class);

    $override = [[
        'key' => 'd999', 'type' => 'document', 'id' => 999, 'name' => 'Only In Override',
        'strings' => ['name' => ['Only In Override'], 'pattern' => []],
    ]];

    $r = $suggester->suggest('Only In Override', $override);

    expect($r['suggestions'])->toHaveCount(1)->and($r['suggestions'][0]['id'])->toBe(999);
});