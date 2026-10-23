<?php

use App\Models\AccessType;
use App\Models\Announcement;
use App\Models\CertificationType;
use App\Models\DocumentType;
use App\Models\SystemUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Reference data and announcements: what a non-staff or anonymous caller can
| read. Place at tests/Feature/ReferenceDataExposureTest.php.
|--------------------------------------------------------------------------
*/

const RDE_PATTERN = 'SECRET-CASHIER-PATTERN';

function rdeAccessId(): int
{
    return AccessType::firstOrCreate(['access_id' => 1], ['access_name' => 'Student'])->access_id;
}

function rdeDocType(bool $archived): DocumentType
{
    return DocumentType::create([
        'document_name'             => ($archived ? 'Archived' : 'Active') . ' Document',
        'document_description'      => 'desc',
        'document_requirements'     => 'Valid ID',
        'document_process_period'   => '3-5 business days',
        'access_id'                 => rdeAccessId(),
        'cashier_document_patterns' => [RDE_PATTERN],
        'is_archived'               => $archived,
    ]);
}

function rdeCertType(bool $archived): CertificationType
{
    return CertificationType::create([
        'certificate_name'           => ($archived ? 'Archived' : 'Active') . ' Certificate',
        'certificate_requirements'   => 'Valid ID',
        'certificate_process_period' => '3-5 business days',
        'access_id'                  => rdeAccessId(),
        'cashier_document_patterns'  => [RDE_PATTERN],
        'is_archived'                => $archived,
    ]);
}

function rdeUser(int $role): SystemUser
{
    return SystemUser::factory()->create(['role_id' => $role, 'status' => 'Activated']);
}

// ── document types ───────────────────────────────────────────────────────

it('does not send cashier patterns to a student on the document-type list or detail', function () {
    $active = rdeDocType(false);
    Sanctum::actingAs(rdeUser(SystemUser::ROLE_STUDENT));

    $list = $this->getJson('/api/v1/document-types')->assertOk();
    expect($list->getContent())->not->toContain(RDE_PATTERN)
        ->and($list->getContent())->not->toContain('cashier_document_patterns');

    $one = $this->getJson('/api/v1/document-types/' . $active->document_type_id)->assertOk();
    expect($one->getContent())->not->toContain(RDE_PATTERN);
});

it('still sends cashier patterns to staff', function () {
    $active = rdeDocType(false);
    Sanctum::actingAs(rdeUser(SystemUser::ROLE_ADMIN));

    $this->getJson('/api/v1/document-types')->assertOk()
        ->assertJsonPath('0.cashier_document_patterns.0', RDE_PATTERN);

    $this->getJson('/api/v1/document-types/' . $active->document_type_id)->assertOk()
        ->assertJsonPath('cashier_document_patterns.0', RDE_PATTERN);
});

it('answers 404 for an archived document type to a student, but not to staff', function () {
    $archived = rdeDocType(true);

    Sanctum::actingAs(rdeUser(SystemUser::ROLE_STUDENT));
    $this->getJson('/api/v1/document-types/' . $archived->document_type_id)->assertNotFound();

    Sanctum::actingAs(rdeUser(SystemUser::ROLE_ADMIN));
    $this->getJson('/api/v1/document-types/' . $archived->document_type_id)->assertOk();
});

// ── certification types ──────────────────────────────────────────────────

it('does not send cashier patterns to a student on the certification list or detail', function () {
    $active = rdeCertType(false);
    Sanctum::actingAs(rdeUser(SystemUser::ROLE_STUDENT));

    $list = $this->getJson('/api/v1/certifications')->assertOk();
    expect($list->getContent())->not->toContain(RDE_PATTERN);

    $one = $this->getJson('/api/v1/certifications/' . $active->certificate_type_id)->assertOk();
    expect($one->getContent())->not->toContain(RDE_PATTERN);
});

it('answers 404 for an archived certification type to a student, but not to staff', function () {
    $archived = rdeCertType(true);

    Sanctum::actingAs(rdeUser(SystemUser::ROLE_STUDENT));
    $this->getJson('/api/v1/certifications/' . $archived->certificate_type_id)->assertNotFound();

    Sanctum::actingAs(rdeUser(SystemUser::ROLE_ADMIN));
    $this->getJson('/api/v1/certifications/' . $archived->certificate_type_id)->assertOk();
});

it('restricts the certification layouts list to staff', function (int $role, bool $allowed) {
    rdeCertType(true);
    Sanctum::actingAs(rdeUser($role));

    $response = $this->getJson('/api/v1/certifications/layouts');

    $allowed ? $response->assertOk() : $response->assertForbidden();
})->with([
    'student'     => [SystemUser::ROLE_STUDENT, false],
    'alumni'      => [SystemUser::ROLE_ALUMNI, false],
    'admin'       => [SystemUser::ROLE_ADMIN, true],
    'super admin' => [SystemUser::ROLE_SUPER_ADMIN, true],
]);

// ── announcements (public routes) ────────────────────────────────────────

function rdeAnnouncement(SystemUser $author, array $overrides = []): Announcement
{
    return Announcement::create(array_merge([
        'title'      => 'Notice',
        'content'    => 'Body',
        'enabled'    => true,
        'created_by' => $author->user_id,
    ], $overrides));
}

it('shows an anonymous visitor only enabled, non-archived announcements', function () {
    $author   = rdeUser(SystemUser::ROLE_SUPER_ADMIN);
    $live     = rdeAnnouncement($author, ['title' => 'Live']);
    $disabled = rdeAnnouncement($author, ['title' => 'Draft', 'enabled' => false]);

    $titles = collect($this->getJson('/api/v1/announcements')->assertOk()->json('data'))->pluck('title');

    expect($titles->all())->toBe(['Live']);

    $this->getJson('/api/v1/announcements/' . $live->id)->assertOk();
    $this->getJson('/api/v1/announcements/' . $disabled->id)->assertNotFound();
});

it('refuses the archived announcement list to an anonymous visitor and a student', function () {
    $author = rdeUser(SystemUser::ROLE_SUPER_ADMIN);
    rdeAnnouncement($author, ['is_archived' => true, 'archived_on' => now()]);

    $this->getJson('/api/v1/announcements?view=archived')->assertForbidden();

    Sanctum::actingAs(rdeUser(SystemUser::ROLE_STUDENT));
    $this->getJson('/api/v1/announcements?view=archived')->assertForbidden();
});

it('lets staff see disabled and archived announcements', function () {
    $author = rdeUser(SystemUser::ROLE_SUPER_ADMIN);
    $draft  = rdeAnnouncement($author, ['title' => 'Draft', 'enabled' => false]);
    rdeAnnouncement($author, ['title' => 'Old', 'is_archived' => true, 'archived_on' => now()]);

    Sanctum::actingAs($author);

    $titles = collect($this->getJson('/api/v1/announcements')->assertOk()->json('data'))->pluck('title');
    expect($titles->all())->toContain('Draft');

    $this->getJson('/api/v1/announcements/' . $draft->id)->assertOk();

    $archived = collect($this->getJson('/api/v1/announcements?view=archived')->assertOk()->json('data'))->pluck('title');
    expect($archived->all())->toBe(['Old']);
});
