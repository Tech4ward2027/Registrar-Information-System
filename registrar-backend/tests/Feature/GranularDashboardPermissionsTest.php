<?php

use App\Enums\RequestStatusEnum;
use App\Models\DocumentRequest;
use App\Models\Policy;
use App\Models\RequestStatus;
use App\Models\SystemUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// ═════════════════════════════════════════════════════════════════════════════
// Work Item #1 — Granular Per-Action Permissions: Definition-of-Done coverage.
//
// Every other Work Item #1 test file (PolicyModuleAccessTest, RisFeatureTest,
// DocumentRequestClaimTest, etc.) exercises the COARSE "does this admin have
// any access to this module at all" gate. None of them drive a real HTTP
// call through the FINE, target-status-dependent gate that's the actual
// point of this work item — DocumentRequestService::authorizeStatusChange().
// This file closes that gap, matching the Definition of Done's explicit
// "verified via direct API call not just hidden UI" / "even if sent
// manually" requirements: every restriction below is asserted against a
// real PUT/POST call, never against hasModuleAccess() in isolation.
// ═════════════════════════════════════════════════════════════════════════════

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * An admin whose attached policy grants the Student Staff shape spec'd in
 * Work Item #1: dashboard => [View, Complete] (no Process), logbook =>
 * [View] (no Export). Deliberately built from the literal target shape
 * rather than reading the seeded "Student Staff" system policy, so this
 * test keeps failing loudly if that seed ever drifts from the spec.
 */
function makeStudentStaffAdmin(): SystemUser
{
    $policy = Policy::firstOrCreate(
        ['name' => 'Test Student Staff (WI#1)'],
        [
            'permissions' => [
                'dashboard' => ['View', 'Complete'],
                'logbook'   => ['View'],
                'inbox'     => ['Access'],
            ],
            'is_system' => false,
        ]
    );

    $admin = SystemUser::factory()->create([
        'role_id'   => SystemUser::ROLE_ADMIN,
        'status'    => 'Activated',
        'policy_id' => $policy->policy_id,
    ]);

    Sanctum::actingAs($admin);

    return $admin;
}

/**
 * An admin whose attached policy grants the full Registrar Staff shape:
 * dashboard => [View, Process, Complete], logbook => [View, Export]. Used
 * as the "nothing was taken away" control group required by the DoD.
 */
function makeRegistrarStaffAdmin(): SystemUser
{
    $policy = Policy::firstOrCreate(
        ['name' => 'Test Registrar Staff (WI#1)'],
        [
            'permissions' => [
                'dashboard' => ['View', 'Process', 'Complete'],
                'logbook'   => ['View', 'Export'],
                'inbox'     => ['Access'],
            ],
            'is_system' => false,
        ]
    );

    $admin = SystemUser::factory()->create([
        'role_id'   => SystemUser::ROLE_ADMIN,
        'status'    => 'Activated',
        'policy_id' => $policy->policy_id,
    ]);

    Sanctum::actingAs($admin);

    return $admin;
}

function granularSeedStatuses(): void
{
    foreach ([1 => 'Processing', 2 => 'Ready to Claim', 3 => 'Completed', 4 => 'Forfeited', 6 => 'Pending Signature'] as $id => $name) {
        RequestStatus::firstOrCreate(['status_id' => $id], ['status_name' => $name]);
    }
}

/**
 * A plain (non-certificate) document request in the given status. Plain
 * requests skip the "certificate must be generated" pre-check inside
 * updateRequest(), so a status-transition test only ever fails for the
 * reason it's meant to test.
 */
function makeGranularDocRequest(RequestStatusEnum $status = RequestStatusEnum::Processing): DocumentRequest
{
    return DocumentRequest::factory()->create(['status_id' => $status->value]);
}

// ═════════════════════════════════════════════════════════════════════════════
// Student Staff — allowed: View the queue, mark Done (Complete)
// ═════════════════════════════════════════════════════════════════════════════

test('Student Staff can list the dashboard queue', function () {
    granularSeedStatuses();
    makeStudentStaffAdmin();

    $this->getJson('/api/v1/document-requests')->assertOk();
});

test('Student Staff can view a single document request', function () {
    granularSeedStatuses();
    $docReq = makeGranularDocRequest();
    makeStudentStaffAdmin();

    $this->getJson("/api/v1/document-requests/{$docReq->request_id}")
         ->assertOk()
         ->assertJsonPath('request_id', $docReq->request_id);
});

test('Student Staff can mark a Ready-to-Claim request Completed (Done)', function () {
    granularSeedStatuses();
    $docReq = makeGranularDocRequest(RequestStatusEnum::ReadyToClaim);
    makeStudentStaffAdmin();

    $this->putJson("/api/v1/document-requests/{$docReq->request_id}", [
        'status_id' => RequestStatusEnum::Completed->value,
    ])->assertOk()
      ->assertJsonPath('status.status_id', RequestStatusEnum::Completed->value);

    $this->assertDatabaseHas('document_request', [
        'request_id' => $docReq->request_id,
        'status_id'  => RequestStatusEnum::Completed->value,
    ]);
});

test('Student Staff can complete a request through the claim endpoint (QR/claim_code)', function () {
    granularSeedStatuses();
    $docReq = makeGranularDocRequest(RequestStatusEnum::ReadyToClaim);
    makeStudentStaffAdmin();

    $this->postJson('/api/v1/document-requests/claim', ['uuid' => $docReq->uuid])
         ->assertOk()
         ->assertJsonPath('status.status_id', RequestStatusEnum::Completed->value);
});

// ═════════════════════════════════════════════════════════════════════════════
// Student Staff — forbidden: Process actions (Ready / Awaiting-Signature /
// Forfeited), even via a hand-crafted PUT that never touches the UI.
// This is the core Definition-of-Done assertion for this work item.
// ═════════════════════════════════════════════════════════════════════════════

test('Student Staff cannot set status_id to Ready-to-Claim via a direct PUT', function () {
    granularSeedStatuses();
    $docReq = makeGranularDocRequest(RequestStatusEnum::Processing);
    makeStudentStaffAdmin();

    $this->putJson("/api/v1/document-requests/{$docReq->request_id}", [
        'status_id' => RequestStatusEnum::ReadyToClaim->value,
    ])->assertStatus(403);

    $this->assertDatabaseHas('document_request', [
        'request_id' => $docReq->request_id,
        'status_id'  => RequestStatusEnum::Processing->value, // unchanged
    ]);
});

test('Student Staff cannot set status_id to Pending Signature via a direct PUT', function () {
    granularSeedStatuses();
    $docReq = makeGranularDocRequest(RequestStatusEnum::Processing);
    makeStudentStaffAdmin();

    $this->putJson("/api/v1/document-requests/{$docReq->request_id}", [
        'status_id' => RequestStatusEnum::PendingSignature->value,
    ])->assertStatus(403);
});

test('Student Staff cannot forfeit a Ready-to-Claim request via a direct PUT', function () {
    granularSeedStatuses();
    $docReq = makeGranularDocRequest(RequestStatusEnum::ReadyToClaim);
    makeStudentStaffAdmin();

    $this->putJson("/api/v1/document-requests/{$docReq->request_id}", [
        'status_id' => RequestStatusEnum::Forfeited->value,
    ])->assertStatus(403);
});

test('Student Staff cannot edit or_number via a direct PUT, even without a status change', function () {
    granularSeedStatuses();
    $docReq = makeGranularDocRequest(RequestStatusEnum::Processing);
    makeStudentStaffAdmin();

    $this->putJson("/api/v1/document-requests/{$docReq->request_id}", [
        'or_number' => 'OR-HAND-CRAFTED-1',
    ])->assertStatus(403);

    $this->assertDatabaseHas('document_request', [
        'request_id' => $docReq->request_id,
        'or_number'  => $docReq->or_number, // unchanged
    ]);
});

test('Student Staff cannot edit receipt_date via a direct PUT', function () {
    granularSeedStatuses();
    $docReq = makeGranularDocRequest(RequestStatusEnum::Processing);
    makeStudentStaffAdmin();

    $this->putJson("/api/v1/document-requests/{$docReq->request_id}", [
        'receipt_date' => now()->toDateString(),
    ])->assertStatus(403);
});

test('Student Staff cannot smuggle an or_number edit alongside an otherwise-allowed Complete', function () {
    // Regression guard for the "accumulate, don't OR" rule in
    // requiredDashboardActions(): completing AND editing or_number in the
    // same call requires BOTH Complete and Process. Student Staff has
    // Complete but not Process, so the combined call must still be
    // rejected outright — not partially applied.
    granularSeedStatuses();
    $docReq = makeGranularDocRequest(RequestStatusEnum::ReadyToClaim);
    makeStudentStaffAdmin();

    $this->putJson("/api/v1/document-requests/{$docReq->request_id}", [
        'status_id' => RequestStatusEnum::Completed->value,
        'or_number' => 'OR-SMUGGLED-1',
    ])->assertStatus(403);

    $this->assertDatabaseHas('document_request', [
        'request_id' => $docReq->request_id,
        'status_id'  => RequestStatusEnum::ReadyToClaim->value, // unchanged
        'or_number'  => $docReq->or_number,                     // unchanged
    ]);
});

// ═════════════════════════════════════════════════════════════════════════════
// Registrar Staff (full) — control group: nothing was taken away
// ═════════════════════════════════════════════════════════════════════════════

test('Registrar Staff can set status_id to Ready-to-Claim via a direct PUT', function () {
    granularSeedStatuses();
    $docReq = makeGranularDocRequest(RequestStatusEnum::Processing);
    makeRegistrarStaffAdmin();

    $this->putJson("/api/v1/document-requests/{$docReq->request_id}", [
        'status_id' => RequestStatusEnum::ReadyToClaim->value,
    ])->assertOk()
      ->assertJsonPath('status.status_id', RequestStatusEnum::ReadyToClaim->value);
});

test('Registrar Staff can set status_id to Pending Signature via a direct PUT', function () {
    granularSeedStatuses();
    $docReq = makeGranularDocRequest(RequestStatusEnum::Processing);
    makeRegistrarStaffAdmin();

    $this->putJson("/api/v1/document-requests/{$docReq->request_id}", [
        'status_id' => RequestStatusEnum::PendingSignature->value,
    ])->assertOk();
});

test('Registrar Staff can mark a request Completed', function () {
    granularSeedStatuses();
    $docReq = makeGranularDocRequest(RequestStatusEnum::ReadyToClaim);
    makeRegistrarStaffAdmin();

    $this->putJson("/api/v1/document-requests/{$docReq->request_id}", [
        'status_id' => RequestStatusEnum::Completed->value,
    ])->assertOk();
});

test('Registrar Staff can edit or_number and receipt_date via a direct PUT', function () {
    granularSeedStatuses();
    $docReq = makeGranularDocRequest(RequestStatusEnum::Processing);
    makeRegistrarStaffAdmin();

    $this->putJson("/api/v1/document-requests/{$docReq->request_id}", [
        'or_number'    => 'OR-REGISTRAR-EDIT-1',
        'receipt_date' => now()->toDateString(),
    ])->assertOk();

    $this->assertDatabaseHas('document_request', [
        'request_id' => $docReq->request_id,
        'or_number'  => 'OR-REGISTRAR-EDIT-1',
    ]);
});

test('Registrar Staff can complete a request through the claim endpoint', function () {
    granularSeedStatuses();
    $docReq = makeGranularDocRequest(RequestStatusEnum::ReadyToClaim);
    makeRegistrarStaffAdmin();

    $this->postJson('/api/v1/document-requests/claim', ['uuid' => $docReq->uuid])
         ->assertOk();
});

// ═════════════════════════════════════════════════════════════════════════════
// Logbook — soft/UI-only Export gate. View is a hard boundary (route-level
// module:logbook check); Export has no server-side counterpart to test
// against by design (see routes/api.php's doc comment on the logbook
// route) — the export document is generated client-side from data the
// browser already holds once the logbook is viewable at all. This test
// only asserts the hard boundary that does exist: View.
// ═════════════════════════════════════════════════════════════════════════════

test('Student Staff can view the logbook endpoint', function () {
    granularSeedStatuses();
    makeStudentStaffAdmin();

    $this->getJson('/api/v1/document-requests/logbook')->assertOk();
});

test('Student Staff can view the request-history endpoint', function () {
    granularSeedStatuses();
    makeStudentStaffAdmin();

    $this->getJson('/api/v1/request-history')->assertOk();
});

// ═════════════════════════════════════════════════════════════════════════════
// Coarse gate — an admin with ZERO dashboard write access (neither Process
// nor Complete) is blocked before the fine gate is ever reached.
// ═════════════════════════════════════════════════════════════════════════════

test('an admin with dashboard View only cannot update a request at all', function () {
    granularSeedStatuses();
    $policy = Policy::firstOrCreate(
        ['name' => 'Test View-Only Dashboard (WI#1)'],
        ['permissions' => ['dashboard' => ['View']], 'is_system' => false]
    );
    $admin = SystemUser::factory()->create([
        'role_id'   => SystemUser::ROLE_ADMIN,
        'status'    => 'Activated',
        'policy_id' => $policy->policy_id,
    ]);
    Sanctum::actingAs($admin);

    $docReq = makeGranularDocRequest(RequestStatusEnum::ReadyToClaim);

    $this->putJson("/api/v1/document-requests/{$docReq->request_id}", [
        'status_id' => RequestStatusEnum::Completed->value,
    ])->assertStatus(403);
});

test('an admin with dashboard View only cannot claim a request', function () {
    granularSeedStatuses();
    $policy = Policy::firstOrCreate(
        ['name' => 'Test View-Only Dashboard (WI#1)'],
        ['permissions' => ['dashboard' => ['View']], 'is_system' => false]
    );
    $admin = SystemUser::factory()->create([
        'role_id'   => SystemUser::ROLE_ADMIN,
        'status'    => 'Activated',
        'policy_id' => $policy->policy_id,
    ]);
    Sanctum::actingAs($admin);

    $docReq = makeGranularDocRequest(RequestStatusEnum::ReadyToClaim);

    $this->postJson('/api/v1/document-requests/claim', ['uuid' => $docReq->uuid])
         ->assertStatus(403);
});

// ═════════════════════════════════════════════════════════════════════════════
// Migration — the seeded system policies must already carry the exact
// spec'd granular shape after 2026_08_22_000000_convert_dashboard_logbook_
// to_granular_actions has run (RefreshDatabase runs every migration once
// before this test body executes).
// ═════════════════════════════════════════════════════════════════════════════

test('the seeded Registrar Staff system policy carries the full granular shape', function () {
    $policy = Policy::where('name', 'Registrar Staff')->first();

    expect($policy)->not->toBeNull();
    expect($policy->permissions['dashboard'] ?? [])
        ->toEqualCanonicalizing(['View', 'Process', 'Complete']);
    expect($policy->permissions['logbook'] ?? [])
        ->toEqualCanonicalizing(['View', 'Export']);
});

test('the seeded Student Staff system policy carries the restricted granular shape', function () {
    $policy = Policy::where('name', 'Student Staff')->first();

    expect($policy)->not->toBeNull();
    expect($policy->permissions['dashboard'] ?? [])
        ->toEqualCanonicalizing(['View', 'Complete']);
    expect($policy->permissions['dashboard'] ?? [])->not->toContain('Process');
});

// ═════════════════════════════════════════════════════════════════════════════
// PolicyService sanitization — a hand-crafted policy write can't smuggle
// the legacy single-token 'Access' (or any other unrecognized token) into
// a module that now has its own granular action vocabulary.
// ═════════════════════════════════════════════════════════════════════════════

test('creating a policy via the API drops the legacy Access token from dashboard/logbook', function () {
    $superAdmin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN]);
    Sanctum::actingAs($superAdmin);

    $response = $this->postJson('/api/v1/policies', [
        'name'        => 'Hand-Crafted Legacy Shape',
        'permissions' => [
            'dashboard' => ['Access', 'View'], // 'Access' is no longer valid here
            'logbook'   => ['Access'],
        ],
    ]);

    $response->assertStatus(201);

    $stored = Policy::where('name', 'Hand-Crafted Legacy Shape')->first();
    expect($stored->permissions['dashboard'])->toBe(['View'])
        ->and($stored->permissions['logbook'])->toBe([]);
});

test('creating a policy via the API rejects an action token that does not belong to its module', function () {
    $superAdmin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN]);
    Sanctum::actingAs($superAdmin);

    $response = $this->postJson('/api/v1/policies', [
        'name'        => 'Cross-Module Token Attempt',
        'permissions' => [
            'dashboard' => ['Export'], // valid token, wrong module
            'logbook'   => ['Process'], // valid token, wrong module
        ],
    ]);

    $response->assertStatus(201);

    $stored = Policy::where('name', 'Cross-Module Token Attempt')->first();
    expect($stored->permissions['dashboard'])->toBe([])
        ->and($stored->permissions['logbook'])->toBe([]);
});