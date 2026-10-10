<?php

use App\Jobs\SuggestUnmatchedLabelTargetJob;
use App\Models\CertificationType;
use App\Models\DocumentType;
use App\Models\Policy;
use App\Models\SystemUser;
use App\Models\UnmatchedCashierItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function uw_admin(): SystemUser
{
    $policy = Policy::create([
        'name'        => 'Reconciliation Staff ' . uniqid(),
        'permissions' => ['cashier_reconciliation' => ['Access']],
        'is_system'   => false,
    ]);
    $admin = SystemUser::factory()->create([
        'role_id' => SystemUser::ROLE_ADMIN, 'status' => 'Activated', 'policy_id' => $policy->policy_id,
    ]);
    Sanctum::actingAs($admin);

    return $admin;
}

function uw_doc(string $name): DocumentType
{
    return DocumentType::create([
        'document_name' => $name, 'document_description' => '', 'document_process_period' => 5,
        'access_id' => 1, 'cashier_document_patterns' => [],
    ]);
}

function uw_item(array $extra = []): UnmatchedCashierItem
{
    $item = UnmatchedCashierItem::create([
        'raw_label' => 'Info. Copy of Grades',
        'normalised_label' => UnmatchedCashierItem::normaliseLabel('Info. Copy of Grades'),
        'occurrence_count' => 1, 'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);
    if ($extra) {
        $item->forceFill($extra)->save();
    }

    return $item->fresh();
}

// ── Dispatch wiring ─────────────────────────────────────────────────────────

test('a first sighting queues one suggestion job when the flag is on', function () {
    Queue::fake();
    config(['features.ai_label_suggestions' => true]);

    UnmatchedCashierItem::recordSighting('Info. Copy of Grades');

    $id = UnmatchedCashierItem::first()->unmatched_cashier_item_id;
    Queue::assertPushed(SuggestUnmatchedLabelTargetJob::class, fn ($j) => $j->itemId === $id);
});

test('repeat sightings of the same label do not queue another job', function () {
    Queue::fake();
    config(['features.ai_label_suggestions' => true]);

    // These three differ only in case, whitespace and trailing punctuation,
    // so they share one normalised key. (An INTERNAL period, as in
    // 'info copy', would be a different key — the normaliser only strips
    // trailing punctuation.)
    UnmatchedCashierItem::recordSighting('Info. Copy of Grades');
    UnmatchedCashierItem::recordSighting('  INFO.   COPY OF GRADES. ');
    UnmatchedCashierItem::recordSighting('info. copy of grades');

    Queue::assertPushed(SuggestUnmatchedLabelTargetJob::class, 1);
    expect(UnmatchedCashierItem::first()->occurrence_count)->toBe(3);
});

test('nothing is queued while the flag is off', function () {
    Queue::fake();
    config(['features.ai_label_suggestions' => false]);

    UnmatchedCashierItem::recordSighting('Info. Copy of Grades');

    Queue::assertNothingPushed();
    expect(UnmatchedCashierItem::count())->toBe(1);
});

test('a queue failure never breaks recording the sighting', function () {
    config(['features.ai_label_suggestions' => true, 'queue.default' => 'no-such-connection']);

    UnmatchedCashierItem::recordSighting('Info. Copy of Grades');

    expect(UnmatchedCashierItem::count())->toBe(1);
});

// ── Index visibility ────────────────────────────────────────────────────────

test('index returns suggestions as an array when the flag is on', function () {
    config(['features.ai_label_suggestions' => true]);
    uw_admin();
    uw_item([
        'suggestions' => ([['key' => 'd1', 'type' => 'document', 'id' => 1, 'name' => 'X', 'score' => 0.9, 'matched_on' => 'name']]),
        'suggestion_source' => 'rules', 'suggested_at' => now(),
    ]);

    $row = $this->getJson('/api/unmatched-cashier-items')->assertOk()->json('data.0');

    expect($row['suggestions'])->toBeArray()->and($row['suggestions'][0]['name'])->toBe('X')
        ->and($row['suggestion_source'])->toBe('rules');
});

test('index hides suggestion fields while the flag is off', function () {
    config(['features.ai_label_suggestions' => false]);
    uw_admin();
    uw_item(['suggestions' => ([['type' => 'document', 'id' => 1]]), 'suggestion_source' => 'rules']);

    $row = $this->getJson('/api/unmatched-cashier-items')->assertOk()->json('data.0');

    expect($row)->not->toHaveKeys(['suggestions', 'suggestion_source', 'suggested_at', 'suggestion_accepted']);
});

// ── Acceptance tracking on resolve ──────────────────────────────────────────

test('resolving to the top suggestion records suggestion_accepted = true', function () {
    uw_admin();
    $doc  = uw_doc('Informative Copy of Grades');
    $item = uw_item(['suggestions' => ([
        ['key' => 'd' . $doc->document_type_id, 'type' => 'document', 'id' => $doc->document_type_id, 'name' => $doc->document_name, 'score' => 0.9, 'matched_on' => 'name'],
    ]), 'suggestion_source' => 'rules']);

    $this->postJson("/api/unmatched-cashier-items/{$item->unmatched_cashier_item_id}/resolve", [
        'document_type_id' => $doc->document_type_id,
    ])->assertOk();

    expect($item->fresh()->suggestion_accepted)->toBeTrue();
});

test('resolving to a different type than the top suggestion records false', function () {
    uw_admin();
    $top   = uw_doc('Informative Copy of Grades');
    $other = uw_doc('Diploma');
    $item  = uw_item(['suggestions' => ([
        ['key' => 'd1', 'type' => 'document', 'id' => $top->document_type_id, 'name' => 'x', 'score' => 0.9, 'matched_on' => 'name'],
        ['key' => 'd2', 'type' => 'document', 'id' => $other->document_type_id, 'name' => 'y', 'score' => 0.5, 'matched_on' => 'name'],
    ])]);

    $this->postJson("/api/unmatched-cashier-items/{$item->unmatched_cashier_item_id}/resolve", [
        'document_type_id' => $other->document_type_id,
    ])->assertOk();

    expect($item->fresh()->suggestion_accepted)->toBeFalse();
});

test('a document id equal to the suggested certificate id is not counted as accepted', function () {
    uw_admin();
    $cert = CertificationType::create([
        'certificate_name' => 'Good Moral', 'certificate_requirements' => '',
        'certificate_process_period' => '3 days', 'access_id' => 1, 'cashier_document_patterns' => [],
    ]);
    $doc  = uw_doc('Some Document');
    $item = uw_item(['suggestions' => ([
        ['key' => 'c1', 'type' => 'certificate', 'id' => $doc->document_type_id, 'name' => 'x', 'score' => 0.9, 'matched_on' => 'name'],
    ])]);

    $this->postJson("/api/unmatched-cashier-items/{$item->unmatched_cashier_item_id}/resolve", [
        'document_type_id' => $doc->document_type_id,
    ])->assertOk();

    expect($item->fresh()->suggestion_accepted)->toBeFalse();
});

test('resolving an item with no suggestions leaves suggestion_accepted null', function () {
    uw_admin();
    $doc  = uw_doc('Informative Copy of Grades');
    $item = uw_item();

    $this->postJson("/api/unmatched-cashier-items/{$item->unmatched_cashier_item_id}/resolve", [
        'document_type_id' => $doc->document_type_id,
    ])->assertOk();

    expect($item->fresh()->suggestion_accepted)->toBeNull();
});