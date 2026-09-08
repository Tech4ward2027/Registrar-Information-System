<?php

use App\Models\AuditLog;
use App\Models\CertificationType;
use App\Models\DocumentType;
use App\Models\SystemUser;
use App\Models\UnmatchedCashierItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function actingAsAdmin(): SystemUser
{
    $admin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN, 'status' => 'Activated']);
    Sanctum::actingAs($admin);
    return $admin;
}

function makeUnmatchedItem(string $rawLabel = 'Info. Copy of Grades', int $occurrences = 3): UnmatchedCashierItem
{
    return UnmatchedCashierItem::create([
        'raw_label'        => $rawLabel,
        'normalised_label' => UnmatchedCashierItem::normaliseLabel($rawLabel),
        'occurrence_count' => $occurrences,
        'first_seen_at'    => now()->subDays(2),
        'last_seen_at'     => now()->subHour(),
    ]);
}

// ═════════════════════════════════════════════════════════════════════════════
// Access control
// ═════════════════════════════════════════════════════════════════════════════

test('a student cannot list unmatched cashier items', function () {
    $student = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT, 'status' => 'Activated']);
    Sanctum::actingAs($student);

    $this->getJson('/api/unmatched-cashier-items')->assertStatus(403);
});

test('a student cannot resolve an unmatched cashier item', function () {
    $item = makeUnmatchedItem();
    $student = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT, 'status' => 'Activated']);
    Sanctum::actingAs($student);

    $this->postJson("/api/unmatched-cashier-items/{$item->unmatched_cashier_item_id}/resolve", [
        'document_type_id' => 1,
    ])->assertStatus(403);
});

// ═════════════════════════════════════════════════════════════════════════════
// Listing
// ═════════════════════════════════════════════════════════════════════════════

test('index lists unresolved items ordered by occurrence_count descending by default', function () {
    actingAsAdmin();

    makeUnmatchedItem('Rare Label', 1);
    makeUnmatchedItem('Common Label', 40);
    makeUnmatchedItem('Mid Label', 10);

    $response = $this->getJson('/api/unmatched-cashier-items')->assertOk();

    $labels = collect($response->json('data'))->pluck('raw_label')->all();

    expect($labels)->toBe(['Common Label', 'Mid Label', 'Rare Label']);
});

test('index excludes resolved items by default', function () {
    actingAsAdmin();

    $resolved = makeUnmatchedItem('Already Fixed');
    $resolved->forceFill(['resolved_at' => now()])->save();
    makeUnmatchedItem('Still Open');

    $response = $this->getJson('/api/unmatched-cashier-items')->assertOk();

    $labels = collect($response->json('data'))->pluck('raw_label')->all();

    expect($labels)->toBe(['Still Open']);
});

test('index with ?resolved=1 shows only resolved items', function () {
    actingAsAdmin();

    $resolved = makeUnmatchedItem('Already Fixed');
    $resolved->forceFill(['resolved_at' => now()])->save();
    makeUnmatchedItem('Still Open');

    $response = $this->getJson('/api/unmatched-cashier-items?resolved=1')->assertOk();

    $labels = collect($response->json('data'))->pluck('raw_label')->all();

    expect($labels)->toBe(['Already Fixed']);
});

// ═════════════════════════════════════════════════════════════════════════════
// Resolve → attach pattern
// ═════════════════════════════════════════════════════════════════════════════

test('resolving an item appends its raw label to the target document type\'s patterns', function () {
    $admin = actingAsAdmin();
    $item = makeUnmatchedItem('Info. Copy of Grades');
    $docType = DocumentType::create([
        'document_name'             => 'Informative Copy of Grades',
        'document_description'      => '',
        'document_process_period'   => 5,
        'access_id'                 => 1,
        'cashier_document_patterns' => ['Informative Copy of Grades'],
    ]);

    $this->postJson("/api/unmatched-cashier-items/{$item->unmatched_cashier_item_id}/resolve", [
        'document_type_id' => $docType->document_type_id,
    ])->assertOk()
      ->assertJsonPath('resolved_by', $admin->user_id);

    expect($docType->fresh()->cashier_document_patterns)
        ->toBe(['Informative Copy of Grades', 'Info. Copy of Grades']);

    expect($item->fresh()->resolved_at)->not->toBeNull();
});

test('resolving an item appends to a certificate type\'s patterns', function () {
    actingAsAdmin();
    $item = makeUnmatchedItem('Good Moral Cert.');
    $certType = CertificationType::create([
        'certificate_name'           => 'Good Moral Certificate',
        'certificate_requirements'   => '',
        'certificate_process_period' => '3 days',
        'access_id'                  => 1,
        'cashier_document_patterns'  => [],
    ]);

    $this->postJson("/api/unmatched-cashier-items/{$item->unmatched_cashier_item_id}/resolve", [
        'certificate_type_id' => $certType->certificate_type_id,
    ])->assertOk();

    expect($certType->fresh()->cashier_document_patterns)->toBe(['Good Moral Cert.']);
});

test('resolving does not duplicate a pattern that already normalises the same way', function () {
    actingAsAdmin();
    // NOT "Informative Copy of Grades" — DatabaseSeeder ($seed = true on the
    // base TestCase) already creates a real DocumentType with that exact
    // pattern. Reusing it here would make this test's own freshly-created
    // $docType a SECOND type claiming the same normalised label — a genuine
    // cross-type conflict App\Rules\CashierPatternsAreConflictFree /
    // CashierPatternConflictChecker now correctly rejects (see resolve()'s
    // conflict check). This test is specifically about same-type dedup, so
    // it needs a label nothing else in the seeded data already owns.
    $item = makeUnmatchedItem('  regression test fee.');
    $docType = DocumentType::create([
        'document_name'             => 'Regression Test Document',
        'document_description'      => '',
        'document_process_period'   => 5,
        'access_id'                 => 1,
        'cashier_document_patterns' => ['Regression Test Fee'],
    ]);

    $this->postJson("/api/unmatched-cashier-items/{$item->unmatched_cashier_item_id}/resolve", [
        'document_type_id' => $docType->document_type_id,
    ])->assertOk();

    expect($docType->fresh()->cashier_document_patterns)->toBe(['Regression Test Fee']);
});

test('resolving requires exactly one of document_type_id or certificate_type_id', function () {
    actingAsAdmin();
    $item = makeUnmatchedItem();

    $this->postJson("/api/unmatched-cashier-items/{$item->unmatched_cashier_item_id}/resolve", [])
        ->assertStatus(422);

    $docType = DocumentType::create([
        'document_name' => 'X', 'document_description' => '', 'document_process_period' => 1, 'access_id' => 1,
    ]);
    $certType = CertificationType::create([
        'certificate_name' => 'Y', 'certificate_requirements' => '', 'certificate_process_period' => '1 day', 'access_id' => 1,
    ]);

    $this->postJson("/api/unmatched-cashier-items/{$item->unmatched_cashier_item_id}/resolve", [
        'document_type_id'    => $docType->document_type_id,
        'certificate_type_id' => $certType->certificate_type_id,
    ])->assertStatus(422);
});

test('resolving an already-resolved item returns a conflict instead of double-processing', function () {
    actingAsAdmin();
    $item = makeUnmatchedItem();
    $item->forceFill(['resolved_at' => now()])->save();

    $docType = DocumentType::create([
        'document_name' => 'X', 'document_description' => '', 'document_process_period' => 1, 'access_id' => 1,
    ]);

    $this->postJson("/api/unmatched-cashier-items/{$item->unmatched_cashier_item_id}/resolve", [
        'document_type_id' => $docType->document_type_id,
    ])->assertStatus(409);
});

test('resolving a nonexistent item returns 404', function () {
    actingAsAdmin();

    // Must be a real, existing document_type_id — ResolveUnmatchedCashierItemRequest's
    // exists:document_type,document_type_id rule runs during controller-dependency
    // resolution, before this route's own UnmatchedCashierItem::find($id) 404 check
    // ever executes. An invalid id here fails validation (422) before the item-not-found
    // check gets a chance to run, masking the very case this test is meant to verify.
    $docType = DocumentType::create([
        'document_name' => 'X', 'document_description' => '', 'document_process_period' => 1, 'access_id' => 1,
    ]);

    $this->postJson('/api/unmatched-cashier-items/999999/resolve', [
        'document_type_id' => $docType->document_type_id,
    ])->assertStatus(404);
});

test('resolving writes an audit log entry naming the target type', function () {
    actingAsAdmin();
    $item = makeUnmatchedItem('Info. Copy of Grades');
    $docType = DocumentType::create([
        'document_name'             => 'Informative Copy of Grades',
        'document_description'      => '',
        'document_process_period'   => 5,
        'access_id'                 => 1,
        'cashier_document_patterns' => [],
    ]);

    $this->postJson("/api/unmatched-cashier-items/{$item->unmatched_cashier_item_id}/resolve", [
        'document_type_id' => $docType->document_type_id,
    ])->assertOk();

    $log = AuditLog::where('action', AuditLog::ACTION_UNMATCHED_CASHIER_ITEM_RESOLVED)->latest('created_at')->first();

    expect($log)->not->toBeNull()
        ->and($log->metadata['attached_to_name'])->toBe('Informative Copy of Grades')
        ->and($log->metadata['attached_to_type'])->toBe('document');
});

// ═════════════════════════════════════════════════════════════════════════════
// Dismiss
// ═════════════════════════════════════════════════════════════════════════════

test('dismissing an item marks it resolved without touching any type\'s patterns', function () {
    actingAsAdmin();
    $item = makeUnmatchedItem('Random One-Off Fee');
    $docType = DocumentType::create([
        'document_name' => 'X', 'document_description' => '', 'document_process_period' => 1,
        'access_id' => 1, 'cashier_document_patterns' => ['existing'],
    ]);

    $this->postJson("/api/unmatched-cashier-items/{$item->unmatched_cashier_item_id}/dismiss")
        ->assertOk();

    expect($item->fresh()->resolved_at)->not->toBeNull()
        ->and($docType->fresh()->cashier_document_patterns)->toBe(['existing']);
});

test('dismissing writes a distinct audit action from resolving', function () {
    actingAsAdmin();
    $item = makeUnmatchedItem('Random One-Off Fee');

    $this->postJson("/api/unmatched-cashier-items/{$item->unmatched_cashier_item_id}/dismiss")->assertOk();

    expect(AuditLog::where('action', AuditLog::ACTION_UNMATCHED_CASHIER_ITEM_DISMISSED)->count())->toBe(1)
        ->and(AuditLog::where('action', AuditLog::ACTION_UNMATCHED_CASHIER_ITEM_RESOLVED)->count())->toBe(0);
});