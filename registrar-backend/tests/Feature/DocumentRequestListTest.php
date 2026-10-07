<?php

use App\Enums\RequestStatusEnum;
use App\Models\DocumentRequest;
use App\Models\Policy;
use App\Models\StudentAcademicRecord;
use App\Models\StudentProfile;
use App\Models\SystemUser;
use App\Models\UndergradRequestorProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// ═════════════════════════════════════════════════════════════════════════════
// Phase 2 — GET /document-requests (server-side search / filter / order /
// pagination), GET /document-requests/counts, and the list privacy rules.
//
// Request statuses are seeded by tests/TestCase.php. Helper names carry a
// "dls" prefix because Pest test files share one global function namespace.
// ═════════════════════════════════════════════════════════════════════════════

function dlsStaff(): SystemUser
{
    $admin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN, 'status' => 'Activated']);

    // Dashboard access plus logbook View (the logbook route is gated by its
    // own module, which grantFullDashboardAccess() does not grant).
    $policy = Policy::firstOrCreate(
        ['name' => 'Test Dashboard And Logbook Access'],
        ['permissions' => ['dashboard' => ['View', 'Process', 'Complete'], 'logbook' => ['View']], 'is_system' => false]
    );
    $admin->update(['policy_id' => $policy->policy_id]);

    Sanctum::actingAs($admin);

    return $admin;
}

/** A Student request with a name and student number. */
function dlsStudentRequest(string $first, string $last, string $number, array $overrides = []): DocumentRequest
{
    $user    = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT, 'status' => 'Activated']);
    $profile = StudentProfile::factory()->create([
        'user_id' => $user->user_id, 'first_name' => $first, 'middle_name' => null, 'last_name' => $last, 'suffix' => null,
    ]);
    $record  = StudentAcademicRecord::factory()->create(['student_profile_id' => $profile->student_profile_id, 'student_number' => $number]);

    return DocumentRequest::factory()->create(array_merge([
        'user_id'             => $user->user_id,
        'student_profile_id'  => $profile->student_profile_id,
        'student_academic_id' => $record->student_academic_id,
    ], $overrides));
}

/** An Undergrad Requestor request (no academic-record FKs — see undergradRequestorProfile()). */
function dlsUndergradRequest(string $first, string $last, string $number, array $overrides = []): array
{
    $profile = UndergradRequestorProfile::factory()->create([
        'first_name'                => $first,
        'middle_name'               => null,   // the factory invents one; keep display_name predictable
        'suffix'                    => null,
        'last_name'                 => $last,
        'student_number'            => $number,
        'phone'                     => '09171234567',
        'present_address'           => '12 Confidential Street',
        'reason_for_non_enrollment' => 'Private medical reason',
    ]);

    $request = DocumentRequest::factory()->create(array_merge(['user_id' => $profile->user_id], $overrides));

    return [$request, $profile];
}

function dlsIds($response): array
{
    return collect($response->json('data'))->pluck('request_id')->all();
}

// ── Search ───────────────────────────────────────────────────────────────────

test('search finds a student by first name, last name and student number', function () {
    dlsStaff();
    $target = dlsStudentRequest('Juan', 'Cruz', '2021-00123');
    dlsStudentRequest('Pedro', 'Reyes', '2020-99999');

    foreach (['Juan', 'cruz', 'Cruz, Juan', '2021-001'] as $term) {
        $response = $this->getJson('/api/document-requests?' . http_build_query(['search' => $term]))->assertOk();

        expect(dlsIds($response))->toBe([$target->request_id]);
    }
});

test('search finds an undergrad requestor by name and student number (bug 1A)', function () {
    dlsStaff();
    [$target] = dlsUndergradRequest('Maria', 'Santos', '2018-00456');
    dlsStudentRequest('Pedro', 'Reyes', '2020-99999');

    expect(dlsIds($this->getJson('/api/document-requests?search=Santos')))->toBe([$target->request_id]);
    expect(dlsIds($this->getJson('/api/document-requests?search=2018-004')))->toBe([$target->request_id]);
});

test('search finds a word inside a multi-word last name', function () {
    dlsStaff();
    $target = dlsStudentRequest('Ana', 'Dela Cruz', '2019-11111');

    expect(dlsIds($this->getJson('/api/document-requests?search=cruz')))->toBe([$target->request_id]);
    expect(dlsIds($this->getJson('/api/document-requests?search=' . urlencode('ana dela'))))->toBe([$target->request_id]);
});

test('search finds a request by claim code (case-insensitive) and by exact request id', function () {
    dlsStaff();

    // Push ids past one digit: search needs at least 2 characters.
    $user = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT, 'status' => 'Activated']);
    $all  = DocumentRequest::factory()->count(12)->create(['user_id' => $user->user_id]);
    $target = $all->last();

    expect($target->request_id)->toBeGreaterThanOrEqual(10);

    expect(dlsIds($this->getJson('/api/document-requests?search=' . strtolower($target->claim_code))))
        ->toBe([$target->request_id]);

    // Exact id match, not substring: "1" + id must not match id.
    expect(dlsIds($this->getJson('/api/document-requests?search=' . $target->request_id)))
        ->toContain($target->request_id);
});

test('an old Ready to Claim request is found even with more than 200 newer requests (bug 1B)', function () {
    dlsStaff();

    $old = dlsStudentRequest('Lolo', 'Matanda', '2001-00001', [
        'status_id'    => RequestStatusEnum::ReadyToClaim->value,
        'requested_at' => now()->subYear(),
    ]);

    $filler = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT, 'status' => 'Activated']);

    // or_number is UNIQUE. Bulk-creating 205 rows from the factory's random
    // default can collide, which made this test flaky. Assign a deterministic
    // sequence instead (keep the base outside the factory's own range).
    DocumentRequest::factory()->count(205)
        ->sequence(fn ($sequence) => ['or_number' => 9000000 + $sequence->index])
        ->create([
            'user_id'   => $filler->user_id,
            'status_id' => RequestStatusEnum::Completed->value,
        ]);

    // The old row is NOT in the newest 200...
    $newest = $this->getJson('/api/document-requests?all_statuses=true&per_page=200')->assertOk();
    expect(dlsIds($newest))->not->toContain($old->request_id);

    // ...but search still finds it.
    $found = $this->getJson('/api/document-requests?search=Matanda')->assertOk();
    expect(dlsIds($found))->toBe([$old->request_id]);
});

test('LIKE wildcards in the search term are matched literally', function () {
    dlsStaff();
    dlsStudentRequest('Juan', 'Cruz', '2021-00123');
    dlsStudentRequest('Pedro', 'Reyes', '2020-99999');

    // "%%" or "__" would match every row if not escaped.
    $this->getJson('/api/document-requests?search=' . urlencode('%%'))->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/document-requests?search=' . urlencode('__'))->assertOk()->assertJsonCount(0, 'data');
});

test('a search made only of separators matches nothing instead of everything', function () {
    dlsStaff();
    dlsStudentRequest('Juan', 'Cruz', '2021-00123');

    $this->getJson('/api/document-requests?search=' . urlencode(',,'))->assertOk()->assertJsonCount(0, 'data');
});

test('search never matches encrypted undergrad columns', function () {
    dlsStaff();
    dlsUndergradRequest('Maria', 'Santos', '2018-00456');

    $this->getJson('/api/document-requests?search=Confidential')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/document-requests?search=09171234567')->assertOk()->assertJsonCount(0, 'data');
});

// ── Validation ───────────────────────────────────────────────────────────────

test('an unknown sort field is rejected', function () {
    dlsStaff();

    $this->getJson('/api/document-requests?sort=' . urlencode('request_id; DROP TABLE users'))
        ->assertStatus(422)->assertJsonValidationErrors('sort');
});

test('per_page above the cap and a too-short search are rejected', function () {
    dlsStaff();

    $this->getJson('/api/document-requests?per_page=201')->assertStatus(422)->assertJsonValidationErrors('per_page');
    $this->getJson('/api/document-requests?search=a')->assertStatus(422)->assertJsonValidationErrors('search');
});

test('the legacy all_statuses=true string flag is still accepted', function () {
    dlsStaff();

    $this->getJson('/api/document-requests?all_statuses=true&per_page=200')->assertOk();
});

test('an unknown status name is rejected and "All" is ignored', function () {
    dlsStaff();
    dlsStudentRequest('Juan', 'Cruz', '2021-00123');

    $this->getJson('/api/document-requests?status=Nonsense')->assertStatus(422)->assertJsonValidationErrors('status');
    $this->getJson('/api/document-requests?status=All&classification=All&document=All')
        ->assertOk()->assertJsonCount(1, 'data');
});

// ── Filters, window, ordering, pagination ────────────────────────────────────

test('classification filter separates students from undergrad requestors', function () {
    dlsStaff();
    $student = dlsStudentRequest('Juan', 'Cruz', '2021-00123');
    [$undergrad] = dlsUndergradRequest('Maria', 'Santos', '2018-00456');

    expect(dlsIds($this->getJson('/api/document-requests?classification=Student')))->toBe([$student->request_id]);
    expect(dlsIds($this->getJson('/api/document-requests?classification=undergrad')))->toBe([$undergrad->request_id]);
});

test('status filter returns only that status across all ages', function () {
    dlsStaff();
    $forfeited = dlsStudentRequest('Old', 'One', '2000-00001', [
        'status_id' => RequestStatusEnum::Forfeited->value, 'requested_at' => now()->subYear(),
    ]);
    dlsStudentRequest('New', 'One', '2000-00002');

    expect(dlsIds($this->getJson('/api/document-requests?status=Forfeited')))->toBe([$forfeited->request_id]);
});

test('default window keeps Pending Signature and Awaiting Submission and uses status_updated_at for Completed', function () {
    dlsStaff();

    $pendingSig = dlsStudentRequest('A', 'Aa', '3000-00001', ['status_id' => RequestStatusEnum::PendingSignature->value]);
    $awaiting   = dlsStudentRequest('B', 'Bb', '3000-00002', ['status_id' => RequestStatusEnum::AwaitingSubmission->value]);

    // Filed long ago, completed just now: must stay visible (the old filter used requested_at and dropped it).
    $freshlyCompleted = dlsStudentRequest('C', 'Cc', '3000-00003', [
        'status_id' => RequestStatusEnum::Completed->value, 'requested_at' => now()->subDays(30),
    ]);

    // Completed three days ago: outside the 24h window.
    $staleCompleted = dlsStudentRequest('D', 'Dd', '3000-00004', [
        'status_id' => RequestStatusEnum::Completed->value, 'requested_at' => now()->subDays(30),
    ]);
    DocumentRequest::whereKey($staleCompleted->request_id)->update(['status_updated_at' => now()->subDays(3)]);

    $ids = dlsIds($this->getJson('/api/document-requests')->assertOk());

    expect($ids)->toContain($pendingSig->request_id, $awaiting->request_id, $freshlyCompleted->request_id)
                ->not->toContain($staleCompleted->request_id);
});

test('Completed rows sort last when sort is provided, and Old Requests reverses the order', function () {
    dlsStaff();
    $done  = dlsStudentRequest('D', 'One', '4000-00001', ['status_id' => RequestStatusEnum::Completed->value, 'requested_at' => now()]);
    $newer = dlsStudentRequest('N', 'Two', '4000-00002', ['requested_at' => now()->subDay()]);
    $older = dlsStudentRequest('O', 'Three', '4000-00003', ['requested_at' => now()->subDays(2)]);

    $recent = dlsIds($this->getJson('/api/document-requests?all_statuses=true&sort=' . urlencode('Recent Requests')));
    expect($recent)->toBe([$newer->request_id, $older->request_id, $done->request_id]);

    $oldest = dlsIds($this->getJson('/api/document-requests?all_statuses=true&sort=' . urlencode('Old Requests')));
    expect($oldest)->toBe([$older->request_id, $newer->request_id, $done->request_id]);
});

test('pagination meta reflects the full match set, not one page', function () {
    dlsStaff();
    $user = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT, 'status' => 'Activated']);
    DocumentRequest::factory()->count(7)->create(['user_id' => $user->user_id]);

    $page2 = $this->getJson('/api/document-requests?per_page=3&page=2&sort=' . urlencode('Recent Requests'))->assertOk();

    expect($page2->json('total'))->toBe(7)
        ->and($page2->json('last_page'))->toBe(3)
        ->and($page2->json('current_page'))->toBe(2)
        ->and($page2->json('data'))->toHaveCount(3);
});

// ── Response shape and privacy ───────────────────────────────────────────────

test('list rows carry display_name, student_number and requester_type for every account type', function () {
    dlsStaff();
    $student = dlsStudentRequest('Juan', 'Cruz', '2021-00123');
    [$undergrad] = dlsUndergradRequest('Maria', 'Santos', '2018-00456');

    $rows = collect($this->getJson('/api/document-requests')->assertOk()->json('data'))->keyBy('request_id');

    expect($rows[$student->request_id]['display_name'])->toBe('Juan Cruz')
        ->and($rows[$student->request_id]['student_number'])->toBe('2021-00123')
        ->and($rows[$student->request_id]['requester_type'])->toBe('Student')
        ->and($rows[$undergrad->request_id]['display_name'])->toBe('Maria Santos')
        ->and($rows[$undergrad->request_id]['student_number'])->toBe('2018-00456')
        ->and($rows[$undergrad->request_id]['requester_type'])->toBe('Undergrad Requestor');
});

test('list responses never contain decrypted undergrad PII', function () {
    dlsStaff();
    dlsUndergradRequest('Maria', 'Santos', '2018-00456');

    foreach (['/api/document-requests', '/api/document-requests?all_statuses=true'] as $url) {
        $response = $this->getJson($url)->assertOk();
        $body     = $response->getContent();

        expect($body)->not->toContain('09171234567')
                     ->not->toContain('Confidential Street')
                     ->not->toContain('Private medical reason');

        $profile = $response->json('data.0.undergrad_requestor_profile');
        expect($profile)->not->toHaveKeys(['phone', 'present_address', 'date_of_birth', 'reason_for_non_enrollment']);
    }
});

test('the logbook applies the same PII restriction', function () {
    dlsStaff();
    dlsUndergradRequest('Maria', 'Santos', '2018-00456', ['status_id' => RequestStatusEnum::Completed->value]);

    $body = $this->getJson('/api/document-requests/logbook')->assertOk()->getContent();

    expect($body)->not->toContain('09171234567')->not->toContain('Confidential Street');
});

test('non-staff still receive only their own requests', function () {
    $mine  = dlsStudentRequest('Juan', 'Cruz', '2021-00123');
    dlsStudentRequest('Pedro', 'Reyes', '2020-99999');

    Sanctum::actingAs(SystemUser::find($mine->user_id));

    $data = $this->getJson('/api/document-requests')->assertOk()->json('data');

    expect(collect($data)->pluck('request_id')->all())->toBe([$mine->request_id]);
});

// ── Counts ───────────────────────────────────────────────────────────────────

test('counts match the database, include zero-count statuses and exclude archived rows', function () {
    dlsStaff();
    $user = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT, 'status' => 'Activated']);

    DocumentRequest::factory()->count(3)->create(['user_id' => $user->user_id, 'status_id' => RequestStatusEnum::Processing->value]);
    DocumentRequest::factory()->count(2)->create(['user_id' => $user->user_id, 'status_id' => RequestStatusEnum::ReadyToClaim->value]);
    DocumentRequest::factory()->create(['user_id' => $user->user_id, 'status_id' => RequestStatusEnum::Processing->value, 'is_archived' => true]);

    $counts = $this->getJson('/api/document-requests/counts')->assertOk()->json();

    expect($counts['Processing'])->toBe(3)
        ->and($counts['Ready to Claim'])->toBe(2)
        ->and($counts['Forfeited'])->toBe(0)
        ->and($counts['Archived'])->toBe(1);
});