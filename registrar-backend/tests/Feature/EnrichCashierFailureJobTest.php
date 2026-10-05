<?php

use App\Contracts\AlumniSystemClientInterface;
use App\DTOs\Ogos\OgosStudentDTO;
use App\Exceptions\OgosException;
use App\Jobs\EnrichCashierFailureJob;
use App\Models\AuditLog;
use App\Models\FailureReasonCode;
use App\Models\StudentProfile;
use App\Models\SystemUser;
use App\Services\AuditLogger;
use App\Services\CashierFailureDiagnosisService;
use App\Services\Ogos\OgosClient;
use App\Services\Ogos\OgosStudentService;
use Database\Seeders\FailureReasonCodeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Diagnosis codes on the enrichment row + the failure_reason catalog (Phase 2b)
|--------------------------------------------------------------------------
| There were no existing enrichment tests to mirror; these cover the job
| paths this phase touches (snapshot found, OGOS 404, retries exhausted) and
| the guarantee that a diagnosis problem can never stop the row being written.
*/

function ejStudent(array $profile = []): SystemUser
{
    $user = SystemUser::factory()->create([
        'role_id' => SystemUser::ROLE_STUDENT, 'status' => 'Activated', 'email' => 'ej.student@example.com',
    ]);

    StudentProfile::factory()->create(array_merge([
        'user_id' => $user->user_id, 'first_name' => 'Juan', 'middle_name' => 'Santos',
        'last_name' => 'Dela Cruz', 'suffix' => '',
    ], $profile));

    return $user->fresh();
}

function ejSourceRow(SystemUser $user, ?array $attempts = null): AuditLog
{
    return app(AuditLogger::class)->logForSystem($user, AuditLog::ACTION_CASHIER_VERIFICATION, [
        'or_number'      => '1234567',
        'attempts'       => $attempts ?? [
            ['name' => 'DELA CRUZ, JUAN S.', 'valid' => false, 'reason' => 'NOT_FOUND'],
            ['name' => 'JUAN DELA CRUZ',     'valid' => false, 'reason' => 'NOT_FOUND'],
        ],
        'final_approved' => false,
        'failure_reason' => 'NOT_FOUND',
    ]);
}

function ejDto(array $o = []): OgosStudentDTO
{
    return OgosStudentDTO::fromArray(array_merge([
        'studentNumber' => '2026-00001-TG-0', 'email' => 'ej.student@example.com',
        'firstName' => 'Juan', 'middleName' => 'Santos', 'lastName' => 'Dela Cruz', 'suffixName' => '',
        'mobileNumber' => '09170000000',
        'program' => ['id' => 1, 'code' => 'BSIT', 'name' => 'BSIT'], 'yearLevel' => 3, 'section' => '3A',
    ], $o));
}

function ejRunJob(SystemUser $user, AuditLog $source, OgosClient $client): void
{
    (new EnrichCashierFailureJob($source->id, $user->user_id, '1234567'))->handle(
        app(AuditLogger::class),
        new OgosStudentService($client),
        Mockery::mock(AlumniSystemClientInterface::class),
        app(CashierFailureDiagnosisService::class),
    );
}

function ejEnrichmentRow(): AuditLog
{
    return AuditLog::where('action', AuditLog::ACTION_CASHIER_VERIFICATION_ENRICHED)->latest('id')->firstOrFail();
}

test('the enrichment row carries diagnosis codes for a drifted, ñ-containing name', function () {
    $user   = ejStudent(['last_name' => 'Peña']);
    $source = ejSourceRow($user);

    $client = Mockery::mock(OgosClient::class);
    $client->shouldReceive('getStudentByEmail')->once()->andReturn(ejDto(['lastName' => 'Peña-Reyes']));

    ejRunJob($user, $source, $client);

    $meta = ejEnrichmentRow()->metadata;

    expect($meta['source_audit_log_id'])->toBe($source->id)
        ->and($meta['enrichment_status'])->toBe('complete')
        ->and($meta['diagnosis_codes'])->toContain(
            CashierFailureDiagnosisService::PROFILE_DRIFT,
            CashierFailureDiagnosisService::FMT_NON_ASCII,
            CashierFailureDiagnosisService::ALL_CANDIDATES_EXHAUSTED,
        );
});

test('an OGOS 404 yields OGOS_NOT_FOUND', function () {
    $user   = ejStudent();
    $source = ejSourceRow($user);

    $client = Mockery::mock(OgosClient::class);
    $client->shouldReceive('getStudentByEmail')->once()->andThrow(new OgosException('not found', 404));

    ejRunJob($user, $source, $client);

    $meta = ejEnrichmentRow()->metadata;
    expect($meta['enrichment_status'])->toBe('not_found')
        ->and($meta['diagnosis_codes'])->toContain(CashierFailureDiagnosisService::OGOS_NOT_FOUND)
        ->not->toContain(CashierFailureDiagnosisService::NO_SNAPSHOT);
});

test('exhausted retries (failed hook) record OGOS_UNREACHABLE', function () {
    $user   = ejStudent();
    $source = ejSourceRow($user);

    (new EnrichCashierFailureJob($source->id, $user->user_id, '1234567'))->failed(new OgosException('down', 503));

    $meta = ejEnrichmentRow()->metadata;
    expect($meta['enrichment_status'])->toBe('failed')
        ->and($meta['diagnosis_codes'])->toContain(CashierFailureDiagnosisService::OGOS_UNREACHABLE);
});

test('a code switched off in the catalog is not attached', function () {
    FailureReasonCode::where('code', 'ALL_CANDIDATES_EXHAUSTED')->update(['is_active' => false]);
    Cache::flush();

    $user   = ejStudent();
    $source = ejSourceRow($user);

    $client = Mockery::mock(OgosClient::class);
    $client->shouldReceive('getStudentByEmail')->once()->andReturn(ejDto(['lastName' => 'Different']));

    ejRunJob($user, $source, $client);

    $codes = ejEnrichmentRow()->metadata['diagnosis_codes'];
    expect($codes)->toContain(CashierFailureDiagnosisService::PROFILE_DRIFT)
        ->not->toContain(CashierFailureDiagnosisService::ALL_CANDIDATES_EXHAUSTED);
});

test('a code missing from the catalog is still attached (fail open)', function () {
    FailureReasonCode::query()->delete();
    Cache::flush();

    $user   = ejStudent();
    $source = ejSourceRow($user);

    $client = Mockery::mock(OgosClient::class);
    $client->shouldReceive('getStudentByEmail')->once()->andReturn(ejDto(['lastName' => 'Different']));

    ejRunJob($user, $source, $client);

    expect(ejEnrichmentRow()->metadata['diagnosis_codes'])->toContain(CashierFailureDiagnosisService::PROFILE_DRIFT);
});

test('a diagnosis failure never stops the enrichment row being written', function () {
    $user   = ejStudent();
    $source = ejSourceRow($user);

    $broken = Mockery::mock(CashierFailureDiagnosisService::class);
    $broken->shouldReceive('diagnose')->andThrow(new RuntimeException('bug'));

    $client = Mockery::mock(OgosClient::class);
    $client->shouldReceive('getStudentByEmail')->once()->andReturn(ejDto());

    (new EnrichCashierFailureJob($source->id, $user->user_id, '1234567'))->handle(
        app(AuditLogger::class),
        new OgosStudentService($client),
        Mockery::mock(AlumniSystemClientInterface::class),
        $broken,
    );

    $meta = ejEnrichmentRow()->metadata;
    expect($meta['enrichment_status'])->toBe('complete')   // snapshot still captured
        ->and($meta['on_file_snapshot'])->not->toBeNull()
        ->and($meta['diagnosis_codes'])->toBe([]);
});

test('the enrichment adds a NEW linked row and never mutates the original verification row', function () {
    $user   = ejStudent();
    $source = ejSourceRow($user);
    $before = $source->fresh()->getAttributes();

    $client = Mockery::mock(OgosClient::class);
    $client->shouldReceive('getStudentByEmail')->once()->andReturn(ejDto());

    ejRunJob($user, $source, $client);

    expect($source->fresh()->getAttributes())->toBe($before)
        ->and(AuditLog::where('action', AuditLog::ACTION_CASHIER_VERIFICATION_ENRICHED)->count())->toBe(1);
});

// ── failure_reason on the original verification row ──────────────────────

test('a NOT_FOUND cashier verification records failure_reason on its audit row', function () {
    config(['services.cashier.api_key' => 'test-key']);
    \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response(['valid' => false, 'reason' => 'NOT_FOUND', 'data' => null], 200)]);

    $user = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT, 'status' => 'Activated']);
    StudentProfile::factory()->create(['user_id' => $user->user_id, 'first_name' => 'Juan', 'last_name' => 'Dela Cruz']);
    \Laravel\Sanctum\Sanctum::actingAs($user);

    $this->postJson('/api/verify-or', ['or_number' => '0000000', 'receipt_date' => now()->toDateString()])
        ->assertStatus(422);

    $log = AuditLog::where('action', AuditLog::ACTION_CASHIER_VERIFICATION)->latest('id')->firstOrFail();
    expect($log->metadata['failure_reason'])->toBe('NOT_FOUND')
        ->and($log->metadata['final_approved'])->toBeFalse();
});

test('an API_ERROR cashier verification records failure_reason API_ERROR', function () {
    config(['services.cashier.api_key' => 'test-key']);
    \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response('boom', 500)]);

    $user = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT, 'status' => 'Activated']);
    StudentProfile::factory()->create(['user_id' => $user->user_id, 'first_name' => 'Juan', 'last_name' => 'Dela Cruz']);
    \Laravel\Sanctum\Sanctum::actingAs($user);

    $this->postJson('/api/verify-or', ['or_number' => '0000000', 'receipt_date' => now()->toDateString()])
        ->assertStatus(422);

    $log = AuditLog::where('action', AuditLog::ACTION_CASHIER_VERIFICATION)->latest('id')->firstOrFail();
    expect($log->metadata['failure_reason'])->toBe('API_ERROR');
});

// ── the catalog ──────────────────────────────────────────────────────────

test('the migration seeds every starter code, and each starter code matches a code the service can emit', function () {
    $emittable = (new ReflectionClass(CashierFailureDiagnosisService::class))->getConstants();

    foreach (FailureReasonCode::STARTER_CODES as $row) {
        expect(FailureReasonCode::where('code', $row['code'])->exists())->toBeTrue();
        expect($emittable)->toHaveKey($row['code']);
        expect(strlen($row['code']))->toBeLessThanOrEqual(50)
            ->and(strlen($row['description']))->toBeLessThanOrEqual(255);
    }

    expect(FailureReasonCode::count())->toBe(count(FailureReasonCode::STARTER_CODES));
});

test('re-seeding is idempotent and never re-enables a code an operator switched off', function () {
    FailureReasonCode::where('code', 'FMT_NON_ASCII')->update(['is_active' => false, 'description' => 'edited']);

    (new FailureReasonCodeSeeder())->run();
    (new FailureReasonCodeSeeder())->run();

    $row = FailureReasonCode::where('code', 'FMT_NON_ASCII')->sole();
    expect($row->is_active)->toBeFalse()
        ->and($row->description)->toBe('edited')
        ->and(FailureReasonCode::count())->toBe(count(FailureReasonCode::STARTER_CODES));
});

test('no catalog description reads as a fault verdict', function () {
    foreach (FailureReasonCode::STARTER_CODES as $row) {
        expect(strtolower($row['description']))->not->toContain('your fault')
            ->not->toContain('is at fault')
            ->not->toContain('cashier made');
    }
});
