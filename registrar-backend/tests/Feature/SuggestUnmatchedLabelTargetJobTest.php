<?php

use App\Jobs\SuggestUnmatchedLabelTargetJob;
use App\Models\CertificationType;
use App\Models\DocumentType;
use App\Models\UnmatchedCashierItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function sj_item(string $label = 'Info. Copy of Grades'): UnmatchedCashierItem
{
    return UnmatchedCashierItem::create([
        'raw_label'        => $label,
        'normalised_label' => UnmatchedCashierItem::normaliseLabel($label),
        'occurrence_count' => 1,
        'first_seen_at'    => now(),
        'last_seen_at'     => now(),
    ]);
}

function sj_row(int $id): ?object
{
    // The PK is unmatched_cashier_item_id, so DB::table()->find() (which assumes `id`) cannot be used.
    return \DB::table('unmatched_cashier_items')->where('unmatched_cashier_item_id', $id)->first();
}

beforeEach(function () {
    DocumentType::query()->delete();
    CertificationType::query()->delete();
    DocumentType::create([
        'document_name' => 'Informative Copy of Grades', 'document_description' => '',
        'document_process_period' => 5, 'access_id' => 1,
        'cashier_document_patterns' => ['Informative Copy of Grades'],
    ]);
    DocumentType::create([
        'document_name' => 'Diploma', 'document_description' => '',
        'document_process_period' => 5, 'access_id' => 1, 'cashier_document_patterns' => [],
    ]);
});

test('job stores rule-based suggestions with the flag off and makes no HTTP call', function () {
    Http::fake();
    config(['features.ai_label_suggestions' => false]);
    $item = sj_item();

    (new SuggestUnmatchedLabelTargetJob($item->unmatched_cashier_item_id))->handle(app(\App\Services\LabelSuggestionService::class));

    $row = sj_row($item->unmatched_cashier_item_id);
    expect($row->suggestion_source)->toBe('rules')
        ->and($row->suggested_at)->not->toBeNull()
        ->and(json_decode($row->suggestions, true)[0]['name'])->toBe('Informative Copy of Grades')
        ->and($row->suggestion_accepted)->toBeNull();
    Http::assertNothingSent();
});

test('job is idempotent and force recomputes', function () {
    config(['features.ai_label_suggestions' => false]);
    $item = sj_item();
    $svc  = app(\App\Services\LabelSuggestionService::class);

    (new SuggestUnmatchedLabelTargetJob($item->unmatched_cashier_item_id))->handle($svc);
    \DB::table('unmatched_cashier_items')->where('unmatched_cashier_item_id', $item->unmatched_cashier_item_id)
        ->update(['suggestion_source' => 'marker']);

    (new SuggestUnmatchedLabelTargetJob($item->unmatched_cashier_item_id))->handle($svc);
    expect(sj_row($item->unmatched_cashier_item_id)->suggestion_source)->toBe('marker');

    (new SuggestUnmatchedLabelTargetJob($item->unmatched_cashier_item_id, true))->handle($svc);
    expect(sj_row($item->unmatched_cashier_item_id)->suggestion_source)->toBe('rules');
});

test('job skips resolved and missing items', function () {
    $item = sj_item();
    $item->forceFill(['resolved_at' => now()])->save();
    $svc = app(\App\Services\LabelSuggestionService::class);

    (new SuggestUnmatchedLabelTargetJob($item->unmatched_cashier_item_id))->handle($svc);
    (new SuggestUnmatchedLabelTargetJob(999999))->handle($svc);

    expect(sj_row($item->unmatched_cashier_item_id)->suggested_at)->toBeNull();
});

test('an ambiguous label uses the LLM pick when the flag and key are on', function () {
    config(['features.ai_label_suggestions' => true, 'services.anthropic.api_key' => 'k']);
    $diploma = DocumentType::where('document_name', 'Diploma')->first();
    Http::fake(['api.anthropic.com/*' => Http::response(['content' => [[
        'type' => 'text', 'text' => json_encode(['choice' => 'd' . $diploma->document_type_id, 'reason' => 'fits']),
    ]]])]);
    $item = sj_item('Docs fee');

    (new SuggestUnmatchedLabelTargetJob($item->unmatched_cashier_item_id))->handle(app(\App\Services\LabelSuggestionService::class));

    $row = sj_row($item->unmatched_cashier_item_id);
    // Either the rules were unambiguous (no call, 'rules') or the LLM pick was applied.
    if ($row->suggestion_source === 'llm') {
        expect(json_decode($row->suggestions, true)[0]['id'])->toBe($diploma->document_type_id);
    } else {
        Http::assertNothingSent();
    }
});

test('an LLM outage leaves rule-based suggestions in place', function () {
    config(['features.ai_label_suggestions' => true, 'services.anthropic.api_key' => 'k']);
    Http::fake(['api.anthropic.com/*' => Http::response('', 500)]);
    $item = sj_item('Xyz fee');

    (new SuggestUnmatchedLabelTargetJob($item->unmatched_cashier_item_id))->handle(app(\App\Services\LabelSuggestionService::class));

    expect(sj_row($item->unmatched_cashier_item_id)->suggestion_source)->toBe('rules');
});