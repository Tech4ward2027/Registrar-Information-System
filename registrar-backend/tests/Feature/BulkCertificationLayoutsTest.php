<?php

use App\Models\AccessType;
use App\Models\AuditLog;
use App\Models\CertificationType;
use App\Models\SystemUser;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function bulkLayoutActAs(int $roleId): SystemUser
{
    $user = SystemUser::factory()->create(['role_id' => $roleId, 'status' => 'Activated']);
    Sanctum::actingAs($user);

    return $user;
}

function bulkLayoutCert(array $overrides = []): CertificationType
{
    AccessType::firstOrCreate(['access_id' => 1], ['access_name' => 'Student']);

    return CertificationType::create(array_merge([
        'certificate_name'           => 'Certificate ' . uniqid(),
        'certificate_requirements'   => 'Valid ID',
        'certificate_process_period' => '3-5 business days',
        'access_id'                  => 1,
        'layout_header_logo_size'    => 48,
    ], $overrides));
}

const BULK_LAYOUT_URL = '/api/v1/certifications/layouts/bulk';

// ── Success ──────────────────────────────────────────────────────────────────

test('admin can update several layouts in one call', function () {
    $a = bulkLayoutCert();
    $b = bulkLayoutCert();
    bulkLayoutActAs(SystemUser::ROLE_ADMIN);

    $this->putJson(BULK_LAYOUT_URL, ['layouts' => [
        ['certificate_type_id' => $a->certificate_type_id, 'layout_header_logo_size' => 64, 'layout_footer_urls' => null],
        ['certificate_type_id' => $b->certificate_type_id, 'layout_header_font_size' => 14, 'layout_header_lines' => ['PUP', 'Registrar']],
    ]])->assertOk()
        ->assertJsonPath('message', 'Certification layouts updated successfully')
        ->assertJsonPath('updated_count', 2)
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.certificate_type_id', $a->certificate_type_id)
        ->assertJsonPath('data.1.certificate_type_id', $b->certificate_type_id);

    $this->assertDatabaseHas('certificate_type', ['certificate_type_id' => $a->certificate_type_id, 'layout_header_logo_size' => 64]);
    $this->assertDatabaseHas('certificate_type', ['certificate_type_id' => $b->certificate_type_id, 'layout_header_font_size' => 14]);
});

test('a field left out of an entry is not touched', function () {
    $a = bulkLayoutCert(['layout_header_logo_size' => 48]);
    bulkLayoutActAs(SystemUser::ROLE_ADMIN);

    $this->putJson(BULK_LAYOUT_URL, ['layouts' => [
        ['certificate_type_id' => $a->certificate_type_id, 'layout_header_font_size' => 12],
    ]])->assertOk();

    $this->assertDatabaseHas('certificate_type', ['certificate_type_id' => $a->certificate_type_id, 'layout_header_logo_size' => 48]);
});

test('super admin can use the bulk endpoint', function () {
    $a = bulkLayoutCert();
    bulkLayoutActAs(SystemUser::ROLE_SUPER_ADMIN);

    $this->putJson(BULK_LAYOUT_URL, ['layouts' => [
        ['certificate_type_id' => $a->certificate_type_id, 'layout_header_logo_size' => 80],
    ]])->assertOk();
});

// ── Audit log ────────────────────────────────────────────────────────────────

test('a successful bulk update writes exactly one audit entry with ids and field names', function () {
    $a = bulkLayoutCert();
    $b = bulkLayoutCert();
    $actor = bulkLayoutActAs(SystemUser::ROLE_ADMIN);

    $this->putJson(BULK_LAYOUT_URL, ['layouts' => [
        ['certificate_type_id' => $a->certificate_type_id, 'layout_header_logo_size' => 64],
        ['certificate_type_id' => $b->certificate_type_id, 'layout_header_font_size' => 14],
    ]])->assertOk();

    $entries = AuditLog::where('action', AuditLog::ACTION_CERTIFICATE_LAYOUTS_BULK_UPDATED)->get();
    expect($entries)->toHaveCount(1);

    $entry = $entries->first();
    expect($entry->user_id)->toBe($actor->user_id);
    expect($entry->metadata['count'])->toBe(2);
    expect($entry->metadata['certificate_type_ids'])->toBe([$a->certificate_type_id, $b->certificate_type_id]);
    expect($entry->metadata['changed_fields'][$a->certificate_type_id])->toBe(['layout_header_logo_size']);
});

// ── Authorization ────────────────────────────────────────────────────────────

test('guests are rejected', function () {
    $this->putJson(BULK_LAYOUT_URL, ['layouts' => []])->assertUnauthorized();
});

test('non-admin roles are forbidden and nothing changes', function (int $role) {
    $a = bulkLayoutCert();
    bulkLayoutActAs($role);

    $this->putJson(BULK_LAYOUT_URL, ['layouts' => [
        ['certificate_type_id' => $a->certificate_type_id, 'layout_header_logo_size' => 99],
    ]])->assertForbidden();

    $this->assertDatabaseHas('certificate_type', ['certificate_type_id' => $a->certificate_type_id, 'layout_header_logo_size' => 48]);
    expect(AuditLog::where('action', AuditLog::ACTION_CERTIFICATE_LAYOUTS_BULK_UPDATED)->count())->toBe(0);
})->with([
    'student'             => [SystemUser::ROLE_STUDENT],
    'alumni'              => [SystemUser::ROLE_ALUMNI],
    'undergrad requestor' => [SystemUser::ROLE_UNDERGRAD_REQUESTOR],
]);

// ── Validation ───────────────────────────────────────────────────────────────

test('invalid payloads are rejected with 422', function (array $payload, string $errorKey) {
    bulkLayoutActAs(SystemUser::ROLE_ADMIN);

    $this->putJson(BULK_LAYOUT_URL, $payload)->assertStatus(422)->assertJsonValidationErrors($errorKey);
})->with([
    'missing layouts'     => [[], 'layouts'],
    'empty layouts'       => [['layouts' => []], 'layouts'],
    'unknown cert id'     => [['layouts' => [['certificate_type_id' => 999999, 'layout_header_logo_size' => 64]]], 'layouts.0.certificate_type_id'],
    'missing cert id'     => [['layouts' => [['layout_header_logo_size' => 64]]], 'layouts.0.certificate_type_id'],
    'logo size too small' => [['layouts' => [['certificate_type_id' => 1, 'layout_header_logo_size' => 10]]], 'layouts.0.layout_header_logo_size'],
    'font size too big'   => [['layouts' => [['certificate_type_id' => 1, 'layout_header_font_size' => 99]]], 'layouts.0.layout_header_font_size'],
    'no layout fields'    => [['layouts' => [['certificate_type_id' => 1]]], 'layouts.0'],
]);

test('duplicate certificate ids in one batch are rejected', function () {
    $a = bulkLayoutCert();
    bulkLayoutActAs(SystemUser::ROLE_ADMIN);

    $this->putJson(BULK_LAYOUT_URL, ['layouts' => [
        ['certificate_type_id' => $a->certificate_type_id, 'layout_header_logo_size' => 64],
        ['certificate_type_id' => $a->certificate_type_id, 'layout_header_logo_size' => 70],
    ]])->assertStatus(422)->assertJsonValidationErrors('layouts.0.certificate_type_id');
});

test('more than the maximum number of entries is rejected', function () {
    $a = bulkLayoutCert();
    bulkLayoutActAs(SystemUser::ROLE_ADMIN);

    $items = array_fill(0, 51, ['certificate_type_id' => $a->certificate_type_id, 'layout_header_logo_size' => 64]);

    $this->putJson(BULK_LAYOUT_URL, ['layouts' => $items])->assertStatus(422)->assertJsonValidationErrors('layouts');
});

// ── Archived + rollback ──────────────────────────────────────────────────────

test('an archived certificate rejects the whole batch with 423 and saves nothing', function () {
    $ok       = bulkLayoutCert();
    $archived = bulkLayoutCert(['is_archived' => true, 'archived_on' => now()]);
    bulkLayoutActAs(SystemUser::ROLE_ADMIN);

    $this->putJson(BULK_LAYOUT_URL, ['layouts' => [
        ['certificate_type_id' => $ok->certificate_type_id, 'layout_header_logo_size' => 64],
        ['certificate_type_id' => $archived->certificate_type_id, 'layout_header_logo_size' => 64],
    ]])->assertStatus(423)
        ->assertJsonPath('archived_certificate_type_ids', [$archived->certificate_type_id]);

    $this->assertDatabaseHas('certificate_type', ['certificate_type_id' => $ok->certificate_type_id, 'layout_header_logo_size' => 48]);
    expect(AuditLog::where('action', AuditLog::ACTION_CERTIFICATE_LAYOUTS_BULK_UPDATED)->count())->toBe(0);
});

test('a failure part-way through rolls back every layout change', function () {
    $a = bulkLayoutCert();
    $b = bulkLayoutCert();
    bulkLayoutActAs(SystemUser::ROLE_ADMIN);

    // The audit write happens after the updates but inside the transaction:
    // if it throws, both layout updates must be undone.
    $failing = Mockery::mock(AuditLogger::class);
    $failing->shouldReceive('log')->once()->andThrow(new RuntimeException('audit unavailable'));
    app()->instance(AuditLogger::class, $failing);

    $this->putJson(BULK_LAYOUT_URL, ['layouts' => [
        ['certificate_type_id' => $a->certificate_type_id, 'layout_header_logo_size' => 64],
        ['certificate_type_id' => $b->certificate_type_id, 'layout_header_logo_size' => 70],
    ]])->assertStatus(500);

    $this->assertDatabaseHas('certificate_type', ['certificate_type_id' => $a->certificate_type_id, 'layout_header_logo_size' => 48]);
    $this->assertDatabaseHas('certificate_type', ['certificate_type_id' => $b->certificate_type_id, 'layout_header_logo_size' => 48]);
});