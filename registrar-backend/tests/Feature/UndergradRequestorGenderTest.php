<?php

use App\Enums\GenderEnum;
use App\Enums\RequestStatusEnum;
use App\Models\DocumentRequest;
use App\Models\Policy;
use App\Models\StudentAcademicRecord;
use App\Models\StudentProfile;
use App\Models\SystemUser;
use App\Models\UndergradRequestorProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// ═════════════════════════════════════════════════════════════════════════════
// Undergrad Requestor gender — registration input, storage, and the Logbook /
// staff-list payload.
//
// Helper names carry an "ugg" prefix because Pest test files share one global
// function namespace; this file is self-contained and does not depend on the
// helpers in other test files.
// ═════════════════════════════════════════════════════════════════════════════

function uggRegistrationPayload(array $overrides = []): array
{
    return array_merge([
        'email'                     => 'requestor' . uniqid() . '@example.com',
        'first_name'                => 'Juan',
        'middle_name'               => 'Dela',
        'last_name'                 => 'Cruz',
        'suffix'                    => null,
        'student_number'            => '2019-00123-MN-0',
        'program'                   => 'BS Information Technology',
        'last_school_year_attended' => '2021-2022',
        'date_of_birth'             => '2000-05-15',
        'present_address'           => '123 Sample St., Taguig City',
        'reason_for_non_enrollment' => null,
        'phone'                     => '09171234567',
        'data_privacy_consent'      => true,
    ], $overrides);
}

/** Staff user with dashboard access plus logbook View (the logbook route has its own module gate). */
function uggStaff(): SystemUser
{
    $admin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN, 'status' => 'Activated']);

    $policy = Policy::firstOrCreate(
        ['name' => 'Test Dashboard And Logbook Access'],
        ['permissions' => ['dashboard' => ['View', 'Process', 'Complete'], 'logbook' => ['View']], 'is_system' => false]
    );
    $admin->update(['policy_id' => $policy->policy_id]);

    Sanctum::actingAs($admin);

    return $admin;
}

/** A Completed undergrad request so it appears in the Logbook. */
function uggUndergradRequest(?string $gender, array $overrides = []): array
{
    $profile = UndergradRequestorProfile::factory()->create([
        'first_name'                => 'Maria',
        'middle_name'               => null,
        'suffix'                    => null,
        'last_name'                 => 'Santos',
        'student_number'            => '2018-00456',
        'gender'                    => $gender,
        'phone'                     => '09171234567',
        'present_address'           => '12 Confidential Street',
        'reason_for_non_enrollment' => 'Private medical reason',
    ]);

    $request = DocumentRequest::factory()->create(array_merge([
        'user_id'   => $profile->user_id,
        'status_id' => RequestStatusEnum::Completed->value,
    ], $overrides));

    return [$request, $profile];
}

function uggStudentRequest(array $overrides = []): DocumentRequest
{
    $user    = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT, 'status' => 'Activated']);
    $profile = StudentProfile::factory()->create([
        'user_id' => $user->user_id, 'first_name' => 'Juan', 'middle_name' => null, 'last_name' => 'Cruz', 'suffix' => null,
    ]);
    $record  = StudentAcademicRecord::factory()->create([
        'student_profile_id' => $profile->student_profile_id, 'student_number' => '2021-00123',
    ]);

    return DocumentRequest::factory()->create(array_merge([
        'user_id'             => $user->user_id,
        'student_profile_id'  => $profile->student_profile_id,
        'student_academic_id' => $record->student_academic_id,
        'status_id'           => RequestStatusEnum::Completed->value,
    ], $overrides));
}

// ── Registration: input & storage ────────────────────────────────────────────

test('register stores the declared gender', function () {
    Mail::fake();

    $payload = uggRegistrationPayload(['gender' => 'Female']);

    $this->postJson('/api/undergrad-requestors/register', $payload)->assertCreated();

    $user = SystemUser::where('email', $payload['email'])->firstOrFail();

    expect(UndergradRequestorProfile::where('user_id', $user->user_id)->value('gender'))->toBe('Female');
});

test('register normalises gender casing and whitespace before validating', function (string $input, string $expected) {
    Mail::fake();

    $payload = uggRegistrationPayload(['gender' => $input]);

    $this->postJson('/api/undergrad-requestors/register', $payload)->assertCreated();

    $user = SystemUser::where('email', $payload['email'])->firstOrFail();

    expect(UndergradRequestorProfile::where('user_id', $user->user_id)->value('gender'))->toBe($expected);
})->with([
    'lowercase'   => ['male', 'Male'],
    'uppercase'   => ['FEMALE', 'Female'],
    'padded'      => ['  Female  ', 'Female'],
]);

test('register rejects a gender outside the allowed values', function (mixed $input) {
    Mail::fake();

    $response = $this->postJson('/api/undergrad-requestors/register', uggRegistrationPayload(['gender' => $input]));

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['gender'])
        ->assertJsonPath('errors.gender.0', 'Please select a valid gender.');

    expect(UndergradRequestorProfile::count())->toBe(0);
})->with([
    'unknown value' => ['Other'],
    'injection'     => ["Male'; DROP TABLE users;--"],
    'array'         => [['Male']],
    'number'        => [1],
]);

test('register still succeeds without a gender and stores NULL', function (mixed $absent) {
    Mail::fake();

    $payload = uggRegistrationPayload();
    if ($absent !== '__omit__') {
        $payload['gender'] = $absent;
    }

    $this->postJson('/api/undergrad-requestors/register', $payload)->assertCreated();

    $user = SystemUser::where('email', $payload['email'])->firstOrFail();

    expect(UndergradRequestorProfile::where('user_id', $user->user_id)->value('gender'))->toBeNull();
})->with([
    'omitted'      => ['__omit__'],
    'null'         => [null],
    'empty string' => [''],
    'whitespace'   => ['   '],
]);

test('the registration response does not echo gender back to the public caller', function () {
    Mail::fake();

    $this->postJson('/api/undergrad-requestors/register', uggRegistrationPayload(['gender' => 'Male']))
        ->assertCreated()
        ->assertJsonMissingPath('data.gender');
});

// ── Logbook ──────────────────────────────────────────────────────────────────

test('the logbook returns an undergrad requestor\'s gender on the row and on the nested profile', function () {
    uggStaff();
    [$request] = uggUndergradRequest('Female');

    $row = collect($this->getJson('/api/document-requests/logbook')->assertOk()->json('data'))
        ->firstWhere('request_id', $request->request_id);

    expect($row)->not->toBeNull()
        ->and($row['requester_type'])->toBe('Undergrad Requestor')
        ->and($row['gender'])->toBe('Female')
        ->and($row['undergrad_requestor_profile']['gender'])->toBe('Female');
});

test('the logbook returns null gender for an undergrad with no declared gender', function () {
    uggStaff();
    [$request] = uggUndergradRequest(null);

    $row = collect($this->getJson('/api/document-requests/logbook')->assertOk()->json('data'))
        ->firstWhere('request_id', $request->request_id);

    expect($row['gender'])->toBeNull()
        ->and($row['undergrad_requestor_profile']['gender'])->toBeNull();
});

test('the logbook leaves top-level gender null for student rows so sex_at_birth still applies', function () {
    uggStaff();
    $request = uggStudentRequest();

    $row = collect($this->getJson('/api/document-requests/logbook')->assertOk()->json('data'))
        ->firstWhere('request_id', $request->request_id);

    expect($row['requester_type'])->toBe('Student')
        ->and($row['gender'])->toBeNull()
        ->and($row['student_profile'])->toHaveKey('sex_at_birth');
});

test('the staff dashboard list also carries the undergrad gender', function () {
    uggStaff();
    [$request] = uggUndergradRequest('Male');

    $row = collect($this->getJson('/api/document-requests?all_statuses=true')->assertOk()->json('data'))
        ->firstWhere('request_id', $request->request_id);

    expect($row['gender'])->toBe('Male');
});

test('adding gender does not leak encrypted undergrad PII into the logbook', function () {
    uggStaff();
    uggUndergradRequest('Female');

    $response = $this->getJson('/api/document-requests/logbook')->assertOk();
    $body     = $response->getContent();

    expect($body)->not->toContain('09171234567')
                 ->not->toContain('Confidential Street')
                 ->not->toContain('Private medical reason');

    expect($response->json('data.0.undergrad_requestor_profile'))
        ->not->toHaveKeys(['phone', 'present_address', 'date_of_birth', 'reason_for_non_enrollment']);
});

test('the logbook still requires logbook access', function () {
    $user = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT, 'status' => 'Activated']);
    Sanctum::actingAs($user);

    $this->getJson('/api/document-requests/logbook')->assertForbidden();
});

// ── Migration ────────────────────────────────────────────────────────────────

test('the gender migration is idempotent and reversible', function () {
    $migration = require database_path('migrations/2026_10_07_000000_add_gender_to_undergrad_requestor_profiles.php');

    expect(Schema::hasColumn('undergrad_requestor_profiles', 'gender'))->toBeTrue();

    $migration->up();   // second run must be a no-op, not a duplicate-column error
    expect(Schema::hasColumn('undergrad_requestor_profiles', 'gender'))->toBeTrue();

    $migration->down();
    expect(Schema::hasColumn('undergrad_requestor_profiles', 'gender'))->toBeFalse();

    $migration->down(); // down() is also safe to repeat

    $migration->up();
    expect(Schema::hasColumn('undergrad_requestor_profiles', 'gender'))->toBeTrue();
});

// ── GenderEnum ───────────────────────────────────────────────────────────────

test('GenderEnum::normalize canonicalises valid input and leaves bad input for validation to reject', function () {
    expect(GenderEnum::normalize('male'))->toBe('Male')
        ->and(GenderEnum::normalize(' FEMALE '))->toBe('Female')
        ->and(GenderEnum::normalize('Other'))->toBe('Other')
        ->and(GenderEnum::normalize(''))->toBeNull()
        ->and(GenderEnum::normalize('   '))->toBeNull()
        ->and(GenderEnum::normalize(null))->toBeNull()
        ->and(GenderEnum::normalize(['Male']))->toBe(['Male']);

    expect(GenderEnum::values())->toBe(['Male', 'Female']);
});
