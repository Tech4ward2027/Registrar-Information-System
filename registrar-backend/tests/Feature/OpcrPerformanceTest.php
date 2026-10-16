<?php

use App\Enums\RequestStatusEnum;
use App\Models\AccessType;
use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Models\Policy;
use App\Models\RequestDocument;
use App\Models\RequestHistory;
use App\Models\SystemUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// ═════════════════════════════════════════════════════════════════════════════
// GET /opcr/performance and the processing fields on GET /document-requests/
// logbook. Request statuses and the default business calendar (Mon-Fri,
// 08:00-20:00 Asia/Manila) come from the migrations/TestCase.
//
// Helper names carry an "opc" prefix: Pest test files share one global
// function namespace.
// ═════════════════════════════════════════════════════════════════════════════

beforeEach(function () {
    config(['app.display_timezone' => 'Asia/Manila']);
});

function opcStaff(array $logbookActions = ['View']): SystemUser
{
    $admin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN, 'status' => 'Activated']);

    $policy = Policy::firstOrCreate(
        ['name' => 'Test Logbook ' . implode('', $logbookActions)],
        ['permissions' => ['dashboard' => ['View'], 'logbook' => $logbookActions], 'is_system' => false]
    );
    $admin->update(['policy_id' => $policy->policy_id]);

    Sanctum::actingAs($admin);

    return $admin;
}

function opcDocType(string $name = 'Transcript of Records', string $period = '2 hours'): DocumentType
{
    return DocumentType::create([
        'document_name'           => $name,
        'document_description'    => 'Test',
        'document_requirements'   => 'Valid ID',
        'document_process_period' => $period,
        'access_id'               => AccessType::firstOrCreate(['access_id' => 1], ['access_name' => 'Student'])->access_id,
    ]);
}

/**
 * A Completed request filed Tue 2026-03-10 10:00 Manila (inside office hours)
 * that reaches Pending Signature $minutesToSignature office minutes later,
 * then waits a long time for signature and pickup — time that must not count.
 */
function opcCompletedRequest(DocumentType $type, int $minutesToSignature): DocumentRequest
{
    $filed = Carbon::parse('2026-03-10 10:00:00', 'Asia/Manila');

    $request = DocumentRequest::factory()->create([
        'status_id'    => RequestStatusEnum::Completed->value,
        'requested_at' => $filed->clone()->utc(),
    ]);

    $document = RequestDocument::create([
        'request_id'       => $request->request_id,
        'document_type_id' => $type->document_type_id,
        'number_of_copies' => 1,
        'status_id'        => RequestStatusEnum::Completed->value,
    ]);

    $transitions = [
        [RequestStatusEnum::Processing, RequestStatusEnum::PendingSignature, $filed->clone()->addMinutes($minutesToSignature)],
        [RequestStatusEnum::PendingSignature, RequestStatusEnum::ReadyToClaim, $filed->clone()->addDay()],
        [RequestStatusEnum::ReadyToClaim, RequestStatusEnum::Completed, $filed->clone()->addDays(2)],
    ];

    foreach ($transitions as [$old, $new, $at]) {
        RequestHistory::create([
            'request_id'      => $request->request_id,
            'old_status_id'   => $old->value,
            'new_status_id'   => $new->value,
            'changed_at'      => $at->clone()->utc(),
            'minutes_processed' => 0,
            'business_minutes'  => 0,
        ]);
    }

    return $request;
}

// ── Access & validation ──────────────────────────────────────────────────────

test('the report requires authentication', function () {
    $this->getJson('/api/opcr/performance?from=2026-01-01&to=2026-06-30')->assertUnauthorized();
});

test('a student account cannot read the report', function () {
    $student = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT, 'status' => 'Activated']);
    Sanctum::actingAs($student);

    $this->getJson('/api/opcr/performance?from=2026-01-01&to=2026-06-30')->assertForbidden();
});

test('an admin without the logbook module cannot read the report', function () {
    $admin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN, 'status' => 'Activated']);
    Sanctum::actingAs($admin);

    $this->getJson('/api/opcr/performance?from=2026-01-01&to=2026-06-30')->assertForbidden();
});

test('both dates are required and must be ordered', function () {
    opcStaff();

    $this->getJson('/api/opcr/performance')->assertUnprocessable()->assertJsonValidationErrors(['from', 'to']);
    $this->getJson('/api/opcr/performance?from=2026-06-30&to=2026-01-01')->assertUnprocessable()->assertJsonValidationErrors(['to']);
    $this->getJson('/api/opcr/performance?from=2026/01/01&to=2026-06-30')->assertUnprocessable()->assertJsonValidationErrors(['from']);
});

test('a range longer than the configured maximum is rejected', function () {
    opcStaff();
    config(['opcr.max_range_days' => 100]);

    $this->getJson('/api/opcr/performance?from=2026-01-01&to=2026-12-31')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['to']);
});

// ── Aggregation ──────────────────────────────────────────────────────────────

test('an empty period returns a well-formed empty report', function () {
    opcStaff();

    $this->getJson('/api/opcr/performance?from=2026-01-01&to=2026-06-30')
        ->assertOk()
        ->assertJsonPath('categories', [])
        ->assertJsonPath('totals.processed_transactions', 0)
        ->assertJsonPath('totals.mean_minutes', null)
        ->assertJsonPath('period.basis', 'requested_at');
});

test('the mean is total counted minutes over processed transactions and ignores the signing and claiming wait', function () {
    opcStaff();

    $type = opcDocType('Transcript of Records', '2 hours');   // target 120 minutes
    opcCompletedRequest($type, 30);
    opcCompletedRequest($type, 90);

    $response = $this->getJson('/api/opcr/performance?from=2026-03-01&to=2026-03-31')->assertOk();

    $category = $response->json('categories.0');

    expect($category['label'])->toBe('Transcript of Records')
        ->and($category['total_minutes'])->toBe(120)
        ->and($category['processed_transactions'])->toBe(2)
        ->and($category['mean_minutes'])->toEqual(60)
        ->and($category['target_minutes'])->toBe(120)
        ->and($category['meets_target'])->toBeTrue()
        ->and($category['unmeasured_transactions'])->toBe(0);

    expect($response->json('totals.total_minutes'))->toBe(120)
        ->and($response->json('totals.processed_transactions'))->toBe(2);
});

test('a mean above the target does not meet it', function () {
    opcStaff();

    opcCompletedRequest(opcDocType('Certificate of Grades', '45 minute/s'), 90);

    $this->getJson('/api/opcr/performance?from=2026-03-01&to=2026-03-31')
        ->assertOk()
        ->assertJsonPath('categories.0.target_minutes', 45)
        ->assertJsonPath('categories.0.meets_target', false);
});

test('forfeited requests and requests outside the period are not counted', function () {
    opcStaff();

    $type = opcDocType();

    $forfeited = opcCompletedRequest($type, 30);
    $forfeited->update(['status_id' => RequestStatusEnum::Forfeited->value]);

    opcCompletedRequest($type, 60);

    $this->getJson('/api/opcr/performance?from=2026-04-01&to=2026-06-30')
        ->assertOk()
        ->assertJsonPath('totals.processed_transactions', 0);

    $this->getJson('/api/opcr/performance?from=2026-03-01&to=2026-03-31')
        ->assertOk()
        ->assertJsonPath('totals.processed_transactions', 1)
        ->assertJsonPath('totals.total_minutes', 60);
});

test('a transaction with no usable history is flagged as unmeasured, not averaged', function () {
    opcStaff();

    $type    = opcDocType();
    $request = opcCompletedRequest($type, 30);
    RequestHistory::where('request_id', $request->request_id)->delete();

    $this->getJson('/api/opcr/performance?from=2026-03-01&to=2026-03-31')
        ->assertOk()
        ->assertJsonPath('categories.0.processed_transactions', 0)
        ->assertJsonPath('categories.0.unmeasured_transactions', 1)
        ->assertJsonPath('categories.0.mean_minutes', null);
});

// ── Logbook payload ──────────────────────────────────────────────────────────

test('the logbook returns the official processing time per request and per item', function () {
    opcStaff();

    $type    = opcDocType();
    $request = opcCompletedRequest($type, 45);

    $row = collect($this->getJson('/api/document-requests/logbook')->assertOk()->json('data'))
        ->firstWhere('request_id', $request->request_id);

    expect($row['processing']['minutes'])->toBe(45)
        ->and($row['processing']['end_status_id'])->toBe(RequestStatusEnum::PendingSignature->value)
        ->and($row['processing']['processed_at'])->not->toBeNull()
        ->and($row['processing']['claimed_at'])->not->toBeNull()
        ->and($row['items_processing'])->toHaveCount(1)
        ->and($row['items_processing'][0]['type'])->toBe('document')
        ->and($row['items_processing'][0]['minutes'])->toBe(45);
});
