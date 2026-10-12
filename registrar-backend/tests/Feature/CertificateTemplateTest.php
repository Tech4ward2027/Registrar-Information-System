<?php

use App\Exceptions\TemplateStorageException;
use App\Models\AccessType;
use App\Models\AuditLog;
use App\Models\CertificateTemplate;
use App\Models\CertificationType;
use App\Models\SystemUser;
use App\Services\AuditLogger;
use App\Services\Certificates\CertificateTemplateStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

const CTP_DISK = 'cert_tpl_test';

beforeEach(function () {
    config([
        'certificate_templates.disk' => CTP_DISK,
        'filesystems.disks.' . CTP_DISK => [
            'driver' => 'local',
            'root'   => storage_path('framework/testing/disks/' . CTP_DISK),
        ],
    ]);
    Storage::fake(CTP_DISK);
    Cache::flush();
});

function ctpActAs(int $roleId = SystemUser::ROLE_ADMIN): SystemUser
{
    $user = SystemUser::factory()->create(['role_id' => $roleId, 'status' => 'Activated']);
    Sanctum::actingAs($user);

    return $user;
}

function ctpType(array $overrides = []): CertificationType
{
    AccessType::firstOrCreate(['access_id' => 1], ['access_name' => 'Student']);

    return CertificationType::create(array_merge([
        'certificate_name'           => 'Certificate of Graduation',
        'certificate_requirements'   => 'Valid ID',
        'certificate_process_period' => '3-5 business days',
        'access_id'                  => 1,
    ], $overrides));
}

function ctpPayload(array $overrides = []): array
{
    return array_merge([
        'content'         => '<p>This certifies that {{ full_name|upper }} graduated from {{ course }}.</p>',
        'required_fields' => ['full_name', 'course'],
    ], $overrides);
}

function ctpUrl(CertificationType $type, string $suffix = ''): string
{
    return "/api/certifications/{$type->certificate_type_id}/template{$suffix}";
}

// ── Access ───────────────────────────────────────────────────────────────────

test('guests, students and alumni cannot touch template endpoints', function () {
    $type = ctpType();

    $this->getJson(ctpUrl($type))->assertUnauthorized();

    foreach ([SystemUser::ROLE_STUDENT, SystemUser::ROLE_ALUMNI] as $role) {
        ctpActAs($role);
        $this->getJson(ctpUrl($type))->assertForbidden();
        $this->putJson(ctpUrl($type), ctpPayload())->assertForbidden();
        $this->getJson(ctpUrl($type, '/versions'))->assertForbidden();
        $this->postJson(ctpUrl($type, '/versions/1/activate'))->assertForbidden();
        $this->getJson('/api/certificate-merge-tags')->assertForbidden();
        $this->getJson('/api/certifications/templates')->assertForbidden();
    }

    expect(CertificateTemplate::count())->toBe(0);
});

// ── Legacy fallback ──────────────────────────────────────────────────────────

test('a type with no template reports the legacy source so the client keeps CERT_CONFIG', function () {
    $type = ctpType();
    ctpActAs();

    $this->getJson(ctpUrl($type))
        ->assertOk()
        ->assertJsonPath('data.source', 'legacy')
        ->assertJsonPath('data.template', null);
});

test('an unknown certification type is a 404 on every template route', function () {
    ctpActAs();

    $this->getJson('/api/certifications/999999/template')->assertNotFound();
    $this->getJson('/api/certifications/999999/template/versions')->assertNotFound();
    $this->putJson('/api/certifications/999999/template', ctpPayload())->assertNotFound();
});

// ── Create / read ────────────────────────────────────────────────────────────

test('saving the first template creates version 1 in the object store and MySQL', function () {
    $type = ctpType();
    $admin = ctpActAs();

    $this->putJson(ctpUrl($type), ctpPayload([
        'paper_size' => 'LETTER', 'orientation' => 'landscape',
        'margin_top' => 20, 'margin_right' => 15, 'margin_bottom' => 20, 'margin_left' => 15,
        'change_note' => 'Initial wording',
    ]))
        ->assertCreated()
        ->assertJsonPath('changed', true)
        ->assertJsonPath('data.version', 1)
        ->assertJsonPath('data.is_active', true)
        ->assertJsonPath('data.paper_size', 'LETTER')
        ->assertJsonPath('data.orientation', 'landscape')
        ->assertJsonPath('data.margins.top', 20)
        ->assertJsonPath('data.required_fields', ['full_name', 'course'])
        ->assertJsonMissingPath('data.storage_path');

    $row = CertificateTemplate::sole();
    expect($row->template_slug)->toBe('certificate-of-graduation')
        ->and($row->storage_path)->toBe('certificate-templates/certificate-of-graduation/template-v1.html')
        ->and($row->created_by)->toBe($admin->user_id);

    Storage::disk(CTP_DISK)->assertExists($row->storage_path);
    expect(hash('sha256', Storage::disk(CTP_DISK)->get($row->storage_path)))->toBe($row->content_sha256);
});

test('GET returns the active template body, version and settings', function () {
    $type = ctpType();
    ctpActAs();
    $this->putJson(ctpUrl($type), ctpPayload())->assertCreated();

    $this->getJson(ctpUrl($type))
        ->assertOk()
        ->assertJsonPath('data.source', 'database')
        ->assertJsonPath('data.template.version', 1)
        ->assertJsonPath('data.template.paper_size', 'A4')
        ->assertJsonPath('data.template.orientation', 'portrait')
        ->assertJsonPath('data.template.margins.left', 25.4)
        ->assertJsonPath('data.template.content', '<p>This certifies that {{ full_name|upper }} graduated from {{ course }}.</p>');
});

test('the summary endpoint lists the active version of every type that has one', function () {
    $a = ctpType();
    $b = ctpType(['certificate_name' => 'Certificate of Enrollment']);
    ctpType(['certificate_name' => 'Certificate of Good Moral']);
    ctpActAs();

    $this->putJson(ctpUrl($a), ctpPayload())->assertCreated();
    $this->putJson(ctpUrl($b), ctpPayload(['content' => '<p>{{ full_name }}</p>', 'required_fields' => ['full_name']]))->assertCreated();

    $res = $this->getJson('/api/certifications/templates')->assertOk();
    expect($res->json('data'))->toHaveCount(2)
        ->and(collect($res->json('data'))->pluck('certificate_type_id')->all())
        ->toBe([$a->certificate_type_id, $b->certificate_type_id])
        ->and($res->json('data.0'))->not->toHaveKey('content');
});

test('two types with the same name get distinct storage folders', function () {
    $a = ctpType();
    $b = ctpType(); // same name
    ctpActAs();

    $this->putJson(ctpUrl($a), ctpPayload())->assertCreated();
    $this->putJson(ctpUrl($b), ctpPayload())->assertCreated();

    $slugs = CertificateTemplate::orderBy('certificate_type_id')->pluck('template_slug')->all();
    expect($slugs[0])->toBe('certificate-of-graduation')
        ->and($slugs[1])->toBe('certificate-of-graduation-' . $b->certificate_type_id);
});

// ── Versioning ───────────────────────────────────────────────────────────────

test('saving identical content and settings is a no-op: no new version, object or audit row', function () {
    $type = ctpType();
    ctpActAs();
    $this->putJson(ctpUrl($type), ctpPayload())->assertCreated();

    $this->putJson(ctpUrl($type), ctpPayload())
        ->assertOk()
        ->assertJsonPath('changed', false)
        ->assertJsonPath('data.version', 1);

    expect(CertificateTemplate::count())->toBe(1)
        ->and(Storage::disk(CTP_DISK)->allFiles())->toHaveCount(1)
        ->and(AuditLog::where('action', AuditLog::ACTION_CERTIFICATE_TEMPLATE_VERSION_CREATED)->count())->toBe(1);
});

test('changing the content creates the next version and keeps the previous one', function () {
    $type = ctpType();
    ctpActAs();
    $this->putJson(ctpUrl($type), ctpPayload())->assertCreated();

    $this->putJson(ctpUrl($type), ctpPayload(['content' => '<p>New wording for {{ full_name }} in {{ course }}.</p>']))
        ->assertCreated()
        ->assertJsonPath('data.version', 2);

    expect(CertificateTemplate::where('is_active', true)->count())->toBe(1)
        ->and(CertificateTemplate::where('version', 1)->sole()->is_active)->toBeFalse()
        ->and(CertificateTemplate::where('version', 2)->sole()->is_active)->toBeTrue();

    Storage::disk(CTP_DISK)->assertExists('certificate-templates/certificate-of-graduation/template-v1.html');
    Storage::disk(CTP_DISK)->assertExists('certificate-templates/certificate-of-graduation/template-v2.html');

    $this->getJson(ctpUrl($type))->assertJsonPath('data.template.version', 2);
});

test('changing only a render setting also creates a new version, inheriting the rest', function () {
    $type = ctpType();
    ctpActAs();
    $this->putJson(ctpUrl($type), ctpPayload(['paper_size' => 'LEGAL']))->assertCreated();

    $this->putJson(ctpUrl($type), ctpPayload(['orientation' => 'landscape']))
        ->assertCreated()
        ->assertJsonPath('data.version', 2)
        ->assertJsonPath('data.paper_size', 'LEGAL')       // inherited from v1
        ->assertJsonPath('data.orientation', 'landscape');
});

test('the versions endpoint lists the history newest first without bodies', function () {
    $type = ctpType();
    ctpActAs();
    $this->putJson(ctpUrl($type), ctpPayload())->assertCreated();
    $this->putJson(ctpUrl($type), ctpPayload(['content' => '<p>{{ full_name }}</p>', 'required_fields' => ['full_name']]))->assertCreated();

    $res = $this->getJson(ctpUrl($type, '/versions'))->assertOk();

    expect(collect($res->json('data'))->pluck('version')->all())->toBe([2, 1])
        ->and(collect($res->json('data'))->pluck('is_active')->all())->toBe([true, false])
        ->and($res->json('data.0'))->not->toHaveKey('content')
        ->and($res->json('data.0'))->not->toHaveKey('storage_path');
});

test('an older version can be re-activated and the switch is audited', function () {
    $type = ctpType();
    ctpActAs();
    $this->putJson(ctpUrl($type), ctpPayload())->assertCreated();
    $this->putJson(ctpUrl($type), ctpPayload(['content' => '<p>{{ full_name }} {{ course }} v2</p>']))->assertCreated();

    $this->postJson(ctpUrl($type, '/versions/1/activate'))
        ->assertOk()
        ->assertJsonPath('data.version', 1)
        ->assertJsonPath('data.is_active', true);

    $this->getJson(ctpUrl($type))->assertJsonPath('data.template.version', 1);
    expect(CertificateTemplate::where('is_active', true)->count())->toBe(1);

    $log = AuditLog::where('action', AuditLog::ACTION_CERTIFICATE_TEMPLATE_ACTIVATED)->sole();
    expect($log->metadata['template_version'])->toBe(1)
        ->and($log->metadata['previous_version'])->toBe(2);

    // Activating the already-active version writes nothing more.
    $this->postJson(ctpUrl($type, '/versions/1/activate'))->assertOk();
    expect(AuditLog::where('action', AuditLog::ACTION_CERTIFICATE_TEMPLATE_ACTIVATED)->count())->toBe(1);
});

test('activating a version that does not exist is a 404', function () {
    $type = ctpType();
    ctpActAs();

    $this->postJson(ctpUrl($type, '/versions/7/activate'))->assertNotFound();
});

test('the audit log records the template version, never the template body', function () {
    $type = ctpType();
    $admin = ctpActAs();
    $this->putJson(ctpUrl($type), ctpPayload())->assertCreated();
    $this->putJson(ctpUrl($type), ctpPayload(['content' => '<p>{{ full_name }} {{ course }} again</p>']))->assertCreated();

    $logs = AuditLog::where('action', AuditLog::ACTION_CERTIFICATE_TEMPLATE_VERSION_CREATED)->orderBy('id')->get();

    expect($logs)->toHaveCount(2)
        ->and($logs[0]->metadata['template_version'])->toBe(1)
        ->and($logs[0]->metadata['previous_version'])->toBeNull()
        ->and($logs[1]->metadata['template_version'])->toBe(2)
        ->and($logs[1]->metadata['previous_version'])->toBe(1)
        ->and($logs[1]->metadata['certificate_type_id'])->toBe($type->certificate_type_id)
        ->and($logs[1]->user_id)->toBe($admin->user_id)
        ->and(json_encode($logs[1]->metadata))->not->toContain('again');
});

// ── Validation & safety ──────────────────────────────────────────────────────

test('an unknown merge tag is rejected and nothing is stored', function () {
    $type = ctpType();
    ctpActAs();

    $this->putJson(ctpUrl($type), ctpPayload(['content' => '<p>{{ full_name }} {{ password }}</p>', 'required_fields' => []]))
        ->assertUnprocessable()
        ->assertJsonPath('errors.content.0', 'Unknown merge tag: {{ password }}.');

    expect(CertificateTemplate::count())->toBe(0)
        ->and(Storage::disk(CTP_DISK)->allFiles())->toBe([]);
});

test('a malformed merge tag and an unknown format are rejected', function () {
    $type = ctpType();
    ctpActAs();

    $this->putJson(ctpUrl($type), ctpPayload(['content' => '<p>{{ full_name </p>', 'required_fields' => []]))
        ->assertUnprocessable()
        ->assertJsonPath('errors.content.0', fn ($m) => str_contains($m, 'Malformed merge tag'));
    $this->putJson(ctpUrl($type), ctpPayload(['content' => '<p>{{ full_name|exec }}</p>', 'required_fields' => []]))
        ->assertUnprocessable();

    expect(CertificateTemplate::count())->toBe(0);
});

test('markup that could execute or fetch anything is stripped before storage', function () {
    $type = ctpType();
    ctpActAs();

    $this->putJson(ctpUrl($type), [
        'content' => '<p onclick="steal()" style="color:red;background:url(http://evil/x.png)">{{ full_name }}</p>'
            . '<script>alert(1)</script><img src="http://evil/p.png"><iframe src="http://evil"></iframe>',
    ])->assertCreated();

    $stored = Storage::disk(CTP_DISK)->get(CertificateTemplate::sole()->storage_path);

    expect($stored)->toContain('{{ full_name }}')
        ->and($stored)->not->toContain('script')
        ->and($stored)->not->toContain('onclick')
        ->and($stored)->not->toContain('evil')
        ->and($stored)->not->toContain('iframe')
        ->and($stored)->not->toContain('<img');
});

test('content that is empty after sanitising is rejected', function () {
    $type = ctpType();
    ctpActAs();

    $this->putJson(ctpUrl($type), ['content' => '<script>alert(1)</script>'])->assertUnprocessable();
    $this->putJson(ctpUrl($type), ['content' => ''])->assertUnprocessable()->assertJsonValidationErrors('content');
});

test('invalid render settings are rejected', function (array $override, string $field) {
    $type = ctpType();
    ctpActAs();

    $this->putJson(ctpUrl($type), ctpPayload($override))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'paper size'        => [['paper_size' => 'A0'], 'paper_size'],
    'orientation'       => [['orientation' => 'diagonal'], 'orientation'],
    'margin too small'  => [['margin_top' => 1], 'margin_top'],
    'margin too large'  => [['margin_left' => 500], 'margin_left'],
    'margin not number' => [['margin_right' => 'abc'], 'margin_right'],
    'unknown required'  => [['required_fields' => ['full_name', 'ssn']], 'required_fields.1'],
    'duplicate required' => [['required_fields' => ['full_name', 'full_name']], 'required_fields.0'],
    'note too long'     => [['change_note' => str_repeat('x', 256)], 'change_note'],
]);

test('a required field that the template never uses is rejected', function () {
    $type = ctpType();
    ctpActAs();

    $this->putJson(ctpUrl($type), ctpPayload(['required_fields' => ['full_name', 'gwa']]))
        ->assertUnprocessable()
        ->assertJsonPath('errors.required_fields.0', 'Required field(s) not used in the template: gwa.');
});

test('an oversized template is rejected', function () {
    $type = ctpType();
    ctpActAs();
    config(['certificate_templates.max_content_length' => 50]);

    $this->putJson(ctpUrl($type), ctpPayload(['content' => '<p>' . str_repeat('a', 100) . '</p>', 'required_fields' => []]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('content');
});

test('an archived certificate type is locked with 423 for save and activate', function () {
    $type = ctpType();
    ctpActAs();
    $this->putJson(ctpUrl($type), ctpPayload())->assertCreated();
    $type->update(['is_archived' => true]);

    $this->putJson(ctpUrl($type), ctpPayload(['content' => '<p>{{ full_name }} {{ course }} x</p>']))->assertStatus(423);
    $this->postJson(ctpUrl($type, '/versions/1/activate'))->assertStatus(423);

    expect(CertificateTemplate::count())->toBe(1);
});

// ── Storage failure & integrity ──────────────────────────────────────────────

test('a failed object-store write saves nothing and returns 503', function () {
    $type = ctpType();
    ctpActAs();
    $this->partialMock(CertificateTemplateStorage::class, function ($mock) {
        $mock->shouldReceive('put')->andThrow(new TemplateStorageException('bucket down'));
    });

    $this->putJson(ctpUrl($type), ctpPayload())
        ->assertStatus(503)
        ->assertJsonMissing(['message' => 'bucket down']);

    expect(CertificateTemplate::count())->toBe(0)
        ->and(AuditLog::where('action', AuditLog::ACTION_CERTIFICATE_TEMPLATE_VERSION_CREATED)->count())->toBe(0);
});

test('if the database step fails after the object was written, the object is removed and no row or audit entry remains', function () {
    $type = ctpType();
    ctpActAs();
    $this->mock(AuditLogger::class, function ($mock) {
        $mock->shouldReceive('log')->andThrow(new RuntimeException('audit down'));
    });

    $this->putJson(ctpUrl($type), ctpPayload())->assertStatus(500);

    expect(CertificateTemplate::count())->toBe(0)
        ->and(Storage::disk(CTP_DISK)->allFiles())->toBe([]);
});

test('a stored body that no longer matches its checksum is never served', function () {
    $type = ctpType();
    ctpActAs();
    $this->putJson(ctpUrl($type), ctpPayload())->assertCreated();

    Storage::disk(CTP_DISK)->put(CertificateTemplate::sole()->storage_path, '<p>tampered</p>');
    Cache::flush();

    $this->getJson(ctpUrl($type))
        ->assertStatus(503)
        ->assertJsonMissing(['content' => '<p>tampered</p>']);
});

test('a missing object returns 503 rather than falling back silently', function () {
    $type = ctpType();
    ctpActAs();
    $this->putJson(ctpUrl($type), ctpPayload())->assertCreated();

    Storage::disk(CTP_DISK)->delete(CertificateTemplate::sole()->storage_path);
    Cache::flush();

    $this->getJson(ctpUrl($type))->assertStatus(503);
});

// ── Immutability & merge-tag reference ───────────────────────────────────────

test('a stored template version is immutable apart from its active flag', function () {
    $type = ctpType();
    ctpActAs();
    $this->putJson(ctpUrl($type), ctpPayload())->assertCreated();

    // fresh() each time: a rejected update leaves the in-memory model dirty.
    expect(fn () => CertificateTemplate::sole()->update(['content_sha256' => str_repeat('0', 64)]))->toThrow(RuntimeException::class)
        ->and(fn () => CertificateTemplate::sole()->update(['version' => 9]))->toThrow(RuntimeException::class);

    $row = CertificateTemplate::sole();
    $row->update(['is_active' => false]);
    expect($row->fresh()->active_certificate_type_id)->toBeNull();
});

test('the database refuses a second active version for the same type', function () {
    $type = ctpType();
    ctpActAs();
    $this->putJson(ctpUrl($type), ctpPayload())->assertCreated();
    $this->putJson(ctpUrl($type), ctpPayload(['content' => '<p>{{ full_name }} {{ course }} 2</p>']))->assertCreated();

    $old = CertificateTemplate::where('version', 1)->sole();

    expect(fn () => $old->update(['is_active' => true]))->toThrow(\Illuminate\Database\QueryException::class);
});

test('the merge-tag reference lists every supported tag and format', function () {
    ctpActAs();

    $res = $this->getJson('/api/certificate-merge-tags')->assertOk();
    $names = collect($res->json('data'))->pluck('name');

    expect($names)->toContain('full_name', 'student_number', 'course', 'control_number', 'issue_date_formal')
        ->and($res->json('filters'))->toBe(['upper', 'lower', 'title']);
});
