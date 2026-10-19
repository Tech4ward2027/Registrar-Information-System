<?php

use App\DTOs\Ogos\OgosPersonalInfoDTO;
use App\DTOs\Ogos\OgosStudentDTO;
use App\Models\AuditLog;
use App\Models\DocumentType;
use App\Models\RequestPurpose;
use App\Models\RequestStatus;
use App\Models\StudentAcademicRecord;
use App\Models\StudentProfile;
use App\Models\SystemUser;
use App\Services\Ogos\OgosClient;
use App\Services\Ogos\OgosStudentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// ── Local helpers ────────────────────────────────────────────────────────

function suffixTestMakeAdmin(): SystemUser
{
    $admin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN, 'status' => 'Activated']);
    Sanctum::actingAs($admin);
    return $admin;
}

function suffixTestOgosPayload(array $overrides = []): array
{
    return array_merge([
        'studentNumber' => '2026-00158-TG-0',
        'email'         => 'joege.nono@example.com',
        'firstName'     => 'Joege',
        'middleName'    => 'Catayen',
        'lastName'      => 'Nono',
        'suffixName'    => 'Jr.',
        'mobileNumber'  => '09171234567',
        'program'       => ['id' => 1, 'code' => 'BSIT', 'name' => 'BS Information Technology'],
        'yearLevel'     => 3,
        'section'       => '3A',
    ], $overrides);
}

function suffixTestMockOgosSuccess(string $email, array $payloadOverrides = []): void
{
    $dto = OgosStudentDTO::fromArray(suffixTestOgosPayload(array_merge(['email' => $email], $payloadOverrides)));

    $ogosClient = Mockery::mock(OgosClient::class);
    $ogosClient->shouldReceive('getStudentByEmail')->once()->with($email)->andReturn($dto);
    $ogosClient->shouldReceive('getStudentPersonalInfo')->once()->andReturn(
        new OgosPersonalInfoDTO(
            studentNumber: $dto->studentNumber,
            gender:        'Male',
            dateOfBirth:   '2002-05-14',
            placeOfBirth:  'Manila',
            heightFt:      null,
            weightKg:      null,
        )
    );
    $ogosClient->shouldReceive('getStudentAddresses')->once()->andReturn([]);

    app()->instance(OgosStudentService::class, new OgosStudentService($ogosClient));
}

function suffixTestSeedCashierRefData(): array
{
    foreach ([1 => 'Processing', 2 => 'Ready to Claim', 3 => 'Completed', 4 => 'Forfeited', 5 => 'Cancelled'] as $id => $name) {
        RequestStatus::firstOrCreate(['status_id' => $id], ['status_name' => $name]);
    }
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

// ═════════════════════════════════════════════════════════════════════════
// DTO — OgosStudentDTO::fromArray() reads the real `suffixName` key.
// ═════════════════════════════════════════════════════════════════════════

test('OgosStudentDTO reads suffix from the suffixName key', function () {
    $dto = OgosStudentDTO::fromArray(suffixTestOgosPayload(['suffixName' => 'Jr.']));

    expect($dto->suffix)->toBe('Jr.');
});

test('OgosStudentDTO treats an empty suffixName as no suffix, same as middleName', function () {
    $dto = OgosStudentDTO::fromArray(suffixTestOgosPayload(['suffixName' => '']));

    expect($dto->suffix)->toBeNull();
});

test('OgosStudentDTO treats a missing suffixName key as no suffix', function () {
    $payload = suffixTestOgosPayload();
    unset($payload['suffixName']);

    $dto = OgosStudentDTO::fromArray($payload);

    expect($dto->suffix)->toBeNull();
});

// ═════════════════════════════════════════════════════════════════════════
// SYNC — OgosStudentService writes the parsed suffix onto student_profile
// ═════════════════════════════════════════════════════════════════════════

test('OGOS login sync writes the suffix onto a new student_profile row', function () {
    $user = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT]);

    suffixTestMockOgosSuccess($user->email, ['suffixName' => 'Jr.']);

    app(OgosStudentService::class)->provisionStudentData($user);

    expect(StudentProfile::where('user_id', $user->user_id)->first()->suffix)->toBe('Jr.');
});

test('OGOS login sync updates the suffix on an existing student_profile row', function () {
    $user = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT]);
    StudentProfile::factory()->create(['user_id' => $user->user_id, 'suffix' => null]);

    suffixTestMockOgosSuccess($user->email, ['suffixName' => 'III']);

    app(OgosStudentService::class)->provisionStudentData($user);

    expect(StudentProfile::where('user_id', $user->user_id)->first()->suffix)->toBe('III');
});

test('OGOS login sync clears suffix when OGOS reports none', function () {
    $user = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT]);
    StudentProfile::factory()->create(['user_id' => $user->user_id, 'suffix' => 'Jr.']);

    suffixTestMockOgosSuccess($user->email, ['suffixName' => '']);

    app(OgosStudentService::class)->provisionStudentData($user);

    expect(StudentProfile::where('user_id', $user->user_id)->first()->suffix)->toBeNull();
});

// ═════════════════════════════════════════════════════════════════════════
// END-TO-END — cashier OR verification with suffix
// ═════════════════════════════════════════════════════════════════════════

test('a suffixed student can be OR-verified once their suffix has synced from OGOS', function () {
    config(['services.cashier.api_key' => 'test-key']);

    $user = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT, 'status' => 'Activated']);

    suffixTestMockOgosSuccess($user->email, [
        'firstName'  => 'Joege',
        'middleName' => 'Catayen',
        'lastName'   => 'Nono',
        'suffixName' => 'Jr.',
    ]);
    app(OgosStudentService::class)->provisionStudentData($user);

    $profile = StudentProfile::where('user_id', $user->user_id)->first();
    StudentAcademicRecord::factory()->create(['student_profile_id' => $profile->student_profile_id]);

    Sanctum::actingAs($user);
    ['purpose' => $purpose, 'docType' => $docType] = suffixTestSeedCashierRefData();

    Http::fake(function ($request) {
        $body = $request->data();

        if (($body['customer_name'] ?? null) === 'NONO JR., JOEGE C.') {
            return Http::response([
                'valid'  => true,
                'reason' => null,
                'data'   => [
                    'receipt_number'   => 1234567,
                    'customer_name'    => 'NONO JR., JOEGE C.',
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

    $log = AuditLog::where('action', AuditLog::ACTION_CASHIER_VERIFICATION)->latest('created_at')->first();

    expect($log)->not->toBeNull()
        ->and($log->metadata['final_approved'])->toBeTrue()
        ->and($log->metadata['matched_name'])->toBe('NONO JR., JOEGE C.');
});

// ═════════════════════════════════════════════════════════════════════════
// STAFF OVERRIDE
// ═════════════════════════════════════════════════════════════════════════

test('admin can set a suffix when updating a student profile', function () {
    suffixTestMakeAdmin();

    $profile = StudentProfile::factory()->create(['suffix' => null]);

    $this->putJson("/api/v1/students/{$profile->student_profile_id}", [
        'suffix' => 'Jr.',
    ])->assertStatus(200);

    expect($profile->fresh()->suffix)->toBe('Jr.');
});

test('admin can set a suffix when creating a student profile', function () {
    suffixTestMakeAdmin();

    $user = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT]);

    $response = $this->postJson('/api/v1/students', [
        'user_id'       => $user->user_id,
        'first_name'    => 'Joege',
        'last_name'     => 'Nono',
        'suffix'        => 'III',
        'date_of_birth' => '2001-01-01',
    ])->assertStatus(201);

    expect($response->json('suffix'))->toBe('III');
    $this->assertDatabaseHas('student_profile', [
        'user_id' => $user->user_id,
        'suffix'  => 'III',
    ]);
});

test('suffix is rejected past the column width', function () {
    suffixTestMakeAdmin();

    $profile = StudentProfile::factory()->create();

    $this->putJson("/api/v1/students/{$profile->student_profile_id}", [
        'suffix' => str_repeat('X', 21),
    ])->assertStatus(422);
});