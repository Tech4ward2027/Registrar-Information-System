<?php

use App\Models\AccessRequest;
use App\Models\AuditLog;
use App\Models\Policy;
use App\Models\SystemUser;
use App\Services\AccessRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function arsActingAdmin(array $overrides = []): SystemUser
{
    $policy = Policy::create([
        'name'        => 'Access Request Submitters',
        'permissions' => ['access_requests' => ['Access']],
        'is_system'   => false,
    ]);

    $actor = SystemUser::factory()->create(array_merge([
        'role_id'   => SystemUser::ROLE_ADMIN,
        'status'    => 'Activated',
        'policy_id' => $policy->policy_id,
    ], $overrides));

    Sanctum::actingAs($actor);
    return $actor;
}

function arsActingSuperAdmin(): SystemUser
{
    $actor = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN, 'status' => 'Activated']);
    Sanctum::actingAs($actor);
    return $actor;
}

function arsRequest(): Request
{
    // Request::create() builds a bare request with no user resolver bound —
    // unlike the real request Laravel's HTTP kernel injects in production,
    // which has one attached by the auth middleware. Sanctum::actingAs()
    // authenticates the guard, but $request->user() on this object still
    // needs to be told to ask it explicitly.
    $request = Request::create('/api/v1/access-requests', 'POST');
    $request->setUserResolver(fn () => auth()->user());
    return $request;
}

// ═════════════════════════════════════════════════════════════════════════════
// store()
// ═════════════════════════════════════════════════════════════════════════════

test('store() creates a Requested row expiring in 7 days', function () {
    $actor = arsActingAdmin();

    $service = app(AccessRequestService::class);
    $accessRequest = $service->store([
        'target_email'      => 'newperson@example.com',
        'target_first_name' => 'New',
        'target_last_name'  => 'Person',
        'requested_role_id' => SystemUser::ROLE_ADMIN,
        'justification'     => 'Needs access to help at the front desk.',
    ], arsRequest());

    expect($accessRequest->status)->toBe(AccessRequest::STATUS_REQUESTED);
    expect($accessRequest->requested_by)->toBe($actor->user_id);
    expect($accessRequest->expires_at->diffInDays(now()->addDays(7)))->toBeLessThan(1);

    $this->assertDatabaseHas('audit_logs', ['action' => AuditLog::ACTION_ACCESS_REQUEST_SUBMITTED]);
});

test('store() rejects a target email that already belongs to a SystemUser', function () {
    arsActingAdmin();
    $existing = SystemUser::factory()->create();

    $service = app(AccessRequestService::class);

    expect(fn () => $service->store([
        'target_email'      => $existing->email,
        'target_first_name' => 'New',
        'target_last_name'  => 'Person',
        'requested_role_id' => SystemUser::ROLE_ADMIN,
        'justification'     => 'Duplicate.',
    ], arsRequest()))->toThrow(ValidationException::class);
});

test('store() persists an optional target middle name', function () {
    arsActingAdmin();

    $service = app(AccessRequestService::class);
    $accessRequest = $service->store([
        'target_email'       => 'withmiddle@example.com',
        'target_first_name'  => 'New',
        'target_middle_name' => 'Santos',
        'target_last_name'   => 'Person',
        'requested_role_id'  => SystemUser::ROLE_ADMIN,
        'justification'      => 'Needs access to help at the front desk.',
    ], arsRequest());

    expect($accessRequest->target_middle_name)->toBe('Santos');
});

test('store() leaves target middle name null when omitted', function () {
    arsActingAdmin();

    $service = app(AccessRequestService::class);
    $accessRequest = $service->store([
        'target_email'      => 'nomiddle@example.com',
        'target_first_name' => 'New',
        'target_last_name'  => 'Person',
        'requested_role_id' => SystemUser::ROLE_ADMIN,
        'justification'     => 'Needs access to help at the front desk.',
    ], arsRequest());

    expect($accessRequest->target_middle_name)->toBeNull();
});

test('store() persists an optional target suffix', function () {
    arsActingAdmin();

    $service = app(AccessRequestService::class);
    $accessRequest = $service->store([
        'target_email'      => 'withsuffix@example.com',
        'target_first_name' => 'New',
        'target_last_name'  => 'Person',
        'target_suffix'     => 'Jr.',
        'requested_role_id' => SystemUser::ROLE_ADMIN,
        'justification'     => 'Needs access to help at the front desk.',
    ], arsRequest());

    expect($accessRequest->target_suffix)->toBe('Jr.');
});

test('store() leaves target suffix null when omitted', function () {
    arsActingAdmin();

    $service = app(AccessRequestService::class);
    $accessRequest = $service->store([
        'target_email'      => 'nosuffix@example.com',
        'target_first_name' => 'New',
        'target_last_name'  => 'Person',
        'requested_role_id' => SystemUser::ROLE_ADMIN,
        'justification'     => 'Needs access to help at the front desk.',
    ], arsRequest());

    expect($accessRequest->target_suffix)->toBeNull();
});

// ═════════════════════════════════════════════════════════════════════════════
// approve()
// ═════════════════════════════════════════════════════════════════════════════

test('approve() carries the target middle name onto the created SystemUser profile', function () {
    $submitter = arsActingAdmin();
    $accessRequest = AccessRequest::create([
        'requested_by'       => $submitter->user_id,
        'target_email'       => 'approvedwithmiddle@example.com',
        'target_first_name'  => 'Approved',
        'target_middle_name' => 'Reyes',
        'target_last_name'   => 'Person',
        'requested_role_id'  => SystemUser::ROLE_ADMIN,
        'justification'      => 'Needed.',
        'status'             => AccessRequest::STATUS_REQUESTED,
        'expires_at'         => now()->addDays(7),
    ]);

    arsActingSuperAdmin();
    $service = app(AccessRequestService::class);
    $user    = $service->approve($accessRequest, arsRequest());

    $this->assertDatabaseHas('admin_profile', [
        'user_id'     => $user->user_id,
        'middle_name' => 'Reyes',
    ]);
});

test('approve() carries the target suffix onto the created SystemUser profile', function () {
    $submitter = arsActingAdmin();
    $accessRequest = AccessRequest::create([
        'requested_by'       => $submitter->user_id,
        'target_email'       => 'approvedwithsuffix@example.com',
        'target_first_name'  => 'Approved',
        'target_last_name'   => 'Person',
        'target_suffix'      => 'III',
        'requested_role_id'  => SystemUser::ROLE_ADMIN,
        'justification'      => 'Needed.',
        'status'             => AccessRequest::STATUS_REQUESTED,
        'expires_at'         => now()->addDays(7),
    ]);

    arsActingSuperAdmin();
    $service = app(AccessRequestService::class);
    $user    = $service->approve($accessRequest, arsRequest());

    $this->assertDatabaseHas('admin_profile', [
        'user_id' => $user->user_id,
        'suffix'  => 'III',
    ]);
});

test('approve() creates a Pending Activation SystemUser and marks the request Fulfilled', function () {
    $submitter = arsActingAdmin();
    $accessRequest = AccessRequest::create([
        'requested_by'      => $submitter->user_id,
        'target_email'      => 'approved@example.com',
        'target_first_name' => 'Approved',
        'target_last_name'  => 'Person',
        'requested_role_id' => SystemUser::ROLE_ADMIN,
        'justification'     => 'Needed.',
        'status'            => AccessRequest::STATUS_REQUESTED,
        'expires_at'        => now()->addDays(7),
    ]);

    $reviewer = arsActingSuperAdmin();
    $service  = app(AccessRequestService::class);
    $user     = $service->approve($accessRequest, arsRequest());

    expect($user->status)->toBe('Pending Activation');
    expect($user->idp_user_id)->toBeNull();
    expect($user->password)->toBeNull();

    $accessRequest->refresh();
    expect($accessRequest->status)->toBe(AccessRequest::STATUS_FULFILLED);
    expect($accessRequest->fulfilled_user_id)->toBe($user->user_id);
    expect($accessRequest->reviewed_by)->toBe($reviewer->user_id);
    expect($accessRequest->expires_at)->toBeNull();

    $this->assertDatabaseHas('audit_logs', ['action' => AuditLog::ACTION_ACCESS_REQUEST_APPROVED]);
});

test('approve() fails for a request that is not in Requested status', function () {
    $submitter = arsActingAdmin();
    $accessRequest = AccessRequest::create([
        'requested_by'      => $submitter->user_id,
        'target_email'      => 'alreadyfulfilled@example.com',
        'target_first_name' => 'X',
        'target_last_name'  => 'Y',
        'requested_role_id' => SystemUser::ROLE_ADMIN,
        'justification'     => 'Needed.',
        'status'            => AccessRequest::STATUS_FULFILLED,
        'expires_at'        => null,
    ]);

    arsActingSuperAdmin();
    $service = app(AccessRequestService::class);

    expect(fn () => $service->approve($accessRequest, arsRequest()))->toThrow(ValidationException::class);
});

// ═════════════════════════════════════════════════════════════════════════════
// reject()
// ═════════════════════════════════════════════════════════════════════════════

test('reject() stores the reason and marks the request Rejected without creating a SystemUser', function () {
    $submitter = arsActingAdmin();
    $accessRequest = AccessRequest::create([
        'requested_by'      => $submitter->user_id,
        'target_email'      => 'rejected@example.com',
        'target_first_name' => 'Rej',
        'target_last_name'  => 'Ected',
        'requested_role_id' => SystemUser::ROLE_ADMIN,
        'justification'     => 'Needed.',
        'status'            => AccessRequest::STATUS_REQUESTED,
        'expires_at'        => now()->addDays(7),
    ]);

    arsActingSuperAdmin();
    $service = app(AccessRequestService::class);
    $service->reject($accessRequest, 'Not enough justification provided.', arsRequest());

    $accessRequest->refresh();
    expect($accessRequest->status)->toBe(AccessRequest::STATUS_REJECTED);
    expect($accessRequest->rejection_reason)->toBe('Not enough justification provided.');

    $this->assertDatabaseMissing('users', ['email' => 'rejected@example.com']);
    $this->assertDatabaseHas('audit_logs', ['action' => AuditLog::ACTION_ACCESS_REQUEST_REJECTED]);
});

// ═════════════════════════════════════════════════════════════════════════════
// analytics cache invalidation (QA bugs #4 / #9 / #14)
// ═════════════════════════════════════════════════════════════════════════════
// AnalyticsController / SuperAdminAnalyticsController cache every panel
// for 10 minutes under the shared "analytics" Cache::tags() bucket.
// store()/approve()/reject() must each flush that tag so a change here
// shows up on the next dashboard load instead of within-window stale
// data. Simulated the same way SuperAdminAnalyticsControllerTest does:
// seed a key under the tag, run the write, assert the key is gone.

test('store() flushes the analytics cache', function () {
    arsActingAdmin();
    Cache::tags(['analytics'])->put('probe', 'stale', now()->addMinutes(10));

    $service = app(AccessRequestService::class);
    $service->store([
        'target_email'      => 'cacheflush-store@example.com',
        'target_first_name' => 'New',
        'target_last_name'  => 'Person',
        'requested_role_id' => SystemUser::ROLE_ADMIN,
        'justification'     => 'Needs access to help at the front desk.',
    ], arsRequest());

    expect(Cache::tags(['analytics'])->has('probe'))->toBeFalse();
});

test('approve() flushes the analytics cache', function () {
    $submitter = arsActingAdmin();
    $accessRequest = AccessRequest::create([
        'requested_by'      => $submitter->user_id,
        'target_email'      => 'cacheflush-approve@example.com',
        'target_first_name' => 'X',
        'target_last_name'  => 'Y',
        'requested_role_id' => SystemUser::ROLE_ADMIN,
        'justification'     => 'Needed.',
        'status'            => AccessRequest::STATUS_REQUESTED,
        'expires_at'        => now()->addDays(7),
    ]);

    arsActingSuperAdmin();
    Cache::tags(['analytics'])->put('probe', 'stale', now()->addMinutes(10));

    $service = app(AccessRequestService::class);
    $service->approve($accessRequest, arsRequest());

    expect(Cache::tags(['analytics'])->has('probe'))->toBeFalse();
});

test('reject() flushes the analytics cache', function () {
    $submitter = arsActingAdmin();
    $accessRequest = AccessRequest::create([
        'requested_by'      => $submitter->user_id,
        'target_email'      => 'cacheflush-reject@example.com',
        'target_first_name' => 'X',
        'target_last_name'  => 'Y',
        'requested_role_id' => SystemUser::ROLE_ADMIN,
        'justification'     => 'Needed.',
        'status'            => AccessRequest::STATUS_REQUESTED,
        'expires_at'        => now()->addDays(7),
    ]);

    arsActingSuperAdmin();
    Cache::tags(['analytics'])->put('probe', 'stale', now()->addMinutes(10));

    $service = app(AccessRequestService::class);
    $service->reject($accessRequest, 'Not enough justification provided.', arsRequest());

    expect(Cache::tags(['analytics'])->has('probe'))->toBeFalse();
});

// ═════════════════════════════════════════════════════════════════════════════
// Authorization — a non-super-admin can never approve/reject, only submit
// ═════════════════════════════════════════════════════════════════════════════

test('a regular admin with the access_requests module can submit but cannot approve', function () {
    arsActingAdmin();

    $this->postJson('/api/v1/access-requests', [
        'target_email'      => 'viaapi@example.com',
        'target_first_name' => 'Via',
        'target_last_name'  => 'Api',
        'requested_role_id' => SystemUser::ROLE_ADMIN,
        'justification'     => 'Needed for front desk coverage.',
    ])->assertCreated();

    $accessRequest = AccessRequest::where('target_email', 'viaapi@example.com')->firstOrFail();

    $this->postJson("/api/v1/access-requests/{$accessRequest->id}/approve")
        ->assertStatus(403);
});

test('an admin without the access_requests module cannot submit a request', function () {
    SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN, 'status' => 'Activated', 'policy_id' => null]);
    $actor = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN, 'status' => 'Activated', 'policy_id' => null]);
    Sanctum::actingAs($actor);

    $this->postJson('/api/v1/access-requests', [
        'target_email'      => 'blocked@example.com',
        'target_first_name' => 'Blocked',
        'target_last_name'  => 'Admin',
        'requested_role_id' => SystemUser::ROLE_ADMIN,
        'justification'     => 'Should be blocked.',
    ])->assertStatus(403);
});

// ═════════════════════════════════════════════════════════════════════════════
// store() — notifies Super Admins
// (QA fix: submitting an access request never notified anyone who could
// actually review it. QUEUE_CONNECTION=sync in tests — see phpunit.xml —
// so the dispatched SendBulkNotificationJob runs inline, no Queue::fake()
// needed to observe its effect on the notifications table.)
// ═════════════════════════════════════════════════════════════════════════════

test('store() notifies every Activated Super Admin', function () {
    $superAdminOne = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN, 'status' => 'Activated']);
    $superAdminTwo = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN, 'status' => 'Activated']);
    $actor = arsActingAdmin();

    $service = app(AccessRequestService::class);
    $accessRequest = $service->store([
        'target_email'      => 'notify-me@example.com',
        'target_first_name' => 'Notify',
        'target_last_name'  => 'Me',
        'requested_role_id' => SystemUser::ROLE_ADMIN,
        'justification'     => 'Needs review.',
    ], arsRequest());

    foreach ([$superAdminOne, $superAdminTwo] as $superAdmin) {
        $notification = \App\Models\Notification::where('notifiable_type', SystemUser::class)
            ->where('notifiable_id', $superAdmin->user_id)
            ->first();

        expect($notification)->not->toBeNull();
        expect($notification->data['access_request_id'])->toBe($accessRequest->id);
        expect($notification->data['target_email'])->toBe('notify-me@example.com');
    }
});

test('store() does not notify a regular Admin', function () {
    $actor = arsActingAdmin();
    $otherAdmin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN, 'status' => 'Activated', 'policy_id' => null]);

    $service = app(AccessRequestService::class);
    $service->store([
        'target_email'      => 'no-notify-admin@example.com',
        'target_first_name' => 'No',
        'target_last_name'  => 'Notify',
        'requested_role_id' => SystemUser::ROLE_ADMIN,
        'justification'     => 'Needs review.',
    ], arsRequest());

    $notification = \App\Models\Notification::where('notifiable_type', SystemUser::class)
        ->where('notifiable_id', $otherAdmin->user_id)
        ->first();

    expect($notification)->toBeNull();
});

test('store() does not notify an inactive Super Admin', function () {
    $actor = arsActingAdmin();
    $inactiveSuperAdmin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN, 'status' => 'Deactivated']);

    $service = app(AccessRequestService::class);
    $service->store([
        'target_email'      => 'no-notify-inactive@example.com',
        'target_first_name' => 'No',
        'target_last_name'  => 'Notify',
        'requested_role_id' => SystemUser::ROLE_ADMIN,
        'justification'     => 'Needs review.',
    ], arsRequest());

    $notification = \App\Models\Notification::where('notifiable_type', SystemUser::class)
        ->where('notifiable_id', $inactiveSuperAdmin->user_id)
        ->first();

    expect($notification)->toBeNull();
});