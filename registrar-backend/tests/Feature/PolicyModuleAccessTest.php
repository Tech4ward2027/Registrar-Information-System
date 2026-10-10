<?php

use App\Models\Policy;
use App\Models\SystemUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// ── Helpers ───────────────────────────────────────────────────────────────────

function makeAdmin(?int $policyId = null): SystemUser
{
    $admin = SystemUser::factory()->create([
        'role_id'   => SystemUser::ROLE_ADMIN,
        'status'    => 'Activated',
        'policy_id' => $policyId,
    ]);
    Sanctum::actingAs($admin);

    return $admin;
}

function seedStudentStaffAndRegistrarPolicies(): array
{
    // The create_policies_table migration already seeds "Student Staff"
    // and "Registrar Staff" as is_system rows (RefreshDatabase runs
    // migrations once, so they exist before any test body runs). Use
    // updateOrCreate rather than create() so this helper works regardless
    // of that pre-seeded state, and so this fixture's intentionally
    // different "Registrar Staff" permissions (full access, for exercising
    // the happy path) win over the migration's more restrictive defaults.
    //
    // Work Item #1 — Granular Per-Action Permissions: dashboard/logbook
    // now use their granular action arrays (Policy::MODULE_ACTIONS)
    // rather than the legacy single-token ['Access'] shape. Student
    // Staff gets View + Complete on dashboard (can see the queue and
    // mark a request Done, matching its real-world seed in
    // DatabaseSeeder::seedPolicies()) but not Process, so it stays
    // useful for exercising the fine-grained 403 boundary alongside
    // GranularDashboardPermissionsTest. Registrar Staff keeps full
    // access on every module for the "happy path" tests below.
    $studentStaff = Policy::updateOrCreate(
        ['name' => 'Student Staff'],
        [
            'permissions' => [
                'dashboard' => ['View', 'Complete'],
                'inbox'     => ['Access'],
                'analytics' => [],
                'logbook'   => [],
                'profile'   => [],
            ],
            'is_system' => true,
        ]
    );

    $registrarStaff = Policy::updateOrCreate(
        ['name' => 'Registrar Staff'],
        [
            'permissions' => [
                'dashboard' => ['View', 'Process', 'Complete'],
                'inbox'     => ['Access'],
                'analytics' => ['Access'],
                'logbook'   => ['View', 'Export'],
                'profile'   => ['Access'],
            ],
            'is_system' => true,
        ]
    );

    return compact('studentStaff', 'registrarStaff');
}

function seedZeroAccessDefaultPolicy(): Policy
{
    // Policy::DEFAULT_NAME ("No Access") is seeded by the
    // 2026_08_03_000005_seed_zero_access_default_policy migration, which
    // already runs as part of RefreshDatabase. updateOrCreate here just
    // makes this fixture explicit and self-contained rather than relying
    // on migration timing, matching the pattern above.
    return Policy::updateOrCreate(
        ['name' => Policy::DEFAULT_NAME],
        [
            'permissions' => [
                'dashboard' => [],
                'inbox'     => [],
                'analytics' => [],
                'logbook'   => [],
                'profile'   => [],
            ],
            'is_system' => true,
        ]
    );
}

// ═════════════════════════════════════════════════════════════════════════════
// UNIT — SystemUser::hasModuleAccess() / effectivePermissions()
// ═════════════════════════════════════════════════════════════════════════════

test('super admin has access to every module regardless of policy', function () {
    $superAdmin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN]);

    foreach (Policy::MODULE_KEYS as $module) {
        expect($superAdmin->hasModuleAccess($module))->toBeTrue();
    }
});

test('students and alumni are never gated by the policy system', function () {
    $student = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT]);
    $alumni  = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ALUMNI]);

    expect($student->hasModuleAccess('analytics'))->toBeTrue()
        ->and($alumni->hasModuleAccess('logbook'))->toBeTrue();
});

test('admin with Student Staff policy only has dashboard and inbox', function () {
    ['studentStaff' => $studentStaff] = seedStudentStaffAndRegistrarPolicies();
    $admin = makeAdmin($studentStaff->policy_id);

    expect($admin->hasModuleAccess('dashboard'))->toBeTrue()
        ->and($admin->hasModuleAccess('inbox'))->toBeTrue()
        ->and($admin->hasModuleAccess('analytics'))->toBeFalse()
        ->and($admin->hasModuleAccess('logbook'))->toBeFalse()
        ->and($admin->hasModuleAccess('profile'))->toBeFalse();
});

test('admin with Student Staff policy has View and Complete on dashboard but not Process', function () {
    // This file's own coarse-gate coverage above ("any dashboard
    // access") would have kept passing even against the legacy
    // ['Access'] fixture shape, which is exactly how it went stale
    // unnoticed. Assert the specific granular tokens directly so this
    // file can't silently drift out of sync with Policy::MODULE_ACTIONS
    // again. The full 403-at-the-route-level version of this lives in
    // GranularDashboardPermissionsTest.
    ['studentStaff' => $studentStaff] = seedStudentStaffAndRegistrarPolicies();
    $admin = makeAdmin($studentStaff->policy_id);

    expect($admin->hasModuleAccess('dashboard', 'View'))->toBeTrue()
        ->and($admin->hasModuleAccess('dashboard', 'Complete'))->toBeTrue()
        ->and($admin->hasModuleAccess('dashboard', 'Process'))->toBeFalse();
});

test('admin with no policy_id falls back to the zero-access default, never an access-granting one', function () {
    // Also seed Registrar Staff/Student Staff (both grant real access) to
    // prove the fallback resolves by Policy::DEFAULT_NAME specifically,
    // not "whatever is_system policy happens to exist first".
    seedStudentStaffAndRegistrarPolicies();
    seedZeroAccessDefaultPolicy();

    $admin = makeAdmin(null);

    foreach (Policy::MODULE_KEYS as $module) {
        expect($admin->hasModuleAccess($module))->toBeFalse();
    }
});

test('an admin created without an explicit policy never inherits Registrar Staff access', function () {
    // Regression test for the historical bug: DEFAULT_NAME used to be
    // 'Registrar Staff', so any admin with no policy_id silently got
    // Analytics + Logbook access instead of nothing. Guard against this
    // ever regressing back, independent of whatever DEFAULT_NAME's exact
    // string value is.
    seedStudentStaffAndRegistrarPolicies();
    seedZeroAccessDefaultPolicy();

    expect(Policy::DEFAULT_NAME)->not->toBe('Registrar Staff');

    $admin = makeAdmin(null);

    expect($admin->hasModuleAccess('analytics'))->toBeFalse()
        ->and($admin->hasModuleAccess('logbook'))->toBeFalse();
});

test('admin falls back to deny when even the default policy is missing', function () {
    // The default-policy row (Policy::DEFAULT_NAME, "No Access") is
    // normally seeded by the 2026_08_03_000005 migration, and
    // RefreshDatabase only migrates once — so that row exists by default
    // in every test in this run. To actually exercise "the default
    // policy is missing", delete it explicitly rather than relying on a
    // blank slate.
    Policy::where('name', Policy::DEFAULT_NAME)->delete();

    $admin = makeAdmin(null);

    expect($admin->hasModuleAccess('analytics'))->toBeFalse();
});

test('unknown module keys are always denied, never accidentally granted', function () {
    ['registrarStaff' => $registrarStaff] = seedStudentStaffAndRegistrarPolicies();
    $admin = makeAdmin($registrarStaff->policy_id);

    expect($admin->hasModuleAccess('billing'))->toBeFalse();
});

// ═════════════════════════════════════════════════════════════════════════════
// FEATURE — EnsureModuleAccess middleware on real routes
// ═════════════════════════════════════════════════════════════════════════════

test('Student Staff admin gets 403 from analytics endpoints', function () {
    ['studentStaff' => $studentStaff] = seedStudentStaffAndRegistrarPolicies();
    makeAdmin($studentStaff->policy_id);

    $this->getJson('/api/analytics/overview')->assertStatus(403);
});

test('Student Staff admin gets 403 from the logbook endpoint', function () {
    ['studentStaff' => $studentStaff] = seedStudentStaffAndRegistrarPolicies();
    makeAdmin($studentStaff->policy_id);

    $this->getJson('/api/document-requests/logbook')->assertStatus(403);
});

test('Registrar Staff admin can reach analytics and logbook', function () {
    ['registrarStaff' => $registrarStaff] = seedStudentStaffAndRegistrarPolicies();
    makeAdmin($registrarStaff->policy_id);

    $this->getJson('/api/analytics/overview')->assertStatus(200);
    $this->getJson('/api/document-requests/logbook')->assertStatus(200);
});

test('super admin reaches analytics even with no policy row in the system at all', function () {
    $superAdmin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN]);
    Sanctum::actingAs($superAdmin);

    $this->getJson('/api/analytics/overview')->assertStatus(200);
});

// ═════════════════════════════════════════════════════════════════════════════
// PolicyService — permission sanitization
// ═════════════════════════════════════════════════════════════════════════════

test('creating a policy silently drops unknown module keys', function () {
    $superAdmin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN]);
    Sanctum::actingAs($superAdmin);

    $response = $this->postJson('/api/policies', [
        'name'        => 'Custom Policy',
        'permissions' => [
            // 'dashboard' is a granular module (Policy::MODULE_ACTIONS),
            // so 'View' — not the legacy 'Access' — is the valid token
            // here; see the sanitization test below for what happens to
            // an invalid/stray token on a granular module.
            'dashboard' => ['View'],
            'billing'   => ['Access'], // not a real module
        ],
    ]);

    $response->assertStatus(201);
    $stored = Policy::where('name', 'Custom Policy')->first();
    expect($stored->permissions)->toHaveKey('dashboard')
        ->and($stored->permissions['dashboard'])->toBe(['View'])
        ->and($stored->permissions)->not->toHaveKey('billing');
});

test('creating a policy drops action tokens the module does not recognize', function () {
    // 'profile' has no entry in Policy::MODULE_ACTIONS, so its only
    // valid token is the legacy single-token 'Access' — a stray
    // granular-style token typed in by hand should be silently dropped,
    // same as an unknown module key is above.
    $superAdmin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN]);
    Sanctum::actingAs($superAdmin);

    $response = $this->postJson('/api/policies', [
        'name'        => 'Custom Policy 2',
        'permissions' => [
            'profile' => ['Process'], // not a valid token for 'profile'
        ],
    ]);

    $response->assertStatus(201);
    $stored = Policy::where('name', 'Custom Policy 2')->first();
    expect($stored->permissions['profile'])->toBe([]);
});

test('granting Process or Complete on dashboard without View backfills View', function () {
    // Regression test for the raw-API bypass of the frontend's
    // "Process/Complete implies View" checkbox behavior — see
    // PolicyService::sanitizePermissions()'s View-dependency guard.
    $superAdmin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN]);
    Sanctum::actingAs($superAdmin);

    $response = $this->postJson('/api/policies', [
        'name'        => 'Process Without View',
        'permissions' => [
            'dashboard' => ['Process'], // View deliberately omitted
        ],
    ]);

    $response->assertStatus(201);
    $stored = Policy::where('name', 'Process Without View')->first();
    expect($stored->permissions['dashboard'])->toContain('View')
        ->and($stored->permissions['dashboard'])->toContain('Process');
});

test('an empty dashboard grant stays empty rather than backfilling View', function () {
    // The View-dependency guard only fires when at least one OTHER
    // action is granted — it must not turn "no dashboard access" into
    // "View-only access" as a side effect.
    $superAdmin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN]);
    Sanctum::actingAs($superAdmin);

    $response = $this->postJson('/api/policies', [
        'name'        => 'No Dashboard Access',
        'permissions' => [
            'dashboard' => [],
        ],
    ]);

    $response->assertStatus(201);
    $stored = Policy::where('name', 'No Dashboard Access')->first();
    expect($stored->permissions['dashboard'])->toBe([]);
});

test('Student Staff admin gets 403 from document-requests counts without dashboard View', function () {
    // Regression test for the pre-existing gap: GET .../counts had no
    // module gate at all before, so an admin with zero dashboard access
    // could still see per-status counts for the same queue index/show
    // now require View for.
    $noAccess = Policy::create([
        'name'        => 'Zero Dashboard Access',
        'permissions' => ['dashboard' => []],
        'is_system'   => false,
    ]);
    makeAdmin($noAccess->policy_id);

    $this->getJson('/api/document-requests/counts')->assertStatus(403);
});

test('Registrar Staff admin can reach document-requests counts', function () {
    ['registrarStaff' => $registrarStaff] = seedStudentStaffAndRegistrarPolicies();
    makeAdmin($registrarStaff->policy_id);

    $this->getJson('/api/document-requests/counts')->assertStatus(200);
});

// ═════════════════════════════════════════════════════════════════════════════
// Cashier Reconciliation module (Phase 1)
// ═════════════════════════════════════════════════════════════════════════════

test('cashier_reconciliation is a registered single-token policy module', function () {
    expect(Policy::MODULE_KEYS)->toContain('cashier_reconciliation')
        ->and(Policy::actionsFor('cashier_reconciliation'))->toBe(['Access']);
});

test('admin with cashier_reconciliation Access passes the module check and one without does not', function () {
    $granted = Policy::create([
        'name' => 'Recon Granted', 'permissions' => ['cashier_reconciliation' => ['Access']], 'is_system' => false,
    ]);
    $denied = Policy::create([
        'name' => 'Recon Denied', 'permissions' => ['cashier_reconciliation' => []], 'is_system' => false,
    ]);

    $a = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN, 'status' => 'Activated', 'policy_id' => $granted->policy_id]);
    $b = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN, 'status' => 'Activated', 'policy_id' => $denied->policy_id]);

    expect($a->hasModuleAccess('cashier_reconciliation'))->toBeTrue()
        ->and($b->hasModuleAccess('cashier_reconciliation'))->toBeFalse();
});

test('a super admin can save a policy that grants cashier_reconciliation', function () {
    $superAdmin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN]);
    Sanctum::actingAs($superAdmin);

    $this->postJson('/api/policies', [
        'name'        => 'Reconciliation Only',
        'permissions' => ['cashier_reconciliation' => ['Access']],
    ])->assertStatus(201);

    expect(Policy::where('name', 'Reconciliation Only')->first()->permissions['cashier_reconciliation'])
        ->toBe(['Access']);
});

function runReconciliationBackfill(string $direction = 'up'): void
{
    $migration = require database_path('migrations/2026_10_05_000001_backfill_cashier_reconciliation_policy_access.php');
    $migration->{$direction}();
}

test('backfill grants cashier_reconciliation to existing policies but never to the zero-access default', function () {
    $legacy = Policy::create([
        'name' => 'Legacy Custom', 'permissions' => ['dashboard' => ['View']], 'is_system' => false,
    ]);
    $zero = seedZeroAccessDefaultPolicy();
    $zero->update(['permissions' => ['dashboard' => []]]);

    runReconciliationBackfill();

    expect($legacy->fresh()->permissions['cashier_reconciliation'])->toBe(['Access'])
        ->and($legacy->fresh()->permissions['dashboard'])->toBe(['View'])
        ->and($zero->fresh()->permissions)->not->toHaveKey('cashier_reconciliation');
});

test('backfill is idempotent and never overwrites a deliberately narrowed grant', function () {
    $narrowed = Policy::create([
        'name' => 'Narrowed', 'permissions' => ['cashier_reconciliation' => []], 'is_system' => false,
    ]);

    runReconciliationBackfill();
    runReconciliationBackfill();

    expect($narrowed->fresh()->permissions['cashier_reconciliation'])->toBe([]);
});

test('backfill rollback removes the key from every policy', function () {
    $policy = Policy::create([
        'name' => 'To Roll Back', 'permissions' => ['cashier_reconciliation' => ['Access'], 'inbox' => ['Access']], 'is_system' => false,
    ]);

    runReconciliationBackfill('down');

    expect($policy->fresh()->permissions)->not->toHaveKey('cashier_reconciliation')
        ->and($policy->fresh()->permissions['inbox'])->toBe(['Access']);
});

test('the seeded Registrar Staff policy keeps access to reconciliation after the backfill', function () {
    runReconciliationBackfill();

    $registrar = Policy::where('name', 'Registrar Staff')->first();
    expect($registrar)->not->toBeNull()
        ->and($registrar->permissions['cashier_reconciliation'] ?? null)->toBe(['Access']);
});