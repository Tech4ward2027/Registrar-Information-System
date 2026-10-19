<?php

use App\Models\AuditLog;
use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Models\SystemUser;
use App\Models\StudentAcademicRecord;
use App\Models\StudentProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function makeVerifyOrStudent(): array
{
    $user    = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT, 'status' => 'Activated']);
    $profile = StudentProfile::factory()->create([
        'user_id'     => $user->user_id,
        'first_name'  => 'Juan',
        'last_name'   => 'Dela Cruz',
        'middle_name' => 'Santos',
        'suffix'      => '',
    ]);
    StudentAcademicRecord::factory()->create(['student_profile_id' => $profile->student_profile_id]);
    Sanctum::actingAs($user);
    return compact('user', 'profile');
}

// ═════════════════════════════════════════════════════════════════════════════
// Validation
// ═════════════════════════════════════════════════════════════════════════════

test('verify-or requires or_number and receipt_date', function () {
    makeVerifyOrStudent();

    $this->postJson('/api/v1/document-requests/verify-or', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['or_number', 'receipt_date']);
});

test('verify-or accepts a receipt_date older than 7 days', function () {
    // The 7-day lookback window has been removed: any past date is valid,
    // it no longer has to fall within the last week.
    config(['services.cashier.api_key' => '']);
    makeVerifyOrStudent();

    $this->postJson('/api/v1/document-requests/verify-or', [
        'or_number'    => '1234567',
        'receipt_date' => now()->subDays(10)->toDateString(),
    ])->assertOk()
      ->assertJsonPath('valid', true);
});

test('verify-or still rejects a receipt_date in the future', function () {
    makeVerifyOrStudent();

    $this->postJson('/api/v1/document-requests/verify-or', [
        'or_number'    => '1234567',
        'receipt_date' => now()->addDay()->toDateString(),
    ])->assertStatus(422)
      ->assertJsonValidationErrors(['receipt_date']);
});

test('a staff user (role 3) cannot call verify-or', function () {
    $admin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN, 'status' => 'Activated']);
    Sanctum::actingAs($admin);

    $this->postJson('/api/v1/document-requests/verify-or', [
        'or_number'    => '1234567',
        'receipt_date' => now()->toDateString(),
    ])->assertStatus(403);
});

// ═════════════════════════════════════════════════════════════════════════════
// Mock mode — no CASHIER_API_KEY configured
// ═════════════════════════════════════════════════════════════════════════════

test('verify-or succeeds in mock mode with empty suggestions', function () {
    config(['services.cashier.api_key' => '']);
    makeVerifyOrStudent();

    $this->postJson('/api/v1/document-requests/verify-or', [
        'or_number'    => '1234567',
        'receipt_date' => now()->toDateString(),
    ])->assertOk()
      ->assertJson([
          'valid'   => true,
          'is_mock' => true,
      ])
      ->assertJsonPath('suggestions.documents', [])
      ->assertJsonPath('suggestions.certificates', []);
});

// ═════════════════════════════════════════════════════════════════════════════
// Live mode — cashier API faked
// ═════════════════════════════════════════════════════════════════════════════

test('verify-or returns document suggestions derived from the cashier receipt', function () {
    config(['services.cashier.api_key' => 'test-key']);

    $docType = DocumentType::create([
        'document_name'             => 'Test Fixture Grade Copy',
        'document_description'      => '',
        'document_process_period'   => 5,
        'access_id'                 => 1,
        'cashier_document_patterns' => ['Test Fixture Grade Copy'],
    ]);

    Http::fake([
        '*' => Http::response([
            'valid'  => true,
            'reason' => null,
            'data'   => [
                'receipt_number'   => 1000001,
                'customer_name'    => 'DELA CRUZ, JUAN S.',
                'transaction_date' => now()->toDateTimeString(),
                'items' => [
                    ['document' => 'Test Fixture Grade Copy', 'amount' => '150.00', 'quantity' => 1],
                ],
            ],
        ], 200),
    ]);

    makeVerifyOrStudent();

    $response = $this->postJson('/api/v1/document-requests/verify-or', [
        'or_number'    => '1000001',
        'receipt_date' => now()->toDateString(),
    ])->assertOk();

    $response->assertJsonPath('valid', true)
        ->assertJsonPath('is_mock', false)
        ->assertJsonPath('suggestions.documents.0.document_type_id', $docType->document_type_id)
        ->assertJsonPath('suggestions.documents.0.number_of_copies', 1)
        ->assertJsonPath('suggestions.unresolved', []);
});

test('verify-or reports unresolved receipt lines without failing the request', function () {
    config(['services.cashier.api_key' => 'test-key']);

    Http::fake([
        '*' => Http::response([
            'valid'  => true,
            'reason' => null,
            'data'   => [
                'receipt_number'   => 1000001,
                'customer_name'    => 'DELA CRUZ, JUAN S.',
                'transaction_date' => now()->toDateTimeString(),
                'items' => [
                    ['document' => 'Some Unrecognized Fee', 'amount' => '150.00', 'quantity' => 1],
                ],
            ],
        ], 200),
    ]);

    makeVerifyOrStudent();

    $this->postJson('/api/v1/document-requests/verify-or', [
        'or_number'    => '1000001',
        'receipt_date' => now()->toDateString(),
    ])->assertOk()
      ->assertJsonPath('suggestions.documents', [])
      ->assertJsonPath('suggestions.unresolved.0.label', 'Some Unrecognized Fee');
});

test('verify-or fails with the same message as store() when the OR is not found', function () {
    config(['services.cashier.api_key' => 'test-key']);

    Http::fake(['*' => Http::response(['valid' => false, 'reason' => 'NOT_FOUND', 'data' => null], 200)]);

    makeVerifyOrStudent();

    $this->postJson('/api/v1/document-requests/verify-or', [
        'or_number'    => '0000000',
        'receipt_date' => now()->toDateString(),
    ])->assertStatus(422)
      ->assertJsonPath('errors.or_number.0', fn ($msg) => str_contains($msg, 'could not be found'));
});

test('verify-or fails when the cashier API is down', function () {
    config(['services.cashier.api_key' => 'test-key']);

    Http::fake(['*' => Http::response([], 500)]);

    makeVerifyOrStudent();

    $this->postJson('/api/v1/document-requests/verify-or', [
        'or_number'    => '1234567',
        'receipt_date' => now()->toDateString(),
    ])->assertStatus(422)
      ->assertJsonPath('errors.or_number.0', fn ($msg) => str_contains($msg, 'temporarily unavailable'));
});

test('verify-or rejects an OR number already used on a previous request without calling the cashier API', function () {
    config(['services.cashier.api_key' => 'test-key', 'services.cashier.single_use' => true]);

    Http::fake(); // no stub registered — a call here fails the test

    ['user' => $user] = makeVerifyOrStudent();
    DocumentRequest::factory()->create(['or_number' => '9999999', 'user_id' => $user->user_id]);

    $this->postJson('/api/v1/document-requests/verify-or', [
        'or_number'    => '9999999',
        'receipt_date' => now()->toDateString(),
    ])->assertStatus(422)
      ->assertJsonPath('errors.or_number.0', fn ($msg) => str_contains($msg, 'already been used'));

    Http::assertNothingSent();
});

test('verify-or does NOT create a DocumentRequest even on success', function () {
    config(['services.cashier.api_key' => '']);
    makeVerifyOrStudent();

    $this->postJson('/api/v1/document-requests/verify-or', [
        'or_number'    => '1234567',
        'receipt_date' => now()->toDateString(),
    ])->assertOk();

    expect(DocumentRequest::count())->toBe(0);
});

test('verify-or writes the same cashier_verification audit log entry as store()', function () {
    config(['services.cashier.api_key' => '']);
    makeVerifyOrStudent();

    $this->postJson('/api/v1/document-requests/verify-or', [
        'or_number'    => '1234567',
        'receipt_date' => now()->toDateString(),
    ])->assertOk();

    $log = AuditLog::where('action', AuditLog::ACTION_CASHIER_VERIFICATION)->latest('created_at')->first();

    expect($log)->not->toBeNull()
        ->and($log->metadata['final_approved'])->toBeTrue();
});

// ═════════════════════════════════════════════════════════════════════════════
// Throttle — dedicated 10/min guard on top of the group's 60/min
// ═════════════════════════════════════════════════════════════════════════════

test('verify-or is throttled after repeated calls', function () {
    config(['services.cashier.api_key' => '']);
    makeVerifyOrStudent();

    $payload = ['or_number' => '1234567', 'receipt_date' => now()->toDateString()];

    for ($i = 0; $i < 10; $i++) {
        $this->postJson('/api/v1/document-requests/verify-or', $payload)->assertOk();
    }

    $this->postJson('/api/v1/document-requests/verify-or', $payload)->assertStatus(429);
});