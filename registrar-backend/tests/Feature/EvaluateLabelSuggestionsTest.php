<?php

use App\Models\CertificationType;
use App\Models\DocumentType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function ev_doc(string $name, array $patterns): DocumentType
{
    return DocumentType::create([
        'document_name' => $name, 'document_description' => '', 'document_process_period' => 5,
        'access_id' => 1, 'cashier_document_patterns' => $patterns,
    ]);
}

beforeEach(function () {
    Storage::fake('local');
    DocumentType::query()->delete();
    CertificationType::query()->delete();
    ev_doc('Informative Copy of Grades', ['Informative Copy of Grades', 'Certification Fee - Informative Copy of Grades']);
    ev_doc('Diploma - 2nd copy', ['Diploma - 2nd copy', 'Diploma -2nd Copy']);
    ev_doc('Transcript of Records', ['Transcript of Records']);
});

test('it reports leave-one-out and drift separately, with n and a confidence interval, and writes a CSV', function () {
    $this->artisan('cashier:eval-label-suggestions', ['--output' => 'eval/test.csv'])
        ->expectsOutputToContain('leave-one-out')
        ->expectsOutputToContain('drift')
        ->expectsOutputToContain('n=')
        ->assertExitCode(0);

    Storage::disk('local')->assertExists('eval/test.csv');
    $csv = Storage::disk('local')->get('eval/test.csv');
    expect($csv)->toContain('source,label,truth_key')->and($csv)->toContain('leave-one-out')->and($csv)->toContain('drift:');
});

test('it never writes to the database', function () {
    $before = DocumentType::query()->orderBy('document_type_id')->get(['document_type_id', 'cashier_document_patterns'])->toJson();

    $this->artisan('cashier:eval-label-suggestions', ['--output' => 'eval/t2.csv'])->assertExitCode(0);

    expect(DocumentType::query()->orderBy('document_type_id')->get(['document_type_id', 'cashier_document_patterns'])->toJson())->toBe($before);
});

test('unsafe output paths are rejected', function (string $path) {
    $this->artisan('cashier:eval-label-suggestions', ['--output' => $path])->assertExitCode(2);
})->with(['../escape.csv', '/etc/passwd', 'a\\b.csv']);

test('with-llm requires an API key and makes no call without one', function () {
    Http::fake();
    config(['services.anthropic.api_key' => '']);

    $this->artisan('cashier:eval-label-suggestions', ['--with-llm' => true])->assertExitCode(1);

    Http::assertNothingSent();
});

test('without --with-llm no HTTP request is ever made', function () {
    Http::fake();
    config(['services.anthropic.api_key' => 'k']);

    $this->artisan('cashier:eval-label-suggestions', ['--output' => 'eval/t3.csv'])->assertExitCode(0);

    Http::assertNothingSent();
});

test('with-llm reports a lift line and only sends catalogue labels', function () {
    config(['services.anthropic.api_key' => 'k']);
    Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => '{"choice":"none","reason":"x"}']]])]);

    $this->artisan('cashier:eval-label-suggestions', ['--with-llm' => true, '--limit' => 5, '--output' => 'eval/t4.csv'])
        ->expectsOutputToContain('Rows sent to the LLM')
        ->assertExitCode(0);

    Http::assertSent(function ($req) {
        $user = $req['messages'][0]['content'] ?? '';

        return str_contains($user, '<label>') && !preg_match('/@|https?:/', $user);
    });
});
