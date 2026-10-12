<?php

use App\Enums\CertificateGenerationStatusEnum;
use App\Enums\RequestStatusEnum;
use App\Exceptions\InvalidCertificateTemplateException;
use App\Models\AccessType;
use App\Models\Alumni;
use App\Models\AlumniAcademicRecord;
use App\Models\AlumniProfile;
use App\Models\AuditLog;
use App\Models\CertificateGeneration;
use App\Models\CertificationType;
use App\Models\DocumentRequest;
use App\Models\RequestCertificate;
use App\Models\Signatory;
use App\Models\StudentAcademicRecord;
use App\Models\StudentProfile;
use App\Models\SystemUser;
use App\Services\Certificates\CertificateLayoutAssets;
use App\Services\Certificates\CertificatePdfRenderer;
use App\Services\Certificates\ControlNumberGenerator;
use App\Services\Certificates\TemplateRenderer;
use App\Services\DocumentRequestService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

const CGN_DISK   = 'cert_gen_test_templates';
const CGN_ASSETS = 'cert_gen_test_assets';

beforeEach(function () {
    config([
        'certificate_templates.disk'     => CGN_DISK,
        'certificate_templates.pdf.temp_dir' => sys_get_temp_dir() . '/ris-dompdf-tests',
        'filesystems.default'            => CGN_ASSETS,
        'filesystems.disks.' . CGN_DISK   => ['driver' => 'local', 'root' => storage_path('framework/testing/disks/' . CGN_DISK)],
        'filesystems.disks.' . CGN_ASSETS => ['driver' => 'local', 'root' => storage_path('framework/testing/disks/' . CGN_ASSETS), 'url' => 'https://assets.test'],
    ]);
    Storage::fake(CGN_DISK);
    // A real temp-dir disk WITH a url, like S3 in production (Storage::fake drops the url).
    $this->assetsRoot = sys_get_temp_dir() . '/ris-cgn-assets-' . uniqid();
    Storage::set(CGN_ASSETS, Storage::build(['driver' => 'local', 'root' => $this->assetsRoot, 'url' => 'https://assets.test']));
    Cache::flush();
});

afterEach(function () {
    File::deleteDirectory($this->assetsRoot);
});

// ── Helpers ──────────────────────────────────────────────────────────────────

function cgnStaff(): SystemUser
{
    $user = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN, 'status' => 'Activated']);
    grantFullDashboardAccess($user);
    Sanctum::actingAs($user);

    return $user;
}

function cgnType(array $overrides = []): CertificationType
{
    AccessType::firstOrCreate(['access_id' => 1], ['access_name' => 'Student']);

    return CertificationType::create(array_merge([
        'certificate_name'           => 'Certificate of Graduation',
        'certificate_requirements'   => 'Valid ID',
        'certificate_process_period' => '3-5 business days',
        'access_id'                  => 1,
    ], $overrides));
}

/** Saves an ACTIVE template through the real Phase 2 endpoint (as a role:3 admin). */
function cgnTemplate(CertificationType $type, array $overrides = []): void
{
    $admin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN, 'status' => 'Activated']);
    Sanctum::actingAs($admin);

    test()->putJson("/api/certifications/{$type->certificate_type_id}/template", array_merge([
        'content' => '<h3 style="text-align:center">CERTIFICATION</h3>'
            . '<p>This is to certify that {{ full_name|upper }} ({{ student_number }}) finished {{ course }}'
            . ' on {{ date_graduated }}. OR {{ official_receipt_number }}. Control {{ control_number }}.'
            . ' Issued {{ issue_date_formal }}. GWA {{ gwa }}.</p>',
        'required_fields' => ['full_name', 'course'],
    ], $overrides))->assertSuccessful();
}

/** An enrolled student with a filed request containing one certificate item of $type. */
function cgnStudent(string $number = '2021-00001-MN-0', string $first = 'Juan', string $last = 'Dela Cruz'): SystemUser
{
    $user    = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT, 'status' => 'Activated']);
    $profile = StudentProfile::factory()->create([
        'user_id' => $user->user_id, 'first_name' => $first, 'middle_name' => 'Santos', 'last_name' => $last, 'suffix' => null,
    ]);
    StudentAcademicRecord::factory()->create([
        'student_profile_id' => $profile->student_profile_id, 'student_number' => $number, 'course' => 'BS Computer Science',
    ]);

    return $user;
}

/** @return array{0: DocumentRequest, 1: RequestCertificate} */
function cgnRequest(SystemUser $owner, CertificationType $type, array $requestOverrides = []): array
{
    static $orSeq = 0; // document_request.or_number is UNIQUE
    $request = DocumentRequest::factory()->create(array_merge([
        'user_id' => $owner->user_id, 'status_id' => RequestStatusEnum::Processing->value, 'or_number' => sprintf('%07d', ++$orSeq),
    ], $requestOverrides));

    $item = RequestCertificate::create([
        'request_id' => $request->request_id, 'certificate_type_id' => $type->certificate_type_id,
        'number_of_copies' => 1, 'status_id' => RequestStatusEnum::Processing->value,
    ]);

    return [$request, $item];
}

function cgnPayload(CertificationType $type, array $overrides = []): array
{
    return array_merge(['certificate_type_id' => $type->certificate_type_id, 'student_num' => '2021-00001-MN-0'], $overrides);
}

/** Swaps the PDF engine for a stub and captures the HTML it was given. */
function cgnCaptureHtml(): object
{
    $spy = new stdClass();
    $spy->html = null;
    test()->mock(CertificatePdfRenderer::class, function ($mock) use ($spy) {
        $mock->shouldReceive('render')->andReturnUsing(function (string $html) use ($spy) {
            $spy->html = $html;

            return '%PDF-1.4 stub';
        });
    });

    return $spy;
}

function cgnYear(): int
{
    return (int) CarbonImmutable::now('Asia/Manila')->format('Y');
}

// ── Access ───────────────────────────────────────────────────────────────────

test('only staff with dashboard process access can generate certificates', function () {
    $type = cgnType();
    cgnTemplate($type);
    cgnStudent();

    auth()->forgetGuards();
    $this->postJson('/api/certificates/generate', cgnPayload($type))->assertUnauthorized();

    Sanctum::actingAs(SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT, 'status' => 'Activated']));
    $this->postJson('/api/certificates/generate', cgnPayload($type))->assertForbidden();

    // An admin whose policy grants nothing is refused by the module gate.
    Sanctum::actingAs(SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN, 'status' => 'Activated']));
    $this->postJson('/api/certificates/generate', cgnPayload($type))->assertForbidden();

    expect(CertificateGeneration::count())->toBe(0);
});

// ── Success path ─────────────────────────────────────────────────────────────

test('generates a real PDF, returns the control number, records it and stores no PDF', function () {
    $type = cgnType();
    cgnTemplate($type);
    $student = cgnStudent();
    [$request, $item] = cgnRequest($student, $type);
    $staff = cgnStaff();

    $response = $this->postJson('/api/certificates/generate', cgnPayload($type, [
        'request_id' => $request->request_id,
        'fields'     => ['gwa' => '1.4500'],
    ]));

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('X-Template-Version', '1');

    $control = $response->headers->get('X-Control-Number');
    expect($control)->toBe('PUP-CERT-' . cgnYear() . '-0001')
        ->and($response->headers->get('Content-Disposition'))->toContain($control . '.pdf')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and(substr($response->getContent(), 0, 5))->toBe('%PDF-');

    $record = CertificateGeneration::sole();
    expect($record->control_number)->toBe($control)
        ->and($record->status)->toBe(CertificateGenerationStatusEnum::Generated)
        ->and($record->template_version)->toBe(1)
        ->and($record->certificate_type_id)->toBe($type->certificate_type_id)
        ->and($record->requester_user_id)->toBe($student->user_id)
        ->and($record->request_id)->toBe($request->request_id)
        ->and($record->generated_by)->toBe($staff->user_id)
        ->and($record->generated_at)->not->toBeNull()
        ->and($record->payload_snapshot['full_name'])->toBe('Juan Santos Dela Cruz')
        ->and($record->payload_snapshot['gwa'])->toBe('1.4500')
        ->and($record->payload_snapshot['control_number'])->toBe($control);

    // Workflow: the existing real "generated" signal is set, status is untouched.
    expect($item->fresh()->generated_at)->not->toBeNull()
        ->and((int) $request->fresh()->status_id)->toBe(RequestStatusEnum::Processing->value);

    // Audit: control number + template version, never the document.
    $log = AuditLog::where('action', AuditLog::ACTION_CERTIFICATE_GENERATED)->sole();
    expect($log->metadata['control_number'])->toBe($control)
        ->and($log->metadata['template_version'])->toBe(1)
        ->and(json_encode($log->metadata))->not->toContain('Juan');

    // Nothing but the template object exists on either disk — the PDF is never archived.
    expect(Storage::disk(CGN_DISK)->allFiles())->toHaveCount(1)
        ->and(Storage::disk(CGN_ASSETS)->allFiles())->toBe([]);
});

test('the merge values are escaped, formatted, and server-resolved', function () {
    $type = cgnType();
    cgnTemplate($type);
    cgnStudent();
    cgnStaff();
    $spy = cgnCaptureHtml();

    $this->postJson('/api/certificates/generate', cgnPayload($type, ['fields' => ['gwa' => '<script>alert(1)</script>']]))->assertOk();

    expect($spy->html)->toContain('JUAN SANTOS DELA CRUZ')
        ->toContain('(2021-00001-MN-0)')
        ->toContain('BS Computer Science')
        ->toContain('PUP-CERT-' . cgnYear() . '-0001')
        ->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->not->toContain('<script>')
        ->not->toContain('{{');
});

test('a request cannot override a server-resolved value', function () {
    $type = cgnType();
    cgnTemplate($type);
    cgnStudent();
    cgnStaff();

    foreach (['full_name', 'course', 'official_receipt_number', 'control_number', 'issue_date', 'not_a_tag'] as $field) {
        $this->postJson('/api/certificates/generate', cgnPayload($type, ['fields' => [$field => 'Hacked']]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('fields');
    }

    expect(CertificateGeneration::count())->toBe(0);
});

test('the latest OR number and the selected signatory are merged from the backend', function () {
    $type = cgnType();
    cgnTemplate($type, [
        'content' => '<p>{{ full_name }} OR {{ official_receipt_number }} by {{ signee_name }}, {{ signee_position }}</p>',
        'required_fields' => ['full_name'],
    ]);
    $student = cgnStudent();
    cgnRequest($student, $type, ['or_number' => '0000001', 'requested_at' => now()->subDay()]);
    cgnRequest($student, $type, ['or_number' => '0000002', 'requested_at' => now()]);
    $signatory = Signatory::create(['name' => 'Maria Reyes', 'position' => 'Campus Registrar', 'sort_order' => 1]);
    cgnStaff();
    $spy = cgnCaptureHtml();

    $this->postJson('/api/certificates/generate', cgnPayload($type, ['signatory_id' => $signatory->signatory_id]))->assertOk();

    expect($spy->html)->toContain('OR 0000002')->toContain('Maria Reyes, Campus Registrar');
});

test('alumni are resolved server-side too', function () {
    $type = cgnType();
    cgnTemplate($type);
    $user  = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ALUMNI, 'status' => 'Activated']);
    $alum  = Alumni::create(['user_id' => $user->user_id, 'alumni_type_id' => Alumni::TYPE_SIS]);
    $prof  = AlumniProfile::create(['alumni_id' => $alum->alumni_id, 'first_name' => 'Ana', 'last_name' => 'Cruz', 'date_of_birth' => '1997-01-01', 'sex_at_birth' => 'Female']);
    AlumniAcademicRecord::create(['alumni_profile_id' => $prof->alumni_profile_id, 'student_number' => '2015-00003-MN-0', 'year_of_graduation' => 2019, 'course' => 'BS Accountancy']);
    cgnStaff();
    $spy = cgnCaptureHtml();

    $this->postJson('/api/certificates/generate', cgnPayload($type, ['student_num' => '2015-00003-MN-0']))->assertOk();

    expect($spy->html)->toContain('ANA CRUZ')->toContain('BS Accountancy')->toContain('on 2019.');
});

test('the real PDF engine honours paper size and orientation', function () {
    $type = cgnType();
    cgnTemplate($type, ['paper_size' => 'LETTER', 'orientation' => 'landscape', 'margin_top' => 10, 'margin_bottom' => 10]);
    cgnStudent();
    cgnStaff();

    $pdf = $this->postJson('/api/certificates/generate', cgnPayload($type))->assertOk()->getContent();

    expect($pdf)->toMatch('~/MediaBox\s*\[\s*0(\.0+)?\s+0(\.0+)?\s+792(\.0+)?\s+612(\.0+)?\s*\]~');
});

// ── Control numbers ──────────────────────────────────────────────────────────

test('control numbers are sequential, unique and follow the agreed format', function () {
    $type = cgnType();
    cgnTemplate($type);
    cgnStudent();
    cgnStaff();
    cgnCaptureHtml();

    $numbers = collect(range(1, 3))->map(fn () => $this->postJson('/api/certificates/generate', cgnPayload($type))->assertOk()->headers->get('X-Control-Number'));

    $y = cgnYear();
    expect($numbers->all())->toBe(["PUP-CERT-{$y}-0001", "PUP-CERT-{$y}-0002", "PUP-CERT-{$y}-0003"])
        ->and(CertificateGeneration::distinct()->count('control_number'))->toBe(3);
});

test('the sequence restarts each calendar year and grows past the padding', function () {
    $gen = app(ControlNumberGenerator::class);
    expect($gen->format(2026, 42))->toBe('PUP-CERT-2026-0042')
        ->and($gen->format(2026, 12345))->toBe('PUP-CERT-2026-12345');

    $type = cgnType();
    cgnTemplate($type);
    cgnStudent();
    cgnStaff();
    cgnCaptureHtml();

    $this->travelTo(CarbonImmutable::parse('2026-12-31 23:00', 'Asia/Manila'));
    $this->postJson('/api/certificates/generate', cgnPayload($type))->assertOk()->assertHeader('X-Control-Number', 'PUP-CERT-2026-0001');

    $this->travelTo(CarbonImmutable::parse('2027-01-01 00:30', 'Asia/Manila'));
    $this->postJson('/api/certificates/generate', cgnPayload($type))->assertOk()->assertHeader('X-Control-Number', 'PUP-CERT-2027-0001');

    $this->travelBack();
});

test('the database refuses a duplicate control number', function () {
    $type = cgnType();
    cgnTemplate($type);
    cgnStudent();
    cgnStaff();
    cgnCaptureHtml();
    $this->postJson('/api/certificates/generate', cgnPayload($type))->assertOk();

    $row = CertificateGeneration::sole();
    expect(fn () => CertificateGeneration::create($row->only([
        'control_number', 'certificate_type_id', 'certificate_template_id', 'template_version', 'student_number',
    ]) + ['status' => CertificateGenerationStatusEnum::Pending]))->toThrow(\Illuminate\Database\QueryException::class);
});

test('control numbers can only be allocated inside a transaction', function () {
    // RefreshDatabase wraps the test in one, so step out to prove the guard.
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }

    expect(fn () => app(ControlNumberGenerator::class)->next())->toThrow(LogicException::class);

    DB::beginTransaction(); // leave one open for the framework's own teardown
});

// ── Legacy fallback & template availability ──────────────────────────────────

test('without an active template the API answers legacy and consumes nothing', function () {
    $type = cgnType();
    $student = cgnStudent();
    [$request, $item] = cgnRequest($student, $type);
    cgnStaff();

    $this->postJson('/api/certificates/generate', cgnPayload($type, ['request_id' => $request->request_id]))
        ->assertStatus(409)
        ->assertJsonPath('code', 'template_not_configured')
        ->assertJsonPath('source', 'legacy');

    expect(CertificateGeneration::count())->toBe(0)
        ->and(DB::table('certificate_control_sequence')->count())->toBe(0)
        ->and($item->fresh()->generated_at)->toBeNull();
});

test('an archived certificate type is locked', function () {
    $type = cgnType();
    cgnTemplate($type);
    cgnStudent();
    $type->update(['is_archived' => true]);
    cgnStaff();

    $this->postJson('/api/certificates/generate', cgnPayload($type))->assertStatus(423)->assertJsonPath('code', 'certificate_type_archived');
    expect(CertificateGeneration::count())->toBe(0);
});

test('a missing template object returns 503 and does not touch the request', function () {
    $type = cgnType();
    cgnTemplate($type);
    $student = cgnStudent();
    [$request, $item] = cgnRequest($student, $type);
    cgnStaff();
    Storage::disk(CGN_DISK)->deleteDirectory('certificate-templates');

    $this->postJson('/api/certificates/generate', cgnPayload($type, ['request_id' => $request->request_id]))
        ->assertStatus(503)
        ->assertJsonPath('code', 'template_storage_unavailable');

    expect(CertificateGeneration::count())->toBe(0)->and($item->fresh()->generated_at)->toBeNull();
});

// ── Input validation & required fields ───────────────────────────────────────

test('invalid input is rejected before anything is looked up', function () {
    $type = cgnType();
    cgnTemplate($type);
    cgnStudent();
    cgnStaff();

    foreach ([
        ['student_num' => '2021 0001; DROP TABLE'],
        ['student_num' => str_repeat('1', 51)],
        ['student_num' => null],
        ['certificate_type_id' => null],
        ['certificate_type_id' => 999999],
        ['signatory_id' => 999999],
        ['request_certificate_id' => 5],   // item id without a request id
        ['fields' => 'gwa'],
        ['fields' => ['gwa' => str_repeat('x', 256)]],
    ] as $bad) {
        $this->postJson('/api/certificates/generate', array_merge(cgnPayload($type), $bad))->assertUnprocessable();
    }

    expect(CertificateGeneration::count())->toBe(0);
});

test('an unknown student number returns 404', function () {
    $type = cgnType();
    cgnTemplate($type);
    cgnStaff();

    $this->postJson('/api/certificates/generate', cgnPayload($type, ['student_num' => '1999-99999-XX-0']))
        ->assertNotFound()->assertJsonPath('code', 'student_not_found');
});

test('missing required fields are listed and burn no control number', function () {
    $type = cgnType();
    cgnTemplate($type, ['required_fields' => ['full_name', 'gwa']]);
    $student = cgnStudent();
    [$request, $item] = cgnRequest($student, $type);
    cgnStaff();

    $this->postJson('/api/certificates/generate', cgnPayload($type, ['request_id' => $request->request_id]))
        ->assertUnprocessable()
        ->assertJsonPath('code', 'missing_required_fields')
        ->assertJsonPath('errors.fields', ['gwa']);

    expect(CertificateGeneration::count())->toBe(0)
        ->and(DB::table('certificate_control_sequence')->count())->toBe(0)
        ->and($item->fresh()->generated_at)->toBeNull();

    // Supplying it succeeds and gets the FIRST number.
    cgnCaptureHtml();
    $this->postJson('/api/certificates/generate', cgnPayload($type, ['request_id' => $request->request_id, 'fields' => ['gwa' => '1.2']]))
        ->assertOk()->assertHeader('X-Control-Number', 'PUP-CERT-' . cgnYear() . '-0001');
});

test('a record with no linked profile yields no name, so a required full_name is reported rather than printed blank', function () {
    $resolver = app(\App\Services\Certificates\CertificateDataResolver::class);
    $orphan   = new \App\DTOs\AcademicRecords\ResolvedAcademicRecord(
        studentNumber: '2021-00001-MN-0', course: 'BS CS', graduationDate: null,
        status: \App\Enums\AcademicRecordStatusEnum::Enrolled,
        requesterType: \App\Enums\RequesterTypeEnum::Student, userId: null,
    );

    expect($resolver->fullName($orphan))->toBeNull();

    $data = $resolver->build($orphan, null, null, ['full_name' => 'Forged Name', 'gwa' => '1.0'], CarbonImmutable::now());
    expect($data['full_name'])->toBeNull()                       // the forged value never wins
        ->and($data['gwa'])->toBe('1.0')
        ->and(app(TemplateRenderer::class)->missingRequired(['full_name'], $data))->toBe(['full_name']);
});

// ── Request workflow integration ─────────────────────────────────────────────

test('a request that belongs to someone else is refused', function () {
    $type = cgnType();
    cgnTemplate($type);
    cgnStudent();
    $other = cgnStudent('2022-00002-MN-0', 'Pedro', 'Reyes');
    [$request, $item] = cgnRequest($other, $type);
    cgnStaff();

    $this->postJson('/api/certificates/generate', cgnPayload($type, ['request_id' => $request->request_id]))
        ->assertUnprocessable()->assertJsonPath('code', 'request_student_mismatch');

    expect(CertificateGeneration::count())->toBe(0)->and($item->fresh()->generated_at)->toBeNull();
});

test('the request must actually contain that certificate type', function () {
    $type  = cgnType();
    $other = cgnType(['certificate_name' => 'Certificate of Enrollment']);
    cgnTemplate($type);
    $student = cgnStudent();
    [$request, $item] = cgnRequest($student, $other);
    cgnStaff();

    $this->postJson('/api/certificates/generate', cgnPayload($type, ['request_id' => $request->request_id]))
        ->assertUnprocessable()->assertJsonPath('code', 'request_missing_certificate');

    $this->postJson('/api/certificates/generate', cgnPayload($type, ['request_id' => $request->request_id, 'request_certificate_id' => $item->request_certificate_id]))
        ->assertUnprocessable()->assertJsonPath('code', 'certificate_item_mismatch');

    expect(CertificateGeneration::count())->toBe(0);
});

test('closed and archived requests are refused', function () {
    $type = cgnType();
    cgnTemplate($type);
    $student = cgnStudent();
    [$done] = cgnRequest($student, $type, ['status_id' => RequestStatusEnum::Completed->value]);
    [$archived] = cgnRequest($student, $type);
    $archived->update(['is_archived' => true]);
    cgnStaff();

    $this->postJson('/api/certificates/generate', cgnPayload($type, ['request_id' => $done->request_id]))
        ->assertUnprocessable()->assertJsonPath('code', 'request_closed');
    $this->postJson('/api/certificates/generate', cgnPayload($type, ['request_id' => $archived->request_id]))
        ->assertNotFound()->assertJsonPath('code', 'request_not_found');

    expect(CertificateGeneration::count())->toBe(0);
});

test('generation without a request touches no workflow state', function () {
    $type = cgnType();
    cgnTemplate($type);
    $student = cgnStudent();
    [, $item] = cgnRequest($student, $type);
    cgnStaff();
    cgnCaptureHtml();

    $this->postJson('/api/certificates/generate', cgnPayload($type))->assertOk();

    expect($item->fresh()->generated_at)->toBeNull()->and(CertificateGeneration::sole()->request_id)->toBeNull();
});

test('re-issuing for an already generated item gets a new control number and keeps the first generated_at', function () {
    $type = cgnType();
    cgnTemplate($type);
    $student = cgnStudent();
    [$request, $item] = cgnRequest($student, $type);
    cgnStaff();
    cgnCaptureHtml();
    $payload = cgnPayload($type, ['request_id' => $request->request_id]);

    $this->postJson('/api/certificates/generate', $payload)->assertOk();
    $first = $item->fresh()->generated_at;
    $this->travel(1)->hours();
    $this->postJson('/api/certificates/generate', $payload)->assertOk()
        ->assertHeader('X-Control-Number', 'PUP-CERT-' . cgnYear() . '-0002');

    expect($item->fresh()->generated_at->equalTo($first))->toBeTrue();
});

// ── Failure handling ─────────────────────────────────────────────────────────

test('a PDF engine failure marks the record failed and never completes the request', function () {
    $type = cgnType();
    cgnTemplate($type);
    $student = cgnStudent();
    [$request, $item] = cgnRequest($student, $type);
    cgnStaff();
    // One stub that fails once, then recovers (a transient engine fault). The
    // router caches the controller per test process, so re-mocking mid-test would not take.
    $calls = 0;
    $this->mock(CertificatePdfRenderer::class, fn ($m) => $m->shouldReceive('render')->andReturnUsing(function () use (&$calls) {
        if (++$calls === 1) {
            throw new RuntimeException('engine exploded: secret detail');
        }

        return '%PDF-1.4 stub';
    }));

    $res = $this->postJson('/api/certificates/generate', cgnPayload($type, ['request_id' => $request->request_id]))
        ->assertStatus(500)->assertJsonPath('code', 'generation_failed');
    expect($res->getContent())->not->toContain('secret detail');

    $record = CertificateGeneration::sole();
    expect($record->status)->toBe(CertificateGenerationStatusEnum::Failed)
        ->and($record->failure_code)->toBe('render_error')
        ->and($record->generated_at)->toBeNull()
        ->and($item->fresh()->generated_at)->toBeNull()
        ->and((int) $request->fresh()->status_id)->toBe(RequestStatusEnum::Processing->value)
        ->and(AuditLog::where('action', AuditLog::ACTION_CERTIFICATE_GENERATED)->count())->toBe(0)
        ->and(AuditLog::where('action', AuditLog::ACTION_CERTIFICATE_GENERATION_FAILED)->sole()->metadata['failure_code'])->toBe('render_error');

    // The failed number stays reserved: the next success is -0002, an explained gap.
    $this->postJson('/api/certificates/generate', cgnPayload($type, ['request_id' => $request->request_id]))
        ->assertOk()->assertHeader('X-Control-Number', 'PUP-CERT-' . cgnYear() . '-0002');
});

test('a corrupt stored template fails closed with a clear message', function () {
    $type = cgnType();
    cgnTemplate($type);
    cgnStudent();
    cgnStaff();
    $this->instance(TemplateRenderer::class, new class(app(\App\Services\Certificates\MergeTags\MergeTagRegistry::class), app(\App\Services\Certificates\TemplateSanitizer::class)) extends TemplateRenderer {
        public function render(string $html, array $data, array $requiredFields = []): string
        {
            throw new InvalidCertificateTemplateException(['content' => ['bad']]);
        }
    });

    $this->postJson('/api/certificates/generate', cgnPayload($type))
        ->assertUnprocessable()->assertJsonPath('code', 'template_invalid');

    expect(CertificateGeneration::sole()->failure_code)->toBe('template_invalid');
});

test('if the workflow update is rejected nothing is marked generated and the record is failed', function () {
    $type = cgnType();
    cgnTemplate($type);
    $student = cgnStudent();
    [$request, $item] = cgnRequest($student, $type);
    cgnStaff();
    cgnCaptureHtml();
    $this->mock(DocumentRequestService::class, fn ($m) => $m->shouldReceive('markCertificatesGenerated')->andThrow(new HttpException(422, 'This request is archived and is read-only. Restore it first.')));

    $this->postJson('/api/certificates/generate', cgnPayload($type, ['request_id' => $request->request_id]))->assertStatus(422);

    $record = CertificateGeneration::sole();
    expect($record->status)->toBe(CertificateGenerationStatusEnum::Failed)
        ->and($record->failure_code)->toBe('workflow_rejected')
        ->and($record->generated_at)->toBeNull()
        ->and($item->fresh()->generated_at)->toBeNull()
        ->and(AuditLog::where('action', AuditLog::ACTION_CERTIFICATE_GENERATED)->count())->toBe(0);
});

// ── Records, snapshot, immutability ──────────────────────────────────────────

test('the payload snapshot can be switched off', function () {
    config(['certificate_templates.store_payload_snapshot' => false]);
    $type = cgnType();
    cgnTemplate($type);
    cgnStudent();
    cgnStaff();
    cgnCaptureHtml();

    $this->postJson('/api/certificates/generate', cgnPayload($type))->assertOk();

    expect(CertificateGeneration::sole()->payload_snapshot)->toBeNull();
});

test('a generation record keeps the version it used and cannot be rewritten', function () {
    $type = cgnType();
    cgnTemplate($type);
    cgnStudent();
    cgnStaff();
    cgnCaptureHtml();
    $this->postJson('/api/certificates/generate', cgnPayload($type))->assertOk();

    // A new template version later must not change what the record says.
    cgnTemplate($type, ['content' => '<p>New wording {{ full_name }} {{ course }}</p>']);
    $record = CertificateGeneration::sole();
    expect($record->template_version)->toBe(1);

    expect(fn () => $record->update(['student_number' => 'tampered']))->toThrow(RuntimeException::class)
        ->and(fn () => $record->update(['status' => CertificateGenerationStatusEnum::Failed]))->toThrow(RuntimeException::class);
});

// ── Layout assets (logos) ────────────────────────────────────────────────────

test('logos are embedded from the app disk only', function () {
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    Storage::disk(CGN_ASSETS)->put('certification-layouts/1/header_left/logo.png', $png);
    Storage::disk(CGN_ASSETS)->put('certification-layouts/1/header_left/not-image.png', 'just text');
    Storage::disk(CGN_ASSETS)->put('secret.txt', 'top secret');
    $assets = app(CertificateLayoutAssets::class);

    expect($assets->dataUri('https://assets.test/certification-layouts/1/header_left/logo.png'))->toStartWith('data:image/png;base64,')
        ->and($assets->dataUri('certification-layouts/1/header_left/logo.png'))->toStartWith('data:image/png;base64,')
        ->and($assets->dataUri('https://assets.test/certification-layouts/1/header_left/logo.png?X-Amz-Signature=abc'))->toStartWith('data:image/png')
        // foreign host: never fetched
        ->and($assets->dataUri('https://evil.example/logo.png'))->toBeNull()
        ->and($assets->dataUri('http://169.254.169.254/latest/meta-data'))->toBeNull()
        // traversal, encoded traversal, non-image, missing, empty
        ->and($assets->dataUri('https://assets.test/../.env'))->toBeNull()
        ->and($assets->dataUri('https://assets.test/%2e%2e/.env'))->toBeNull()
        ->and($assets->dataUri('https://assets.test/secret.txt'))->toBeNull()
        ->and($assets->dataUri('https://assets.test/certification-layouts/1/header_left/not-image.png'))->toBeNull()
        ->and($assets->dataUri('https://assets.test/nope.png'))->toBeNull()
        ->and($assets->dataUri(null))->toBeNull()
        ->and($assets->dataUri('  '))->toBeNull();
});

test('the letterhead (header lines and logos) is part of the generated page', function () {
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    Storage::disk(CGN_ASSETS)->put('logo.png', $png);
    $type = cgnType([
        'layout_header_left_url' => 'https://assets.test/logo.png',
        'layout_header_lines'    => ['Republic of the Philippines', 'POLYTECHNIC UNIVERSITY <of> the Philippines'],
        'layout_header_font_size' => 11,
    ]);
    cgnTemplate($type);
    cgnStudent();
    cgnStaff();

    // Real renderer, observing the final page through a spy on the engine's input.
    $renderer = app(CertificatePdfRenderer::class);
    $tpl      = \App\Models\CertificateTemplate::where('certificate_type_id', $type->certificate_type_id)->sole();
    $method   = new ReflectionMethod($renderer, 'page');
    $html     = $method->invoke($renderer, '<p>BODY</p>', $tpl, $type->fresh(), 'PUP-CERT-2026-0001');

    expect($html)->toContain('Republic of the Philippines')
        ->toContain('POLYTECHNIC UNIVERSITY &lt;of&gt; the Philippines')   // header text is escaped
        ->toContain('data:image/png;base64,')
        ->toContain('<p>BODY</p>')
        ->not->toContain('https://assets.test');

    // And the real engine accepts it.
    $pdf = $renderer->render('<p>BODY</p>', $tpl, $type->fresh(), 'PUP-CERT-2026-0001');
    expect(substr($pdf, 0, 5))->toBe('%PDF-');
});
