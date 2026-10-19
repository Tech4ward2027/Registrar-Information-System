<?php

use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Models\RequestPurpose;
use App\Models\StudentAcademicRecord;
use App\Models\StudentProfile;
use App\Models\SystemUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * These tests exercise the OR-first reorder end-to-end: verify-or's
 * suggestions actually flow into a real store() submission, and the two
 * endpoints' single-use / strict-matching behaviour stays consistent with
 * each other — not just individually correct in isolation (see
 * CashierVerifyEndpointTest.php and CashierTest.php for the isolated
 * cases). This is the integration layer those two files don't cover.
 */
function integrationStudent(): array
{
    $user    = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT, 'status' => 'Activated']);
    $profile = StudentProfile::factory()->create([
        'user_id'     => $user->user_id,
        'first_name'  => 'Aron Stephen',
        'last_name'   => 'Cordova',
        'middle_name' => 'S.',
        'suffix'      => '',
    ]);
    StudentAcademicRecord::factory()->create(['student_profile_id' => $profile->student_profile_id]);
    Sanctum::actingAs($user);
    return compact('user', 'profile');
}

test('a full verify-then-submit flow: suggestions from verify-or successfully create a request via store()', function () {
    config(['services.cashier.api_key' => 'test-key', 'services.cashier.single_use' => true]);

    $docType = DocumentType::create([
        'document_name'             => 'Test Fixture Grade Copy',
        'document_description'      => '',
        'document_process_period'   => 5,
        'access_id'                 => 1,
        'cashier_document_patterns' => ['Test Fixture Grade Copy'],
    ]);
    $purpose = RequestPurpose::create(['purpose_name' => 'Employment']);

    Http::fake([
        '*' => Http::response([
            'valid'  => true,
            'reason' => null,
            'data'   => [
                'receipt_number'   => 1000001,
                'customer_name'    => 'CORDOVA, ARON STEPHEN S.',
                'transaction_date' => now()->toDateTimeString(),
                'items' => [
                    ['document' => 'Test Fixture Grade Copy', 'amount' => '150.00', 'quantity' => 1],
                ],
            ],
        ], 200),
    ]);

    integrationStudent();

    // Step 1: verify-or — this is what the reordered wizard calls first.
    $verifyResponse = $this->postJson('/api/v1/document-requests/verify-or', [
        'or_number'    => '1000001',
        'receipt_date' => now()->toDateString(),
    ])->assertOk();

    $suggestedDoc = $verifyResponse->json('suggestions.documents.0');
    expect($suggestedDoc['document_type_id'])->toBe($docType->document_type_id);

    // No request exists yet — verify-or must never create one.
    expect(DocumentRequest::count())->toBe(0);

    // Step 2: the frontend pre-fills the Documents step from that
    // suggestion, the student confirms, and store() submits exactly what
    // was suggested (this is what handleSubmit() in RequestForm.jsx
    // actually sends).
    $storeResponse = $this->postJson('/api/v1/document-requests', [
        'request_purpose_id' => $purpose->request_purpose_id,
        'or_number'           => '1000001',
        'receipt_date'        => now()->toDateString(),
        'documents' => [
            [
                'document_type_id' => $suggestedDoc['document_type_id'],
                'number_of_copies' => $suggestedDoc['number_of_copies'],
            ],
        ],
        'certificates' => [],
    ])->assertStatus(201);

    expect(DocumentRequest::count())->toBe(1)
        ->and(DocumentRequest::first()->or_number)->toBe('1000001');
});

test('after a successful submission, a second verify-or on the same OR is rejected without re-hitting the cashier API', function () {
    config(['services.cashier.api_key' => 'test-key', 'services.cashier.single_use' => true]);

    $docType = DocumentType::create([
        'document_name'             => 'Test Fixture Grade Copy',
        'document_description'      => '',
        'document_process_period'   => 5,
        'access_id'                 => 1,
        'cashier_document_patterns' => ['Test Fixture Grade Copy'],
    ]);
    $purpose = RequestPurpose::create(['purpose_name' => 'Employment']);

    Http::fake([
        '*' => Http::response([
            'valid' => true, 'reason' => null,
            'data' => [
                'receipt_number' => 1000001, 'customer_name' => 'CORDOVA, ARON STEPHEN S.',
                'transaction_date' => now()->toDateTimeString(),
                'items' => [['document' => 'Test Fixture Grade Copy', 'amount' => '150.00', 'quantity' => 1]],
            ],
        ], 200),
    ]);

    integrationStudent();

    // Verify, then submit — OR is now "used" (DocumentRequest row exists).
    $this->postJson('/api/v1/document-requests/verify-or', [
        'or_number' => '1000001', 'receipt_date' => now()->toDateString(),
    ])->assertOk();

    $this->postJson('/api/v1/document-requests', [
        'request_purpose_id' => $purpose->request_purpose_id,
        'or_number'           => '1000001',
        'receipt_date'        => now()->toDateString(),
        'documents'           => [['document_type_id' => $docType->document_type_id, 'number_of_copies' => 1]],
        'certificates'        => [],
    ])->assertStatus(201);

    Http::fake(); // reset the fake — an unexpected call now fails the test

    // A second student somehow tries the same OR — single-use must catch
    // this at verify-or, the same way it already does at store().
    $secondUser    = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT, 'status' => 'Activated']);
    $secondProfile = StudentProfile::factory()->create(['user_id' => $secondUser->user_id]);
    StudentAcademicRecord::factory()->create(['student_profile_id' => $secondProfile->student_profile_id]);
    Sanctum::actingAs($secondUser);

    $this->postJson('/api/v1/document-requests/verify-or', [
        'or_number' => '1000001', 'receipt_date' => now()->toDateString(),
    ])->assertStatus(422)
      ->assertJsonPath('errors.or_number.0', fn ($msg) => str_contains($msg, 'already been used'));

    Http::assertNothingSent();
});

test('store() still strictly rejects a submission that adds an item verify-or never suggested', function () {
    // The suggestion tier is soft; the money gate is not. Verifying an OR
    // and then hand-picking something the receipt never paid for must
    // still fail at final submit exactly as it did before this feature.
    config(['services.cashier.api_key' => 'test-key']);

    $paidFor   = DocumentType::create([
        'document_name' => 'Test Fixture Grade Copy', 'document_description' => '',
        'document_process_period' => 5, 'access_id' => 1,
        'cashier_document_patterns' => ['Test Fixture Grade Copy'],
    ]);
    $notPaidFor = DocumentType::create([
        'document_name' => 'Test Fixture Transcript', 'document_description' => '',
        'document_process_period' => 5, 'access_id' => 1,
        'cashier_document_patterns' => ['Test Fixture Transcript'],
    ]);
    $purpose = RequestPurpose::create(['purpose_name' => 'Employment']);

    Http::fake([
        '*' => Http::response([
            'valid' => true, 'reason' => null,
            'data' => [
                'receipt_number' => 1000001, 'customer_name' => 'CORDOVA, ARON STEPHEN S.',
                'transaction_date' => now()->toDateTimeString(),
                'items' => [['document' => 'Test Fixture Grade Copy', 'amount' => '150.00', 'quantity' => 1]],
            ],
        ], 200),
    ]);

    integrationStudent();

    $this->postJson('/api/v1/document-requests/verify-or', [
        'or_number' => '1000001', 'receipt_date' => now()->toDateString(),
    ])->assertOk()
      ->assertJsonPath('suggestions.documents.0.document_type_id', $paidFor->document_type_id);

    // Student manually adds the un-paid-for document alongside the
    // suggested one before hitting Submit.
    $this->postJson('/api/v1/document-requests', [
        'request_purpose_id' => $purpose->request_purpose_id,
        'or_number'           => '1000001',
        'receipt_date'        => now()->toDateString(),
        'documents' => [
            ['document_type_id' => $paidFor->document_type_id,    'number_of_copies' => 1],
            ['document_type_id' => $notPaidFor->document_type_id, 'number_of_copies' => 1],
        ],
        'certificates' => [],
    ])->assertStatus(422);

    expect(DocumentRequest::count())->toBe(0);
});