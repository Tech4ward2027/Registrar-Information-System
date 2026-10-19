<?php

use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Models\RequestPurpose;
use App\Models\RequestStatus;
use App\Models\StudentAcademicRecord;
use App\Models\StudentProfile;
use App\Models\SystemUser;
use App\Services\CashierService;
use App\Services\CashierDocumentMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// ── Helpers ───────────────────────────────────────────────────────────────────

function makeCashierStudent(): array
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

function seedCashierReferenceData(): array
{
    RequestStatus::firstOrCreate(['status_id' => 1], ['status_name' => 'Processing']);
    RequestStatus::firstOrCreate(['status_id' => 2], ['status_name' => 'Ready to Claim']);
    RequestStatus::firstOrCreate(['status_id' => 3], ['status_name' => 'Completed']);
    RequestStatus::firstOrCreate(['status_id' => 4], ['status_name' => 'Forfeited']);
    RequestStatus::firstOrCreate(['status_id' => 5], ['status_name' => 'Cancelled']);
    $purpose = RequestPurpose::firstOrCreate(['request_purpose_id' => 1], ['purpose_name' => 'DFA']);

    // Retrieve or update ID 1 so its name and patterns explicitly match mock receipt lines
    $docType = DocumentType::where('document_type_id', 1)->first();
    if ($docType) {
        $docType->update([
            'document_name' => 'Transcript of Records',
            'cashier_document_patterns' => json_encode(['Transcript of Records']),
        ]);
    } else {
        $docType = DocumentType::create([
            'document_type_id'          => 1,
            'document_name'             => 'Transcript of Records',
            'document_description'      => '',
            'document_process_period'   => 5,
            'access_id'                 => 1,
            'cashier_document_patterns' => json_encode(['Transcript of Records']),
        ]);
    }

    return compact('purpose', 'docType');
}

// ═════════════════════════════════════════════════════════════════════════════
// UNIT — CashierService::formatCustomerName
// ═════════════════════════════════════════════════════════════════════════════

test('formatCustomerName reduces middle name to an initial', function () {
    $service = new CashierService();
    expect($service->formatCustomerName('Dela Cruz', 'Juan', 'Santos'))
        ->toBe('DELA CRUZ, JUAN S.');
});

test('formatCustomerName handles empty middle name', function () {
    $service = new CashierService();
    expect($service->formatCustomerName('Reyes', 'Maria', ''))
        ->toBe('REYES, MARIA');
});

test('formatCustomerName appends suffix with period', function () {
    $service = new CashierService();
    expect($service->formatCustomerName('Guevarra', 'Pedro', '', 'Jr'))
        ->toBe('GUEVARRA, PEDRO JR.');
});

test('formatCustomerName does not double-add period to suffix', function () {
    $service = new CashierService();
    expect($service->formatCustomerName('Santos', 'Jose', '', 'Sr.'))
        ->toBe('SANTOS, JOSE SR.');
});

test('formatCustomerName combines middle initial and suffix', function () {
    $service = new CashierService();
    expect($service->formatCustomerName('Mendoza', 'Sabeniano James Martin', 'Alonzo', ''))
        ->toBe('MENDOZA, SABENIANO JAMES MARTIN A.');
});

test('formatCustomerName never emits a full middle name (regression: incident 2026-08-11)', function () {
    $service = new CashierService();
    $formatted = $service->formatCustomerName('Romano', 'Jefferson', 'Camero', '');

    expect($formatted)->toBe('ROMANO, JEFFERSON C.')
        ->and($formatted)->not->toContain('CAMERO');
});

// ═════════════════════════════════════════════════════════════════════════════
// UNIT — CashierService::verifyPayment — mock mode
// ═════════════════════════════════════════════════════════════════════════════

test('verifyPayment returns mock valid response when no API key is set', function () {
    config(['services.cashier.api_key' => '']);

    $service = new CashierService();
    $result  = $service->verifyPayment('1234567', 'DELA CRUZ, JUAN SANTOS');

    expect($result['valid'])->toBeTrue()
        ->and($result['reason'])->toBeNull()
        ->and($result['data']['_mock'])->toBeTrue()
        ->and($result['data']['receipt_number'])->toBe(1234567);
});

// ═════════════════════════════════════════════════════════════════════════════
// UNIT — CashierService::verifyPayment — live mode (HTTP faked)
// ═════════════════════════════════════════════════════════════════════════════

test('verifyPayment returns valid true when cashier API confirms OR', function () {
    config(['services.cashier.api_key' => 'test-key']);

    Http::fake([
        '*' => Http::response([
            'valid'  => true,
            'reason' => null,
            'data'   => ['receipt_number' => 1234567, 'items' => []],
        ], 200),
    ]);

    $service = new CashierService();
    $result  = $service->verifyPayment('1234567', 'DELA CRUZ, JUAN SANTOS');

    expect($result['valid'])->toBeTrue()
        ->and($result['reason'])->toBeNull();
});

test('verifyPayment returns valid false with NOT_FOUND when OR does not exist', function () {
    config(['services.cashier.api_key' => 'test-key']);

    Http::fake([
        '*' => Http::response(['valid' => false, 'reason' => 'NOT_FOUND', 'data' => null], 200),
    ]);

    $service = new CashierService();
    $result  = $service->verifyPayment('0000000', 'DELA CRUZ, JUAN SANTOS');

    expect($result['valid'])->toBeFalse()
        ->and($result['reason'])->toBe('NOT_FOUND');
});

test('verifyPayment returns API_ERROR on server error response', function () {
    config(['services.cashier.api_key' => 'test-key']);

    Http::fake(['*' => Http::response([], 500)]);

    $service = new CashierService();
    $result  = $service->verifyPayment('1234567', 'DELA CRUZ, JUAN SANTOS');

    expect($result['valid'])->toBeFalse()
        ->and($result['reason'])->toBe('API_ERROR');
});

test('verifyPayment returns API_ERROR on connection failure', function () {
    config(['services.cashier.api_key' => 'test-key']);

    Http::fake(['*' => function () {
        throw new \Illuminate\Http\Client\ConnectionException('Connection refused');
    }]);

    $service = new CashierService();
    $result  = $service->verifyPayment('1234567', 'DELA CRUZ, JUAN SANTOS');

    expect($result['valid'])->toBeFalse()
        ->and($result['reason'])->toBe('API_ERROR');
});

// ═════════════════════════════════════════════════════════════════════════════
// UNIT — CashierService::isOrAlreadyUsed
// ═════════════════════════════════════════════════════════════════════════════

test('isOrAlreadyUsed returns false when single-use is disabled', function () {
    config(['services.cashier.single_use' => false]);

    DocumentRequest::factory()->create(['or_number' => '9999999']);

    $service = new CashierService();
    expect($service->isOrAlreadyUsed('9999999'))->toBeFalse();
});

test('isOrAlreadyUsed returns true when OR is already on another request', function () {
    config(['services.cashier.single_use' => true]);

    DocumentRequest::factory()->create(['or_number' => '9999999']);

    $service = new CashierService();
    expect($service->isOrAlreadyUsed('9999999'))->toBeTrue();
});

test('isOrAlreadyUsed returns false when OR is only on the excluded request', function () {
    config(['services.cashier.single_use' => true]);

    $existing = DocumentRequest::factory()->create(['or_number' => '9999999']);

    $service = new CashierService();
    expect($service->isOrAlreadyUsed('9999999', $existing->request_id))->toBeFalse();
});

test('isOrAlreadyUsed returns false when OR has not been used at all', function () {
    config(['services.cashier.single_use' => true]);

    $service = new CashierService();
    expect($service->isOrAlreadyUsed('1111111'))->toBeFalse();
});

// ═════════════════════════════════════════════════════════════════════════════
// UNIT — CashierDocumentMatcher::match
// ═════════════════════════════════════════════════════════════════════════════

test('matcher returns valid when receipt contains matching document with sufficient quantity', function () {
    $matcher = new CashierDocumentMatcher();

    $docType = DocumentType::create(['document_name' => 'Transcript of Records', 'document_description' => '', 'cashier_document_patterns' => ['Transcript of Records'], 'document_process_period' => 5, 'access_id' => 1]);

    $result = $matcher->match(
        cashierItems: [['document' => 'Transcript of Records', 'quantity' => 2]],
        documents:    [['document_type_id' => $docType->document_type_id, 'number_of_copies' => 2]],
        certificates: [],
    );

    expect($result['valid'])->toBeTrue();
});

test('matcher is case-insensitive when matching receipt labels', function () {
    $matcher = new CashierDocumentMatcher();

    $docType = DocumentType::create(['document_name' => 'Transcript of Records', 'document_description' => '', 'cashier_document_patterns' => ['transcript of records'], 'document_process_period' => 5, 'access_id' => 1]);

    $result = $matcher->match(
        cashierItems: [['document' => 'TRANSCRIPT OF RECORDS', 'quantity' => 1]],
        documents:    [['document_type_id' => $docType->document_type_id, 'number_of_copies' => 1]],
        certificates: [],
    );

    expect($result['valid'])->toBeTrue();
});

test('matcher returns invalid when receipt does not contain the requested document', function () {
    $matcher = new CashierDocumentMatcher();

    $docType = DocumentType::create(['document_name' => 'Transcript of Records', 'document_description' => '', 'cashier_document_patterns' => ['Transcript of Records'], 'document_process_period' => 5, 'access_id' => 1]);

    $result = $matcher->match(
        cashierItems: [['document' => 'Diploma - 2nd Copy', 'quantity' => 1]],
        documents:    [['document_type_id' => $docType->document_type_id, 'number_of_copies' => 1]],
        certificates: [],
    );

    expect($result['valid'])->toBeFalse()
        ->and($result['errors'])->toHaveKey("documents.{$docType->document_type_id}");
});

test('matcher returns invalid when receipt quantity is less than requested copies', function () {
    $matcher = new CashierDocumentMatcher();

    $docType = DocumentType::create(['document_name' => 'Transcript of Records', 'document_description' => '', 'cashier_document_patterns' => ['Transcript of Records'], 'document_process_period' => 5, 'access_id' => 1]);

    $result = $matcher->match(
        cashierItems: [['document' => 'Transcript of Records', 'quantity' => 1]],
        documents:    [['document_type_id' => $docType->document_type_id, 'number_of_copies' => 3]],
        certificates: [],
    );

    expect($result['valid'])->toBeFalse()
        ->and($result['errors'])->toHaveKey("documents.{$docType->document_type_id}")
        ->and($result['errors']["documents.{$docType->document_type_id}"][0])
            ->toContain('1 copy')
            ->toContain('requested 3');
});

test('matcher skips document types with null patterns', function () {
    $matcher = new CashierDocumentMatcher();

    $docType = DocumentType::create(['document_name' => 'Good Moral Certificate', 'document_description' => '', 'cashier_document_patterns' => null, 'document_process_period' => 5, 'access_id' => 1]);

    $result = $matcher->match(
        cashierItems: [],
        documents:    [['document_type_id' => $docType->document_type_id, 'number_of_copies' => 1]],
        certificates: [],
    );

    expect($result['valid'])->toBeTrue();
});

test('matcher sums quantities when same label appears multiple times on receipt', function () {
    $matcher = new CashierDocumentMatcher();

    $docType = DocumentType::create(['document_name' => 'Transcript of Records', 'document_description' => '', 'cashier_document_patterns' => ['Transcript of Records'], 'document_process_period' => 5, 'access_id' => 1]);

    $result = $matcher->match(
        cashierItems: [
            ['document' => 'Transcript of Records', 'quantity' => 1],
            ['document' => 'Transcript of Records', 'quantity' => 2],
        ],
        documents:    [['document_type_id' => $docType->document_type_id, 'number_of_copies' => 3]],
        certificates: [],
    );

    expect($result['valid'])->toBeTrue();
});

// ═════════════════════════════════════════════════════════════════════════════
// INTEGRATION — OR validation via POST /api/document-requests
// ═════════════════════════════════════════════════════════════════════════════

test('document request is accepted in mock mode without a real OR', function () {
    config(['services.cashier.api_key' => '']);

    makeCashierStudent();
    ['purpose' => $purpose, 'docType' => $docType] = seedCashierReferenceData();

    $this->postJson('/api/v1/document-requests', [
        'request_purpose_id' => $purpose->request_purpose_id,
        'or_number'          => '1234567',
        'receipt_date'       => now()->toDateString(),
        'documents'          => [['document_type_id' => $docType->document_type_id, 'number_of_copies' => 1]],
    ])->assertCreated();
});

test('document request is rejected when OR is not found in cashier API', function () {
    config(['services.cashier.api_key' => 'test-key']);

    Http::fake(['*' => Http::response(['valid' => false, 'reason' => 'NOT_FOUND', 'data' => null], 200)]);

    makeCashierStudent();
    ['purpose' => $purpose, 'docType' => $docType] = seedCashierReferenceData();

    $this->postJson('/api/v1/document-requests', [
        'request_purpose_id' => $purpose->request_purpose_id,
        'or_number'          => '0000000',
        'receipt_date'       => now()->toDateString(),
        'documents'          => [['document_type_id' => $docType->document_type_id, 'number_of_copies' => 1]],
    ])->assertStatus(422)
      ->assertJsonPath('errors.or_number.0', fn ($msg) => str_contains($msg, 'could not be found'));
});

test('document request is rejected when cashier API is down', function () {
    config(['services.cashier.api_key' => 'test-key']);

    Http::fake(['*' => Http::response([], 500)]);

    makeCashierStudent();
    ['purpose' => $purpose, 'docType' => $docType] = seedCashierReferenceData();

    $this->postJson('/api/v1/document-requests', [
        'request_purpose_id' => $purpose->request_purpose_id,
        'or_number'          => '1234567',
        'receipt_date'       => now()->toDateString(),
        'documents'          => [['document_type_id' => $docType->document_type_id, 'number_of_copies' => 1]],
    ])->assertStatus(422)
      ->assertJsonPath('errors.or_number.0', fn ($msg) => str_contains($msg, 'temporarily unavailable'));
});

test('document request is rejected when OR number is already used', function () {
    config([
        'services.cashier.api_key'    => '',
        'services.cashier.single_use' => true,
    ]);

    ['user' => $user] = makeCashierStudent();
    ['purpose' => $purpose, 'docType' => $docType] = seedCashierReferenceData();

    DocumentRequest::factory()->create(['or_number' => '9999999', 'user_id' => $user->user_id]);

    $this->postJson('/api/v1/document-requests', [
        'request_purpose_id' => $purpose->request_purpose_id,
        'or_number'          => '9999999',
        'receipt_date'       => now()->toDateString(),
        'documents'          => [['document_type_id' => $docType->document_type_id, 'number_of_copies' => 1]],
    ])->assertStatus(422)
      ->assertJsonPath('errors.or_number.0', fn ($msg) => str_contains($msg, 'already been used'));
});

test('document request succeeds when cashier only accepts the full-middle-name format', function () {
    config(['services.cashier.api_key' => 'test-key']);

    ['user' => $user, 'profile' => $profile] = makeCashierStudent();
    ['purpose' => $purpose, 'docType' => $docType] = seedCashierReferenceData();

    Http::fake(function ($request) {
        $body = $request->data();

        if (($body['customer_name'] ?? null) === 'DELA CRUZ, JUAN SANTOS') {
            return Http::response([
                'valid'  => true,
                'reason' => null,
                'data'   => [
                    'receipt_number'   => 1234567,
                    'customer_name'    => 'DELA CRUZ, JUAN SANTOS',
                    'transaction_date' => now()->toDateTimeString(),
                    'items'            => [
                        ['document' => 'Transcript of Records', 'quantity' => 1]
                    ],
                ],
            ], 200);
        }

        return Http::response(['valid' => false, 'reason' => 'NOT_FOUND', 'data' => null], 200);
    });

    $this->postJson('/api/v1/document-requests', [
        'request_purpose_id' => $purpose->request_purpose_id,
        'or_number'          => '1234567',
        'receipt_date'       => now()->toDateString(),
        'documents'          => [['document_type_id' => $docType->document_type_id, 'number_of_copies' => 1]],
    ])->assertCreated();

    $log = \App\Models\AuditLog::where('action', \App\Models\AuditLog::ACTION_CASHIER_VERIFICATION)
        ->latest('created_at')
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->metadata['final_approved'])->toBeTrue()
        ->and(count($log->metadata['attempts']))->toBeGreaterThan(1)
        ->and($log->metadata['matched_name'])->toBe('DELA CRUZ, JUAN SANTOS');
});

test('document request is rejected and every candidate attempt is logged when no format matches', function () {
    config(['services.cashier.api_key' => 'test-key']);

    ['user' => $user] = makeCashierStudent();
    ['purpose' => $purpose, 'docType' => $docType] = seedCashierReferenceData();

    Http::fake([
        '*' => Http::response(['valid' => false, 'reason' => 'NOT_FOUND', 'data' => null], 200),
    ]);

    $this->postJson('/api/v1/document-requests', [
        'request_purpose_id' => $purpose->request_purpose_id,
        'or_number'          => '0000000',
        'receipt_date'       => now()->toDateString(),
        'documents'          => [['document_type_id' => $docType->document_type_id, 'number_of_copies' => 1]],
    ])->assertStatus(422);

    $log = \App\Models\AuditLog::where('action', \App\Models\AuditLog::ACTION_CASHIER_VERIFICATION)
        ->latest('created_at')
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->metadata['final_approved'])->toBeFalse()
        ->and($log->metadata['matched_name'])->toBeNull()
        ->and(count($log->metadata['attempts']))->toBeGreaterThan(1);
});

test('document request with no OR number is accepted when OR is optional', function () {
    config(['services.cashier.api_key' => '']);

    makeCashierStudent();
    ['purpose' => $purpose, 'docType' => $docType] = seedCashierReferenceData();

    $this->postJson('/api/v1/document-requests', [
        'request_purpose_id' => $purpose->request_purpose_id,
        'documents'          => [['document_type_id' => $docType->document_type_id, 'number_of_copies' => 1]],
    ])->assertCreated();
});