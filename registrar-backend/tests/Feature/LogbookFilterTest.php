<?php

use App\Enums\RequestStatusEnum;
use App\Models\AccessType;
use App\Models\CertificationType;
use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Models\LogbookCategory;
use App\Models\Policy;
use App\Models\RequestCertificate;
use App\Models\RequestDocument;
use App\Models\StudentProfile;
use App\Models\SystemUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// ═════════════════════════════════════════════════════════════════════════════
// GET /document-requests/logbook — server-side search, category filtering,
// paging totals and the resolved e-mail. Helper names carry an "lgb" prefix:
// Pest test files share one global function namespace.
// ═════════════════════════════════════════════════════════════════════════════

function lgbStaff(): SystemUser
{
    $admin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN, 'status' => 'Activated']);

    $policy = Policy::firstOrCreate(
        ['name' => 'Test Logbook Filter View'],
        ['permissions' => ['dashboard' => ['View'], 'logbook' => ['View']], 'is_system' => false]
    );
    $admin->update(['policy_id' => $policy->policy_id]);

    Sanctum::actingAs($admin);

    return $admin;
}

function lgbAccessId(): int
{
    return AccessType::firstOrCreate(['access_id' => 1], ['access_name' => 'Student'])->access_id;
}

function lgbDocType(string $name, ?LogbookCategory $category = null): DocumentType
{
    return DocumentType::create([
        'document_name'           => $name,
        'document_description'    => 'Test',
        'document_requirements'   => 'Valid ID',
        'document_process_period' => '2 hours',
        'access_id'               => lgbAccessId(),
        'logbook_category_id'     => $category?->logbook_category_id,
    ]);
}

function lgbCertType(string $name, ?LogbookCategory $category = null): CertificationType
{
    return CertificationType::create([
        'certificate_name'           => $name,
        'certificate_requirements'   => 'Test',
        'certificate_process_period' => '1 working day',
        'access_id'                  => lgbAccessId(),
        'logbook_category_id'        => $category?->logbook_category_id,
    ]);
}

/** A Completed request with one document (and optionally one certificate). */
function lgbRequest(?DocumentType $doc = null, ?CertificationType $cert = null, array $attributes = []): DocumentRequest
{
    $request = DocumentRequest::factory()->create(array_merge([
        'status_id'    => RequestStatusEnum::Completed->value,
        'requested_at' => '2026-03-10 02:00:00',
    ], $attributes));

    if ($doc) {
        RequestDocument::create([
            'request_id'       => $request->request_id,
            'document_type_id' => $doc->document_type_id,
            'number_of_copies' => 1,
            'status_id'        => RequestStatusEnum::Completed->value,
        ]);
    }

    if ($cert) {
        RequestCertificate::create([
            'request_id'          => $request->request_id,
            'certificate_type_id' => $cert->certificate_type_id,
            'number_of_copies'    => 1,
            'status_id'           => RequestStatusEnum::Completed->value,
        ]);
    }

    return $request;
}

function lgbIds(\Illuminate\Testing\TestResponse $response): array
{
    return collect($response->json('data'))->pluck('request_id')->sort()->values()->all();
}

// ── Category filter ──────────────────────────────────────────────────────────

test('a category selects its types by exact label and never by a substring', function () {
    lgbStaff();

    $torCategory = LogbookCategory::create(['name' => 'Transcript of Records']);
    $tor         = lgbRequest(lgbDocType('Transcript of Records (Regular)', $torCategory));
    $doctor      = lgbRequest(lgbDocType('Doctor Visit Form'));   // contains "tor", must not match
    $factor      = lgbRequest(lgbDocType('Factor Analysis Sheet')); // contains "tor", must not match

    $ids = lgbIds($this->getJson('/api/document-requests/logbook?' . http_build_query([
        'categories' => ['Transcript of Records'],
    ]))->assertOk());

    expect($ids)->toBe([$tor->request_id])
        ->and($ids)->not->toContain($doctor->request_id, $factor->request_id);
});

test('a type without a category is matched by its own name, case-insensitively', function () {
    lgbStaff();

    $fee   = lgbRequest(lgbDocType('Completion Fee'));
    $other = lgbRequest(lgbDocType('Diploma'));

    $ids = lgbIds($this->getJson('/api/document-requests/logbook?' . http_build_query([
        'categories' => ['completion fee'],
    ]))->assertOk());

    expect($ids)->toBe([$fee->request_id])->and($ids)->not->toContain($other->request_id);
});

test('several selected categories are combined, and All Document means no restriction', function () {
    lgbStaff();

    $a = lgbRequest(lgbDocType('Diploma'));
    $b = lgbRequest(lgbDocType('Good Moral'));
    $c = lgbRequest(lgbDocType('Honorable Dismissal'));

    $two = lgbIds($this->getJson('/api/document-requests/logbook?' . http_build_query([
        'categories' => ['Diploma', 'Good Moral'],
    ]))->assertOk());

    $all = lgbIds($this->getJson('/api/document-requests/logbook?' . http_build_query([
        'categories' => ['All Document'],
    ]))->assertOk());

    expect($two)->toBe(collect([$a->request_id, $b->request_id])->sort()->values()->all())
        ->and($all)->toBe(collect([$a->request_id, $b->request_id, $c->request_id])->sort()->values()->all());
});

test('certification mode filters by the certification label, or any certificate', function () {
    lgbStaff();

    $grad  = lgbRequest(null, lgbCertType('Certificate of Graduation'));
    $enrol = lgbRequest(null, lgbCertType('Certificate of Enrollment'));
    $docOnly = lgbRequest(lgbDocType('Diploma'));

    $one = lgbIds($this->getJson('/api/document-requests/logbook?' . http_build_query([
        'mode' => 'certifications', 'certification' => 'Certificate of Graduation',
    ]))->assertOk());

    $any = lgbIds($this->getJson('/api/document-requests/logbook?' . http_build_query([
        'mode' => 'certifications', 'certification' => 'All Certification',
    ]))->assertOk());

    expect($one)->toBe([$grad->request_id])
        ->and($any)->toBe(collect([$grad->request_id, $enrol->request_id])->sort()->values()->all())
        ->and($any)->not->toContain($docOnly->request_id);
});

// ── Search ───────────────────────────────────────────────────────────────────

test('search finds a request by OR number prefix and by account e-mail prefix', function () {
    lgbStaff();

    $type  = lgbDocType('Diploma');
    $byOr  = lgbRequest($type, null, ['or_number' => '9876543']);
    $user  = SystemUser::factory()->create(['email' => 'zelda.finder@example.com']);
    $byMail = lgbRequest($type, null, ['user_id' => $user->user_id, 'or_number' => '1111111']);
    $other = lgbRequest($type, null, ['or_number' => '2222222']);

    $orIds = lgbIds($this->getJson('/api/document-requests/logbook?search=98765')->assertOk());
    $mailIds = lgbIds($this->getJson('/api/document-requests/logbook?search=zelda.finder')->assertOk());

    expect($orIds)->toBe([$byOr->request_id])
        ->and($mailIds)->toBe([$byMail->request_id])
        ->and($orIds)->not->toContain($other->request_id);
});

test('search treats LIKE wildcards as plain text', function () {
    lgbStaff();

    lgbRequest(lgbDocType('Diploma'), null, ['or_number' => '1234567']);

    $this->getJson('/api/document-requests/logbook?search=' . urlencode('%'))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

// ── Totals, paging, scope ────────────────────────────────────────────────────

test('the response total is the filtered record count across all pages', function () {
    lgbStaff();

    $type = lgbDocType('Diploma');
    foreach (range(1, 5) as $_) {
        lgbRequest($type);
    }
    lgbRequest(lgbDocType('Good Moral'));

    $response = $this->getJson('/api/document-requests/logbook?' . http_build_query([
        'categories' => ['Diploma'], 'per_page' => 2, 'page' => 2,
    ]))->assertOk();

    expect($response->json('total'))->toBe(5)
        ->and($response->json('last_page'))->toBe(3)
        ->and($response->json('data'))->toHaveCount(2);
});

test('only Completed requests are listed', function () {
    lgbStaff();

    $type = lgbDocType('Diploma');
    $done = lgbRequest($type);
    lgbRequest($type, null, ['status_id' => RequestStatusEnum::Processing->value]);

    expect(lgbIds($this->getJson('/api/document-requests/logbook')->assertOk()))->toBe([$done->request_id]);
});

test('the date range filters on the requested date', function () {
    lgbStaff();

    $type = lgbDocType('Diploma');
    $in   = lgbRequest($type, null, ['requested_at' => '2026-03-10 02:00:00']);
    lgbRequest($type, null, ['requested_at' => '2026-05-10 02:00:00']);

    expect(lgbIds($this->getJson('/api/document-requests/logbook?from=2026-03-01&to=2026-03-31')->assertOk()))
        ->toBe([$in->request_id]);
});

// ── Validation ───────────────────────────────────────────────────────────────

test('invalid filter input is rejected', function () {
    lgbStaff();

    $this->getJson('/api/document-requests/logbook?from=not-a-date')->assertStatus(422);
    $this->getJson('/api/document-requests/logbook?from=2026-04-01&to=2026-03-01')->assertStatus(422);
    $this->getJson('/api/document-requests/logbook?mode=everything')->assertStatus(422);
    $this->getJson('/api/document-requests/logbook?categories=Diploma')->assertStatus(422);
    $this->getJson('/api/document-requests/logbook?search=' . str_repeat('a', 101))->assertStatus(422);
});

// ── E-mail ───────────────────────────────────────────────────────────────────

test('the e-mail falls back to the filing account when the requester has no profile account', function () {
    lgbStaff();

    $user    = SystemUser::factory()->create(['email' => 'filer@example.com']);
    $request = lgbRequest(lgbDocType('Diploma'), null, ['user_id' => $user->user_id]);

    $row = collect($this->getJson('/api/document-requests/logbook')->assertOk()->json('data'))
        ->firstWhere('request_id', $request->request_id);

    expect($row['email'])->toBe('filer@example.com');
});

test('the e-mail prefers the profile owner account over the filing account', function () {
    lgbStaff();

    $owner   = SystemUser::factory()->create(['email' => 'owner@example.com']);
    $filer   = SystemUser::factory()->create(['email' => 'filer@example.com']);
    $profile = StudentProfile::factory()->create(['user_id' => $owner->user_id]);

    $request = lgbRequest(lgbDocType('Diploma'), null, [
        'user_id'            => $filer->user_id,
        'student_profile_id' => $profile->student_profile_id,
    ]);

    $row = collect($this->getJson('/api/document-requests/logbook')->assertOk()->json('data'))
        ->firstWhere('request_id', $request->request_id);

    expect($row['email'])->toBe('owner@example.com');
});

test('a student account cannot read the logbook', function () {
    $student = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT, 'status' => 'Activated']);
    Sanctum::actingAs($student);

    $this->getJson('/api/document-requests/logbook')->assertForbidden();
});
