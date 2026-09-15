<?php

use App\Enums\UndergradRequestorVerificationStatusEnum;
use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Models\RequestPurpose;
use App\Models\SystemUser;
use App\Models\UndergradRequestorProfile;
use App\Models\UndergradRequestorVerification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Undergrad Requestor Registration — Phase 5 feature tests
|--------------------------------------------------------------------------
| Covers the request-flow gate (an Approved Undergrad Requestor uses the
| exact same filing endpoints Students/Alumni use; a non-Approved one is
| cleanly blocked) and confirms the six whitelist locations Phase 5
| touched behave as intended — including the two FreeRequestService
| locations that were deliberately left EXCLUDING this role.
|--------------------------------------------------------------------------
*/

// ── Helpers ──────────────────────────────────────────────────────────

/**
 * An Activated Undergrad Requestor with an Approved verification — the
 * state the account can only ever reach via Phase 3/4's approve ->
 * first-IDP-login pipeline (see UndergradRequestorProvisioningTest.php).
 * Constructed directly here since these tests exercise Phase 5, not that
 * pipeline itself.
 */
function p5ApprovedRequestor(): SystemUser
{
    $user = SystemUser::factory()->create([
        'role_id'            => SystemUser::ROLE_UNDERGRAD_REQUESTOR,
        'status'             => 'Activated',
        'idp_user_id'        => 'idp-' . uniqid(),
        'password'           => null,
        'local_auth_enabled' => 0,
        'pending_expires_at' => null,
    ]);

    UndergradRequestorProfile::factory()->create([
        'user_id'           => $user->user_id,
        'first_name'        => 'Maria',
        'last_name'         => 'Santos',
        'email_verified_at' => now(),
    ]);

    UndergradRequestorVerification::factory()->create([
        'user_id'     => $user->user_id,
        'status'      => UndergradRequestorVerificationStatusEnum::Approved,
        'reviewed_at' => now(),
    ]);

    Sanctum::actingAs($user);

    return $user;
}

/** A Pending (unapproved) submission — cannot normally hold a live
 * session per Phase 3's activation guard, but constructed with a token
 * anyway to prove the defense-in-depth layers (route middleware +
 * service-level check) hold even in that otherwise-unreachable state. */
function p5PendingRequestor(): SystemUser
{
    $user = SystemUser::factory()->create([
        'role_id' => SystemUser::ROLE_UNDERGRAD_REQUESTOR,
        // 'Activated' here is deliberately artificial — see the
        // docblock above. EnsureAccountActive only checks this column,
        // so this is the one way to reach EnsureUndergradRequestorApproved
        // and DocumentRequestService's check in a test without also
        // rebuilding the SSO callback flow.
        'status'      => 'Activated',
        'idp_user_id' => 'idp-' . uniqid(),
    ]);

    UndergradRequestorProfile::factory()->create(['user_id' => $user->user_id]);
    UndergradRequestorVerification::factory()->create([
        'user_id' => $user->user_id,
        'status'  => UndergradRequestorVerificationStatusEnum::Pending,
    ]);

    Sanctum::actingAs($user);

    return $user;
}

function p5RejectedRequestor(): SystemUser
{
    $user = SystemUser::factory()->create([
        'role_id'     => SystemUser::ROLE_UNDERGRAD_REQUESTOR,
        'status'      => 'Activated', // artificial; see p5PendingRequestor()
        'idp_user_id' => 'idp-' . uniqid(),
    ]);

    UndergradRequestorProfile::factory()->create(['user_id' => $user->user_id]);
    UndergradRequestorVerification::factory()->create([
        'user_id'          => $user->user_id,
        'status'           => UndergradRequestorVerificationStatusEnum::Rejected,
        'reviewed_at'      => now(),
        'rejection_reason' => 'Test fixture rejection.',
    ]);

    Sanctum::actingAs($user);

    return $user;
}

function p5Catalog(): array
{
    $docType = DocumentType::create([
        'document_name'             => 'Test Fixture Certificate',
        'document_description'      => '',
        'document_process_period'   => 5,
        'access_id'                 => 1,
        'cashier_document_patterns' => ['Test Fixture Certificate'],
    ]);
    $purpose = RequestPurpose::create(['purpose_name' => 'Employment']);

    return [$docType, $purpose];
}

// ═══════════════════════════════════════════════════════════════════════
// The request-flow gate
// ═══════════════════════════════════════════════════════════════════════

test('an Approved Undergrad Requestor can file a document request through the same endpoint as Student/Alumni', function () {
    [$docType, $purpose] = p5Catalog();
    $user = p5ApprovedRequestor();

    $response = $this->postJson('/api/document-requests', [
        'request_purpose_id' => $purpose->request_purpose_id,
        'documents'           => [['document_type_id' => $docType->document_type_id, 'number_of_copies' => 1]],
        'certificates'        => [],
    ])->assertStatus(201);

    $requestId = $response->json('request_id') ?? DocumentRequest::first()->request_id;

    $documentRequest = DocumentRequest::findOrFail($requestId);
    expect($documentRequest->user_id)->toBe($user->user_id);

    // No parallel system: every academic-record FK stays null (D5) —
    // this role has no such record, not a special one.
    expect($documentRequest->student_profile_id)->toBeNull();
    expect($documentRequest->student_academic_id)->toBeNull();
    expect($documentRequest->alumni_profile_id)->toBeNull();
    expect($documentRequest->alumni_academic_id)->toBeNull();
});

test('a still-Pending Undergrad Requestor is blocked with a review-pending message, not a generic 403', function () {
    [$docType, $purpose] = p5Catalog();
    p5PendingRequestor();

    $response = $this->postJson('/api/document-requests', [
        'request_purpose_id' => $purpose->request_purpose_id,
        'documents'           => [['document_type_id' => $docType->document_type_id, 'number_of_copies' => 1]],
        'certificates'        => [],
    ])->assertStatus(403);

    expect($response->json('undergrad_requestor_status'))->toBe('pending');
    expect(DocumentRequest::count())->toBe(0);
});

test('a Rejected Undergrad Requestor is blocked with a rejected-specific message', function () {
    [$docType, $purpose] = p5Catalog();
    p5RejectedRequestor();

    $response = $this->postJson('/api/document-requests', [
        'request_purpose_id' => $purpose->request_purpose_id,
        'documents'           => [['document_type_id' => $docType->document_type_id, 'number_of_copies' => 1]],
        'certificates'        => [],
    ])->assertStatus(403);

    expect($response->json('undergrad_requestor_status'))->toBe('rejected');
    expect(DocumentRequest::count())->toBe(0);
});

test('the gate is a no-op for Student and Alumni', function () {
    [$docType, $purpose] = p5Catalog();

    $student = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT, 'status' => 'Activated']);
    \App\Models\StudentProfile::factory()->create(['user_id' => $student->user_id]);
    \App\Models\StudentAcademicRecord::factory()->create([
        'student_profile_id' => $student->studentProfile->student_profile_id,
    ]);
    Sanctum::actingAs($student);

    $this->postJson('/api/document-requests', [
        'request_purpose_id' => $purpose->request_purpose_id,
        'documents'           => [['document_type_id' => $docType->document_type_id, 'number_of_copies' => 1]],
        'certificates'        => [],
    ])->assertStatus(201);
});

test('the service-level check blocks creation even if the route middleware were bypassed', function () {
    // Defense-in-depth: call the service directly, skipping HTTP/route
    // middleware entirely, to prove buildRequestData() re-derives the
    // approval check on its own rather than trusting the caller.
    [$docType, $purpose] = p5Catalog();
    $user = p5PendingRequestor();

    expect(fn () => app(\App\Services\DocumentRequestService::class)->createRequest($user, [
        'request_purpose_id' => $purpose->request_purpose_id,
        'documents'           => [['document_type_id' => $docType->document_type_id, 'number_of_copies' => 1]],
        'certificates'        => [],
    ]))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);

    expect(DocumentRequest::count())->toBe(0);
});

test('request-documents line-item store is also gated', function () {
    [$docType, $purpose] = p5Catalog();
    p5PendingRequestor();

    // No parent request exists (blocked above), so this also fails —
    // but via the same role/approval gate before it would even reach
    // the ownership lookup.
    $this->postJson('/api/request-documents', [
        'request_id'        => 999999,
        'document_type_id'  => $docType->document_type_id,
        'number_of_copies'  => 1,
    ])->assertStatus(403);
});

// ═══════════════════════════════════════════════════════════════════════
// The six whitelist locations
// ═══════════════════════════════════════════════════════════════════════

test('Undergrad Requestor is not a grantable role_assignment target — the onboarding pipeline is the only valid path', function () {
    // Deliberately NOT added to StoreCashierOrOverrideRequest's sibling,
    // StoreRoleAssignmentRequest's role_id whitelist — see
    // RoleAssignmentService::assertDirectionAllowed()'s Phase 5 note for
    // why: this role has exactly one valid path into existence (D4), and
    // a role_assignments grant would produce a verification-less,
    // permanently-refused account. This test locks in that this
    // rejection happens at the FormRequest layer (shape), not silently
    // deeper in the service.
    $admin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN, 'status' => 'Activated']);
    $superAdmin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN, 'status' => 'Activated']);
    Sanctum::actingAs($superAdmin);

    $this->postJson('/api/role-assignments', [
        'user_id' => $admin->user_id,
        'role_id' => SystemUser::ROLE_UNDERGRAD_REQUESTOR,
    ])->assertStatus(422)
      ->assertJsonValidationErrors('role_id');
});

test('an Approved Undergrad Requestor can still be found BY NAME as a role-assignment grant target', function () {
    // The other half of Phase 5's RoleAssignmentService change:
    // searchGrantableUsers() is the TARGET picker (who to grant a role
    // TO), which is role-agnostic by design — an Undergrad Requestor
    // account is a legitimate Admin-grant target (student-staff-style),
    // it just can't be the granted role_id ITSELF (see the test above).
    $requestor  = p5ApprovedRequestor();
    $superAdmin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN, 'status' => 'Activated']);
    Sanctum::actingAs($superAdmin);

    $this->getJson('/api/role-assignments/search-users?q=Santos')
        ->assertOk()
        ->assertJsonFragment(['user_id' => $requestor->user_id]);
});

test('CashierOrOverrideController surfaces Undergrad Requestor accounts in its picker', function () {
    $requestor = p5ApprovedRequestor();
    $admin     = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN, 'status' => 'Activated']);
    Sanctum::actingAs($admin);

    $this->getJson('/api/cashier-overrides/search-users?q=Santos')
        ->assertOk()
        ->assertJsonFragment(['user_id' => $requestor->user_id]);
});

test('a cashier OR override can be issued for an Undergrad Requestor account', function () {
    $requestor = p5ApprovedRequestor();
    $admin     = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN, 'status' => 'Activated']);
    Sanctum::actingAs($admin);

    $this->postJson('/api/cashier-overrides', [
        'or_number' => 'OR-TEST-0001',
        'user_id'   => $requestor->user_id,
        'reason'    => 'Verified physical receipt at the counter for this fixture test.',
    ])->assertStatus(201);
});

test('a cashier OR override still cannot be issued for a staff account', function () {
    $target = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN, 'status' => 'Activated']);
    $admin  = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN, 'status' => 'Activated']);
    Sanctum::actingAs($admin);

    $this->postJson('/api/cashier-overrides', [
        'or_number' => 'OR-TEST-0002',
        'user_id'   => $target->user_id,
        'reason'    => 'Should not be allowed for a staff account under test.',
    ])->assertStatus(422)
      ->assertJsonValidationErrors('user_id');
});

test('an Undergrad Requestor is excluded from the failed-login-burst security alert audience', function () {
    $requestor = p5ApprovedRequestor();

    config([
        'security_events.alert_threshold'       => 3,
        'security_events.alert_window_minutes'  => 10,
    ]);

    $logger  = app(\App\Services\SecurityEventLogger::class);
    $request = \Illuminate\Http\Request::create('/api/auth/local-login', 'POST');

    // Drive the burst through the real public API
    // (recordLoginFailure() -> private maybeAlertOnBurst()), same as
    // LocalAuthService::attempt() does on every failure branch — rather
    // than reaching into the private threshold check directly.
    for ($i = 0; $i < 4; $i++) {
        $logger->recordLoginFailure('someone-else@example.com', 'bad_password', $request);
    }

    // The exclusion list is what's under test: if
    // ROLE_UNDERGRAD_REQUESTOR were missing from it, this Approved
    // requestor — a bystander with no connection to the email above —
    // would receive the alert anyway, since sendToAllExcept() notifies
    // every role NOT explicitly named. This requestor has no other
    // reason to receive any notification in this test, so "received
    // nothing at all" is an equivalent, schema-safe check to "received
    // no burst alert specifically" (notifications rows key off
    // notification_type_id, not a trigger_event string column).
    $this->assertDatabaseMissing('notifications', [
        'notifiable_id'   => $requestor->user_id,
        'notifiable_type' => SystemUser::class,
    ]);
});

test('an Undergrad Requestor cannot be searched for or filed against in the free-request flow', function () {
    $requestor = p5ApprovedRequestor();
    $admin     = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN, 'status' => 'Activated']);
    Sanctum::actingAs($admin);

    // Location 1: FreeRequestService::searchAccounts() must not surface
    // this role at all.
    $results = app(\App\Services\FreeRequestService::class)->searchAccounts('Santos');
    expect($results->pluck('user_id'))->not->toContain($requestor->user_id);

    // Location 2: fileFreeRequest() must still refuse even if a caller
    // somehow obtained the user_id another way.
    expect(fn () => app(\App\Services\FreeRequestService::class)->fileFreeRequest(
        actor: $admin,
        targetUser: $requestor,
        validated: ['documents' => [], 'certificates' => []],
    ))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});
