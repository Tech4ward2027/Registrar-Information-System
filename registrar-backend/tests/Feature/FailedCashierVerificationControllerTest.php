<?php

use App\Contracts\CashierServiceInterface;
use App\Models\AuditLog;
use App\Models\FailureReasonCode;
use App\Models\Policy;
use App\Models\SecurityEvent;
use App\Models\StudentProfile;
use App\Models\SystemUser;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// Helpers are fv-prefixed because Pest helper functions are global.

const FV_LIST = '/api/failed-cashier-verifications';

beforeEach(function () {
    config(['features.system_health' => true]);
    Cache::flush();
});

function fvAdmin(array $permissions = ['cashier_reconciliation' => ['Access']]): SystemUser
{
    $policy = Policy::create([
        'name' => 'Recon Staff ' . uniqid(), 'permissions' => $permissions, 'is_system' => false,
    ]);
    $admin = SystemUser::factory()->create([
        'role_id' => SystemUser::ROLE_ADMIN, 'status' => 'Activated', 'policy_id' => $policy->policy_id,
    ]);
    Sanctum::actingAs($admin);

    return $admin;
}

function fvStudent(string $email = 'fv.student@example.com'): SystemUser
{
    $user = SystemUser::factory()->create([
        'role_id' => SystemUser::ROLE_STUDENT, 'status' => 'Activated', 'email' => $email,
    ]);
    StudentProfile::factory()->create([
        'user_id' => $user->user_id, 'first_name' => 'Juan', 'middle_name' => 'Santos',
        'last_name' => 'Dela Cruz', 'suffix' => '',
    ]);

    return $user;
}

function fvAudit(SystemUser $user, string $action, array $metadata, ?\DateTimeInterface $at = null): AuditLog
{
    return AuditLog::create([
        'user_id' => $user->user_id, 'email' => $user->email, 'role_name' => 'student',
        'action' => $action, 'metadata' => $metadata,
        'hash' => str_repeat('0', 64), 'prev_hash' => '0',
        'created_at' => $at ?? now(),
    ]);
}

function fvFailure(SystemUser $user, string $or = '123456', array $extra = [], ?\DateTimeInterface $at = null): AuditLog
{
    return fvAudit($user, AuditLog::ACTION_CASHIER_VERIFICATION, array_merge([
        'or_number' => $or,
        'attempts' => [
            ['name' => 'DELA CRUZ, JUAN S.', 'valid' => false, 'reason' => 'NOT_FOUND'],
            ['name' => 'DELA CRUZ, JUAN SANTOS', 'valid' => false, 'reason' => 'NOT_FOUND'],
        ],
        'matched_name' => null, 'is_mock' => false, 'final_approved' => false,
        'failure_reason' => 'NOT_FOUND',
    ], $extra), $at);
}

function fvEnrich(SystemUser $user, AuditLog $source, array $codes, array $extra = []): AuditLog
{
    return fvAudit($user, AuditLog::ACTION_CASHIER_VERIFICATION_ENRICHED, array_merge([
        'source_audit_log_id' => $source->id,
        'or_number' => $source->metadata['or_number'],
        'source_system' => 'ogos',
        'on_file_snapshot' => ['first_name' => 'JUAN', 'last_name' => 'DELA CRUZ', 'middle_name' => 'SANTOS', 'suffix' => ''],
        'enrichment_status' => 'complete', 'failure_reason' => null,
        'diagnosis_codes' => $codes,
    ], $extra));
}

// ---------------------------------------------------------------- access

test('every route rejects unauthenticated, student and module-less admin callers', function () {
    $this->getJson(FV_LIST)->assertUnauthorized();
    $this->postJson(FV_LIST . '/1/recheck')->assertUnauthorized();

    $student = fvStudent();
    Sanctum::actingAs($student);
    $this->getJson(FV_LIST)->assertForbidden();
    $this->getJson(FV_LIST . '/1')->assertForbidden();
    $this->postJson(FV_LIST . '/1/recheck')->assertForbidden();

    fvAdmin(['dashboard' => ['View']]); // a policy WITHOUT cashier_reconciliation
    $this->getJson(FV_LIST)->assertForbidden();
    $this->getJson(FV_LIST . '/1')->assertForbidden();
    $this->postJson(FV_LIST . '/1/recheck')->assertForbidden();
});

test('an admin with the module and a super admin can list', function () {
    fvAdmin();
    $this->getJson(FV_LIST)->assertOk();

    Sanctum::actingAs(SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN, 'status' => 'Activated']));
    $this->getJson(FV_LIST)->assertOk();
});

test('every route is 404 while the system_health flag is off', function () {
    config(['features.system_health' => false]);
    fvAdmin();

    $this->getJson(FV_LIST)->assertNotFound();
    $this->getJson(FV_LIST . '/1')->assertNotFound();
    $this->postJson(FV_LIST . '/1/recheck')->assertNotFound();
});

// ---------------------------------------------------------------- list

test('the list holds only real failed verifications, joined to their enrichment codes', function () {
    $student = fvStudent();
    $failed  = fvFailure($student, '111111');
    fvEnrich($student, $failed, ['FMT_MISSING_MIDDLE']);

    // Not failures: an approved attempt, an admin override, another action.
    fvAudit($student, AuditLog::ACTION_CASHIER_VERIFICATION, ['or_number' => '222222', 'final_approved' => true, 'is_mock' => false]);
    fvAudit($student, AuditLog::ACTION_CASHIER_VERIFICATION, ['or_number' => '333333', 'method' => 'admin_override', 'final_approved' => true]);
    fvAudit($student, AuditLog::ACTION_LOGIN, ['final_approved' => false]);

    fvAdmin();
    $res = $this->getJson(FV_LIST)->assertOk();

    expect($res->json('data'))->toHaveCount(1);
    $row = $res->json('data.0');
    expect($row['id'])->toBe($failed->id)
        ->and($row['or_number'])->toBe('111111')
        ->and($row['failure_reason'])->toBe('NOT_FOUND')
        ->and($row['attempts_count'])->toBe(2)
        ->and($row['enrichment_status'])->toBe('complete')
        ->and($row['diagnosis_codes'])->toBe(['FMT_MISSING_MIDDLE']);
});

test('the list filters by exact OR number, email prefix, reason and diagnosis code', function () {
    $a = fvStudent('alpha.one@example.com');
    $b = fvStudent('beta.two@example.com');

    $fa = fvFailure($a, '100001');
    $fb = fvFailure($b, '100002', ['failure_reason' => 'API_ERROR']);
    fvEnrich($a, $fa, ['FMT_NON_ASCII']);
    fvEnrich($b, $fb, ['OGOS_NOT_FOUND']);

    fvAdmin();

    expect($this->getJson(FV_LIST . '?or_number=100001')->json('data'))->toHaveCount(1);
    // OR search is exact: a fragment finds nothing.
    expect($this->getJson(FV_LIST . '?or_number=1000')->json('data'))->toHaveCount(0);

    expect(collect($this->getJson(FV_LIST . '?email=beta')->json('data'))->pluck('id')->all())->toBe([$fb->id]);
    expect($this->getJson(FV_LIST . '?reason=API_ERROR')->json('data.0.id'))->toBe($fb->id);
    expect($this->getJson(FV_LIST . '?code=FMT_NON_ASCII')->json('data.0.id'))->toBe($fa->id);
    expect($this->getJson(FV_LIST . '?code=FMT_NON_ASCII')->json('data'))->toHaveCount(1);
});

test('LIKE wildcards in the email search are matched literally', function () {
    fvFailure(fvStudent('someone@example.com'));
    fvAdmin();

    expect($this->getJson(FV_LIST . '?email=' . urlencode('%%%'))->json('data'))->toHaveCount(0);
    expect($this->getJson(FV_LIST . '?email=' . urlencode('___'))->json('data'))->toHaveCount(0);
});

test('rows outside the requested window are excluded and bad parameters are rejected', function () {
    $student = fvStudent();
    fvFailure($student, '555555', [], now()->subDays(45));
    fvAdmin();

    expect($this->getJson(FV_LIST . '?days=30')->json('data'))->toHaveCount(0);
    expect($this->getJson(FV_LIST . '?days=60')->json('data'))->toHaveCount(1);

    $this->getJson(FV_LIST . '?days=0')->assertStatus(422);
    $this->getJson(FV_LIST . '?days=91')->assertStatus(422);
    $this->getJson(FV_LIST . '?per_page=51')->assertStatus(422);
    $this->getJson(FV_LIST . '?email=ab')->assertStatus(422);
    $this->getJson(FV_LIST . '?code=' . urlencode('x; DROP'))->assertStatus(422);
    $this->getJson(FV_LIST . '?reason=NOPE')->assertStatus(422);
});

test('the list is paginated', function () {
    $student = fvStudent();
    foreach (range(1, 5) as $i) {
        fvFailure($student, (string) (700000 + $i));
    }
    fvAdmin();

    $res = $this->getJson(FV_LIST . '?per_page=2')->assertOk();
    expect($res->json('data'))->toHaveCount(2)
        ->and($res->json('meta.total'))->toBe(5)
        ->and($res->json('meta.last_page'))->toBe(3);
});

// ---------------------------------------------------------------- detail

test('detail returns attempts, snapshot, catalogued codes and cross-link counts, and is audited', function () {
    $this->seed(\Database\Seeders\FailureReasonCodeSeeder::class);

    $student = fvStudent('detail.student@example.com');
    $failed  = fvFailure($student, '424242');
    fvEnrich($student, $failed, ['FMT_MISSING_MIDDLE']);
    fvFailure($student, '424243'); // another failure this week

    SecurityEvent::create([
        'event_type' => SecurityEvent::EVENT_TYPE_PROVISIONING_FAILED,
        'reason' => 'ogos_unreachable', 'email' => 'detail.student@example.com',
        'metadata' => ['system' => 'ogos', 'http_status' => 503],
    ]);

    $admin = fvAdmin();
    $res   = $this->getJson(FV_LIST . '/' . $failed->id)->assertOk();

    expect($res->json('data.or_number'))->toBe('424242')
        ->and($res->json('data.attempts'))->toHaveCount(2)
        ->and($res->json('data.attempts.0.name'))->toBe('DELA CRUZ, JUAN S.')
        ->and($res->json('data.on_file_snapshot.last_name'))->toBe('DELA CRUZ')
        ->and($res->json('data.diagnosis.0.code'))->toBe('FMT_MISSING_MIDDLE')
        ->and($res->json('data.diagnosis.0.description'))->not->toBeEmpty()
        ->and($res->json('data.cross_links.other_failed_verifications'))->toBe(1)
        ->and($res->json('data.cross_links.provisioning_failures'))->toBe(1)
        ->and($res->json('data.note'))->toContain('not a fault verdict');

    // Exactly one access record, attributed to the viewer, metadata only.
    $log = AuditLog::where('action', AuditLog::ACTION_FAILED_VERIFICATION_VIEWED)->sole();
    expect($log->user_id)->toBe($admin->user_id)
        ->and($log->metadata['source_audit_log_id'])->toBe($failed->id)
        ->and(json_encode($log->metadata))->not->toContain('424242')
        ->and(json_encode($log->metadata))->not->toContain('DELA CRUZ');
});

test('detail is a 404 for an approved attempt or a row of another action', function () {
    $student  = fvStudent();
    $approved = fvAudit($student, AuditLog::ACTION_CASHIER_VERIFICATION, ['or_number' => '1', 'final_approved' => true]);
    $login    = fvAudit($student, AuditLog::ACTION_LOGIN, ['final_approved' => false]);

    fvAdmin();
    $this->getJson(FV_LIST . '/' . $approved->id)->assertNotFound();
    $this->getJson(FV_LIST . '/' . $login->id)->assertNotFound();
    $this->getJson(FV_LIST . '/999999')->assertNotFound();

    expect(AuditLog::where('action', AuditLog::ACTION_FAILED_VERIFICATION_VIEWED)->count())->toBe(0);
});

test('detail fails closed when the access record cannot be written', function () {
    $failed = fvFailure(fvStudent());
    fvAdmin();

    $logger = Mockery::mock(AuditLogger::class);
    $logger->shouldReceive('log')->andThrow(new RuntimeException('audit down'));
    $this->instance(AuditLogger::class, $logger);

    $res = $this->getJson(FV_LIST . '/' . $failed->id);

    expect($res->status())->toBe(500)
        ->and($res->getContent())->not->toContain('424242')
        ->and($res->getContent())->not->toContain('123456');
});

// ---------------------------------------------------------------- recheck

function fvFakeCashier(array $result): \Mockery\MockInterface
{
    $mock = Mockery::mock(CashierServiceInterface::class);
    $mock->shouldReceive('verifyPaymentAny')->andReturn($result);
    app()->instance(CashierServiceInterface::class, $mock);

    return $mock;
}

test('recheck regenerates candidates from the stored profile, never approves, and is audited', function () {
    config(['services.cashier.api_key' => 'k']);
    $student = fvStudent();
    $failed  = fvFailure($student, '616161');

    $mock = Mockery::mock(CashierServiceInterface::class);
    $mock->shouldReceive('verifyPaymentAny')
        ->once()
        ->withArgs(function (string $or, array $names) {
            // OR comes from the stored row; names from the stored profile.
            return $or === '616161' && $names !== [] && str_contains(strtoupper($names[0]), 'DELA CRUZ');
        })
        ->andReturn([
            'valid' => true, 'reason' => null, 'data' => [], 'matched_name' => 'DELA CRUZ, JUAN S.',
            'attempts' => [['name' => 'DELA CRUZ, JUAN S.', 'valid' => true, 'reason' => null]],
        ]);
    app()->instance(CashierServiceInterface::class, $mock);

    $admin = fvAdmin();
    $res   = $this->postJson(FV_LIST . '/' . $failed->id . '/recheck')->assertOk();

    expect($res->json('data.outcome'))->toBe('matched')
        ->and($res->json('data.approved'))->toBeFalse()
        ->and($res->json('data.matched_name'))->toBe('DELA CRUZ, JUAN S.');

    $log = AuditLog::where('action', AuditLog::ACTION_FAILED_VERIFICATION_RECHECKED)->sole();
    expect($log->user_id)->toBe($admin->user_id)
        ->and($log->metadata['outcome'])->toBe('matched')
        ->and(json_encode($log->metadata))->not->toContain('616161')
        ->and(json_encode($log->metadata))->not->toContain('DELA CRUZ');

    // Nothing else changed: the original failure row is untouched, no new verification row.
    expect(AuditLog::where('action', AuditLog::ACTION_CASHIER_VERIFICATION)->count())->toBe(1)
        ->and(AuditLog::find($failed->id)->metadata['final_approved'])->toBeFalse();
});

test('recheck maps NOT_FOUND and API_ERROR outcomes', function () {
    config(['services.cashier.api_key' => 'k']);
    $student = fvStudent();
    $a = fvFailure($student, '1');
    $b = fvFailure($student, '2');
    fvAdmin();

    fvFakeCashier(['valid' => false, 'reason' => 'NOT_FOUND', 'data' => null, 'matched_name' => null, 'attempts' => []]);
    expect($this->postJson(FV_LIST . '/' . $a->id . '/recheck')->json('data.outcome'))->toBe('not_found');

    fvFakeCashier(['valid' => false, 'reason' => 'API_ERROR', 'data' => null, 'matched_name' => null, 'attempts' => []]);
    expect($this->postJson(FV_LIST . '/' . $b->id . '/recheck')->json('data.outcome'))->toBe('api_error');
});

test('recheck is unavailable in mock mode and makes no lookup', function () {
    config(['services.cashier.api_key' => '']);
    $failed = fvFailure(fvStudent());
    fvAdmin();

    $mock = Mockery::mock(CashierServiceInterface::class);
    $mock->shouldNotReceive('verifyPaymentAny');
    app()->instance(CashierServiceInterface::class, $mock);

    $this->postJson(FV_LIST . '/' . $failed->id . '/recheck')->assertStatus(409);
    expect(AuditLog::where('action', AuditLog::ACTION_FAILED_VERIFICATION_RECHECKED)->count())->toBe(0);
});

test('recheck only accepts an existing failed row and refuses free-text input', function () {
    config(['services.cashier.api_key' => '']); // irrelevant: the row check comes first
    $student  = fvStudent();
    $approved = fvAudit($student, AuditLog::ACTION_CASHIER_VERIFICATION, ['or_number' => '9', 'final_approved' => true]);
    fvAdmin();

    $mock = Mockery::mock(CashierServiceInterface::class);
    $mock->shouldNotReceive('verifyPaymentAny');
    app()->instance(CashierServiceInterface::class, $mock);

    $this->postJson(FV_LIST . '/' . $approved->id . '/recheck')->assertNotFound();
    $this->postJson(FV_LIST . '/999999/recheck')->assertNotFound();
    // There is no route that takes an OR number or a name.
    $this->postJson(FV_LIST . '/recheck', ['or_number' => '123456', 'name' => 'X'])->assertStatus(404);
});

test('recheck is refused when the account or profile no longer exists', function () {
    config(['services.cashier.api_key' => 'k']);
    $student = fvStudent();
    $failed  = fvFailure($student);
    DB::table('student_profile')->where('user_id', $student->user_id)->delete();
    fvAdmin();

    $mock = Mockery::mock(CashierServiceInterface::class);
    $mock->shouldNotReceive('verifyPaymentAny');
    app()->instance(CashierServiceInterface::class, $mock);

    $this->postJson(FV_LIST . '/' . $failed->id . '/recheck')->assertStatus(422);
});

test('a second recheck of the same row inside the cooldown is refused', function () {
    config(['services.cashier.api_key' => 'k']);
    $failed = fvFailure(fvStudent());
    fvAdmin();

    $mock = Mockery::mock(CashierServiceInterface::class);
    $mock->shouldReceive('verifyPaymentAny')->once()->andReturn([
        'valid' => false, 'reason' => 'NOT_FOUND', 'data' => null, 'matched_name' => null, 'attempts' => [],
    ]);
    app()->instance(CashierServiceInterface::class, $mock);

    $this->postJson(FV_LIST . '/' . $failed->id . '/recheck')->assertOk();
    $this->postJson(FV_LIST . '/' . $failed->id . '/recheck')->assertStatus(429);

    expect(AuditLog::where('action', AuditLog::ACTION_FAILED_VERIFICATION_RECHECKED)->count())->toBe(1);
});

test('the audit_logs action index migration is idempotent and reversible', function () {
    $migration = require database_path('migrations/2026_10_09_000000_add_action_created_index_to_audit_logs.php');

    $migration->up();
    $migration->up(); // second run is a no-op
    expect(\Illuminate\Support\Facades\Schema::hasIndex('audit_logs', 'idx_audit_logs_action_created'))->toBeTrue();

    $migration->down();
    expect(\Illuminate\Support\Facades\Schema::hasIndex('audit_logs', 'idx_audit_logs_action_created'))->toBeFalse();

    $migration->up(); // leave the schema as the suite expects
});
