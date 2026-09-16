<?php

use App\Exceptions\AccountExpiredException;
use App\Exceptions\AccountPendingVerificationException;
use App\Exceptions\AccountRejectedException;
use App\Models\AuditLog;
use App\Models\SystemUser;
use App\Models\UndergradRequestorVerification;
use App\Services\Sso\IdpClient;
use App\Services\Sso\SsoAuthService;
use App\Services\Sso\UserProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

function urpRequest(): Request
{
    return Request::create('/api/auth/callback', 'POST');
}

function urpPendingRequestor(array $overrides = []): SystemUser
{
    return SystemUser::factory()->create(array_merge([
        'role_id'            => SystemUser::ROLE_UNDERGRAD_REQUESTOR,
        'status'             => 'Pending Verification',
        'idp_user_id'        => null,
        'password'           => null,
        'local_auth_enabled' => 0,
        'pending_expires_at' => now()->addDays(14),
    ], $overrides));
}

// ═════════════════════════════════════════════════════════════════════════════
// Approved -> first SSO login auto-activates
// ═════════════════════════════════════════════════════════════════════════════

test('an Approved Undergrad Requestor auto-activates on first SSO login', function () {
    $user = urpPendingRequestor();
    UndergradRequestorVerification::factory()->approved()->create(['user_id' => $user->user_id]);

    $service = app(UserProvisioningService::class);
    $result  = $service->provision([
        'id'    => 'idp-undergrad-abc',
        'email' => $user->email,
    ], urpRequest());

    $user->refresh();
    expect($user->status)->toBe('Activated');
    expect($user->idp_user_id)->toBe('idp-undergrad-abc');
    expect($user->pending_expires_at)->toBeNull();
    expect($result->user->user_id)->toBe($user->user_id);

    $this->assertDatabaseHas('audit_logs', [
        'action'         => AuditLog::ACTION_UNDERGRAD_REQUESTOR_ACTIVATED,
        'target_user_id' => $user->user_id,
        'user_id'        => $user->user_id,
    ]);
});

test('an already-Activated Undergrad Requestor logging in again does not re-trigger activation', function () {
    $user = SystemUser::factory()->create([
        'role_id'     => SystemUser::ROLE_UNDERGRAD_REQUESTOR,
        'status'      => 'Activated',
        'idp_user_id' => 'already-linked',
    ]);
    UndergradRequestorVerification::factory()->approved()->create(['user_id' => $user->user_id]);

    app(UserProvisioningService::class)->provision([
        'id'    => 'a-different-idp-id',
        'email' => $user->email,
    ], urpRequest());

    $user->refresh();
    expect($user->idp_user_id)->toBe('already-linked');
    expect(AuditLog::where('action', AuditLog::ACTION_UNDERGRAD_REQUESTOR_ACTIVATED)->count())->toBe(0);
});

// ═════════════════════════════════════════════════════════════════════════════
// Rejected -> blocked, no token, IdP token revoked
// ═════════════════════════════════════════════════════════════════════════════

test('a Rejected Undergrad Requestor is blocked at provision() with no status change', function () {
    $user = urpPendingRequestor(['status' => 'Rejected']);
    UndergradRequestorVerification::factory()->rejected()->create(['user_id' => $user->user_id]);

    $service = app(UserProvisioningService::class);

    expect(fn () => $service->provision([
        'id'    => 'idp-undergrad-rejected',
        'email' => $user->email,
    ], urpRequest()))->toThrow(AccountRejectedException::class);

    $user->refresh();
    expect($user->status)->toBe('Rejected');
    expect($user->idp_user_id)->toBeNull();
});

test('SsoAuthService revokes the IdP token when a Rejected Undergrad Requestor attempts login', function () {
    $user = urpPendingRequestor(['status' => 'Rejected']);
    UndergradRequestorVerification::factory()->rejected()->create(['user_id' => $user->user_id]);

    $this->mock(IdpClient::class, function ($mock) use ($user) {
        $mock->shouldReceive('exchangeCode')->once()->with('auth-code')->andReturn('access-token-123');
        $mock->shouldReceive('fetchUserProfile')->once()->with('access-token-123')->andReturn([
            'id'    => 'idp-undergrad-rejected',
            'email' => $user->email,
        ]);
        // The revocation this whole test exists to prove happens.
        $mock->shouldReceive('logout')->once()->with('access-token-123', 'idp-undergrad-rejected');
    });

    $service = app(SsoAuthService::class);

    expect(fn () => $service->loginWithCode('auth-code', urpRequest()))
        ->toThrow(AccountRejectedException::class);
});

// ═════════════════════════════════════════════════════════════════════════════
// Still Pending -> blocked, calm message, no token, no revocation
// ═════════════════════════════════════════════════════════════════════════════

test('a still-Pending Undergrad Requestor is blocked with a non-alarming message', function () {
    $user = urpPendingRequestor();
    UndergradRequestorVerification::factory()->create(['user_id' => $user->user_id]); // status defaults to Pending

    $service = app(UserProvisioningService::class);

    try {
        $service->provision(['id' => 'idp-undergrad-pending', 'email' => $user->email], urpRequest());
        $this->fail('Expected AccountPendingVerificationException to be thrown.');
    } catch (AccountPendingVerificationException $e) {
        expect($e->getMessage())->toContain('still under review');
    }

    $user->refresh();
    expect($user->status)->toBe('Pending Verification');
    expect($user->idp_user_id)->toBeNull();
});

test('SsoAuthService does NOT revoke the IdP token for a still-Pending Undergrad Requestor', function () {
    $user = urpPendingRequestor();
    UndergradRequestorVerification::factory()->create(['user_id' => $user->user_id]);

    $this->mock(IdpClient::class, function ($mock) use ($user) {
        $mock->shouldReceive('exchangeCode')->once()->andReturn('access-token-456');
        $mock->shouldReceive('fetchUserProfile')->once()->andReturn([
            'id'    => 'idp-undergrad-pending',
            'email' => $user->email,
        ]);
        // The whole point of this test: logout() must never be called for
        // a merely-pending account — this isn't a punitive rejection.
        $mock->shouldNotReceive('logout');
    });

    $service = app(SsoAuthService::class);

    expect(fn () => $service->loginWithCode('auth-code', urpRequest()))
        ->toThrow(AccountPendingVerificationException::class);
});

// ═════════════════════════════════════════════════════════════════════════════
// Abandoned (past 14-day window) -> self-heals to Expired
// ═════════════════════════════════════════════════════════════════════════════

test('a Pending Verification Undergrad Requestor past their 14-day window self-heals to Expired', function () {
    $user = urpPendingRequestor(['pending_expires_at' => now()->subDay()]);
    UndergradRequestorVerification::factory()->create(['user_id' => $user->user_id]);

    $service = app(UserProvisioningService::class);

    expect(fn () => $service->provision([
        'id'    => 'idp-undergrad-expired',
        'email' => $user->email,
    ], urpRequest()))->toThrow(AccountExpiredException::class);

    $user->refresh();
    expect($user->status)->toBe('Expired');

    $this->assertDatabaseHas('audit_logs', [
        'action'         => AuditLog::ACTION_ADMIN_EXPIRED,
        'target_user_id' => $user->user_id,
    ]);
});

// ═════════════════════════════════════════════════════════════════════════════
// RoleResolver untouched — role always comes from the DB, never the IdP
// ═════════════════════════════════════════════════════════════════════════════

test('an Approved Undergrad Requestor keeps role_id 5 even if the IdP profile carries no roles claim', function () {
    $user = urpPendingRequestor();
    UndergradRequestorVerification::factory()->approved()->create(['user_id' => $user->user_id]);

    $service = app(UserProvisioningService::class);
    $result  = $service->provision([
        'id'    => 'idp-undergrad-no-roles-claim',
        'email' => $user->email,
        // Deliberately no 'roles' key — mirrors how the IdP's real
        // response omits it for non-admin account types (see
        // isSystemAdministratorAccountType()'s docblock).
    ], urpRequest());

    expect($result->user->role_id)->toBe(SystemUser::ROLE_UNDERGRAD_REQUESTOR);
});
