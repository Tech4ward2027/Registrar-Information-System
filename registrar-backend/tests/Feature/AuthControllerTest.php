<?php

use App\Exceptions\IdpException;
use App\Exceptions\IdpUnavailableException;
use App\Models\RoleAssignment;
use App\Models\SystemUser;
use App\Services\Sso\SsoAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// ── Helpers ───────────────────────────────────────────────────────────────────

function authMakeLocalUser(array $overrides = []): SystemUser
{
    return SystemUser::factory()->create(array_merge([
        'role_id'             => SystemUser::ROLE_ADMIN,
        'status'              => 'Activated',
        'password'            => bcrypt('CorrectPass1'),
        'local_auth_enabled'  => 1,
    ], $overrides));
}

// ═════════════════════════════════════════════════════════════════════════════
// LoginRequest — shared validation, exercised through both /login and
// /auth/local-login since it's the same FormRequest class either way.
// ═════════════════════════════════════════════════════════════════════════════

test('login fails validation when email or password is missing', function () {
    $this->postJson('/api/v1/login', ['email' => 'not-an-email'])
         ->assertStatus(422)
         ->assertJsonValidationErrors(['email', 'password']);
});

test('local-login fails validation the same way as /login', function () {
    $this->postJson('/api/v1/auth/local-login', ['email' => 'not-an-email'])
         ->assertStatus(422)
         ->assertJsonValidationErrors(['email', 'password']);
});

// ═════════════════════════════════════════════════════════════════════════════
// AuthController::login() — IDP-first with local fallback
// ═════════════════════════════════════════════════════════════════════════════

test('login succeeds via the IdP and sets X-Auth-Method: idp', function () {
    $user = authMakeLocalUser();

    $this->mock(SsoAuthService::class, function ($mock) use ($user) {
        $mock->shouldReceive('loginWithCredentials')
             ->once()
             ->andReturn(['user' => $user, 'token' => 'idp-token']);
    });

    $this->postJson('/api/v1/login', [
        'email'    => $user->email,
        'password' => 'whatever-the-idp-accepted',
    ])->assertOk()
      ->assertHeader('X-Auth-Method', 'idp')
      ->assertJsonPath('user.user_id', $user->user_id);
});

test('login rejects immediately when the IdP rejects credentials (no local fallback)', function () {
    $user = authMakeLocalUser();

    $this->mock(SsoAuthService::class, function ($mock) {
        $mock->shouldReceive('loginWithCredentials')
             ->once()
             ->andThrow(new IdpException('Invalid credentials.', 401));
    });

    $this->postJson('/api/v1/login', [
        'email'    => $user->email,
        'password' => 'wrong-password',
    ])->assertStatus(401)
      ->assertJson(['message' => 'Invalid credentials.']);
});

test('login falls back to local auth when the IdP is unreachable', function () {
    $user = authMakeLocalUser();

    $this->mock(SsoAuthService::class, function ($mock) {
        $mock->shouldReceive('loginWithCredentials')
             ->once()
             ->andThrow(new IdpUnavailableException('Connection refused', 0));
    });

    $this->postJson('/api/v1/login', [
        'email'    => $user->email,
        'password' => 'CorrectPass1',
    ])->assertOk()
      ->assertHeader('X-Auth-Method', 'local')
      ->assertJsonPath('idp_offline', true)
      ->assertJsonPath('user.user_id', $user->user_id);
});

test('login fallback fails with idp_offline flag when local credentials are also wrong', function () {
    $user = authMakeLocalUser();

    $this->mock(SsoAuthService::class, function ($mock) {
        $mock->shouldReceive('loginWithCredentials')
             ->once()
             ->andThrow(new IdpUnavailableException('Connection refused', 0));
    });

    $this->postJson('/api/v1/login', [
        'email'    => $user->email,
        'password' => 'WrongPassword1',
    ])->assertStatus(401)
      ->assertJsonPath('idp_offline', true);
});

// ═════════════════════════════════════════════════════════════════════════════
// GET /api/v1/me — unaffected by the FormRequest conversion, kept as a smoke test
// ═════════════════════════════════════════════════════════════════════════════

test('unauthenticated /me returns 401', function () {
    $this->getJson('/api/v1/me')->assertStatus(401);
});

// ═════════════════════════════════════════════════════════════════════════════
// LocalAuthController::login() — always-local, no IdP involved
// ═════════════════════════════════════════════════════════════════════════════

test('local-login succeeds with correct credentials for a local-auth-enabled user', function () {
    $user = authMakeLocalUser();

    $this->postJson('/api/v1/auth/local-login', [
        'email'    => $user->email,
        'password' => 'CorrectPass1',
    ])->assertOk()
      ->assertJsonPath('user.user_id', $user->user_id);
});

test('local-login is rejected for a user without local auth enabled', function () {
    $user = authMakeLocalUser(['local_auth_enabled' => 0]);

    $this->postJson('/api/v1/auth/local-login', [
        'email'    => $user->email,
        'password' => 'CorrectPass1',
    ])->assertStatus(403);
});

test('local-login is rejected with the wrong password', function () {
    $user = authMakeLocalUser();

    $this->postJson('/api/v1/auth/local-login', [
        'email'    => $user->email,
        'password' => 'WrongPassword1',
    ])->assertStatus(401);
});

test('local-login is rejected for a deactivated account', function () {
    $user = authMakeLocalUser(['status' => 'Deactivated']);

    $this->postJson('/api/v1/auth/local-login', [
        'email'    => $user->email,
        'password' => 'CorrectPass1',
    ])->assertStatus(403);
});

// ═════════════════════════════════════════════════════════════════════════════
// LocalAuthController::setPassword() — SetLocalPasswordRequest (superadmin only)
// ═════════════════════════════════════════════════════════════════════════════

test('non-superadmin cannot set a local password', function () {
    $admin  = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN, 'status' => 'Activated']);
    Sanctum::actingAs($admin);
    $target = SystemUser::factory()->create();

    $this->postJson('/api/v1/auth/local-password', [
        'user_id'               => $target->user_id,
        'password'              => 'NewPassword1',
        'password_confirmation' => 'NewPassword1',
    ])->assertStatus(403);
});

test('setPassword fails validation when confirmation does not match', function () {
    $superAdmin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN, 'status' => 'Activated']);
    Sanctum::actingAs($superAdmin);
    // Super-admin target, isolating this test to the confirmation-mismatch
    // failure rather than also tripping the target-role rule.
    $target = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN]);

    $this->postJson('/api/v1/auth/local-password', [
        'user_id'               => $target->user_id,
        'password'              => 'NewPassword1',
        'password_confirmation' => 'Mismatch1',
    ])->assertStatus(422)
      ->assertJsonValidationErrors(['password']);
});

test('setPassword fails validation for a nonexistent user_id', function () {
    $superAdmin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN, 'status' => 'Activated']);
    Sanctum::actingAs($superAdmin);

    $this->postJson('/api/v1/auth/local-password', [
        'user_id'               => 999999,
        'password'              => 'NewPassword1',
        'password_confirmation' => 'NewPassword1',
    ])->assertStatus(422)
      ->assertJsonValidationErrors(['user_id']);
});

test('superadmin can set a local password, enabling local auth for a super-admin target', function () {
    $superAdmin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN, 'status' => 'Activated']);
    Sanctum::actingAs($superAdmin);
    // Break-glass access is restricted to Super Admin accounts — the
    // target must be one too, not just any user (see
    // SetLocalPasswordRequest).
    $target = SystemUser::factory()->create([
        'role_id'             => SystemUser::ROLE_SUPER_ADMIN,
        'local_auth_enabled'  => 0,
    ]);

    $this->postJson('/api/v1/auth/local-password', [
        'user_id'               => $target->user_id,
        'password'              => 'NewPassword1',
        'password_confirmation' => 'NewPassword1',
    ])->assertOk()
      ->assertJsonPath('user_id', $target->user_id);

    $this->assertDatabaseHas('users', ['user_id' => $target->user_id, 'local_auth_enabled' => 1]);

    // The target can now actually log in locally with the new password.
    $this->postJson('/api/v1/auth/local-login', [
        'email'    => $target->email,
        'password' => 'NewPassword1',
    ])->assertOk();
});

// ═════════════════════════════════════════════════════════════════════════════
// SetLocalPasswordRequest — target must be a Super Admin account
// ═════════════════════════════════════════════════════════════════════════════

test('setPassword rejects a target user who is not a super admin', function () {
    $superAdmin = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_SUPER_ADMIN, 'status' => 'Activated']);
    Sanctum::actingAs($superAdmin);

    foreach ([SystemUser::ROLE_STUDENT, SystemUser::ROLE_ALUMNI, SystemUser::ROLE_ADMIN] as $roleId) {
        $target = SystemUser::factory()->create(['role_id' => $roleId]);

        $this->postJson('/api/v1/auth/local-password', [
            'user_id'               => $target->user_id,
            'password'              => 'NewPassword1',
            'password_confirmation' => 'NewPassword1',
        ])->assertStatus(422)
          ->assertJsonValidationErrors(['user_id']);

        $this->assertDatabaseHas('users', ['user_id' => $target->user_id, 'local_auth_enabled' => 0]);
    }
});

// ═════════════════════════════════════════════════════════════════════════════
// POST /api/v1/auth/switch-role — Step 3 of Multi-Role Assignments
// (AuthController::switchRole / RoleAssignmentService::switchTo)
// ═════════════════════════════════════════════════════════════════════════════

test('switch-role requires authentication', function () {
    $this->postJson('/api/v1/auth/switch-role', ['role_id' => SystemUser::ROLE_ADMIN])
         ->assertStatus(401);
});

test('switch-role fails validation for a role_id outside the four known roles', function () {
    $person = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT, 'status' => 'Activated']);
    Sanctum::actingAs($person);

    $this->postJson('/api/v1/auth/switch-role', ['role_id' => 999])
         ->assertStatus(422)
         ->assertJsonValidationErrors(['role_id']);
});

test('switch-role rejects a role the caller does not actively hold', function () {
    $person = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT, 'status' => 'Activated']);
    Sanctum::actingAs($person);

    // Holds Student only — never granted Admin.
    RoleAssignment::create([
        'user_id' => $person->user_id,
        'role_id' => SystemUser::ROLE_STUDENT,
        'status'  => RoleAssignment::STATUS_ACTIVE,
    ]);

    $this->postJson('/api/v1/auth/switch-role', ['role_id' => SystemUser::ROLE_ADMIN])
         ->assertStatus(422)
         ->assertJsonValidationErrors(['role_id']);
});

test('switch-role succeeds for a role the caller actively holds, returns the assumed role, and sets a fresh token cookie', function () {
    // Base account is Student; Admin is a second, concurrent grant with
    // a restricted policy — the "student staff" scenario end to end.
    $person = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT, 'status' => 'Activated']);

    RoleAssignment::create([
        'user_id' => $person->user_id,
        'role_id' => SystemUser::ROLE_STUDENT,
        'status'  => RoleAssignment::STATUS_ACTIVE,
    ]);

    RoleAssignment::create([
        'user_id' => $person->user_id,
        'role_id' => SystemUser::ROLE_ADMIN,
        'status'  => RoleAssignment::STATUS_ACTIVE,
    ]);

    // Sanctum::actingAs() bypasses real token issuance, which this test
    // needs (switchTo() deletes/reissues the token the request came in
    // on) — authenticate with a real created token instead, same as a
    // real browser session would present via the 'token' cookie/bearer
    // header.
    $plainTextToken = $person->createToken('sanctum-idp')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$plainTextToken}")
        ->postJson('/api/v1/auth/switch-role', ['role_id' => SystemUser::ROLE_ADMIN]);

    $response->assertOk()
        ->assertJsonPath('user.role_id', SystemUser::ROLE_ADMIN)
        ->assertCookie('token');

    // Old token gone, exactly one new one in its place.
    $person->refresh();
    expect($person->tokens()->count())->toBe(1);
    expect($person->tokens()->first()->name)->toBe('sanctum-idp');

    // The old plaintext token no longer authenticates anything.
    //
    // Laravel's Sanctum guard (RequestGuard) caches the resolved user for
    // the lifetime of the guard instance, and that instance persists
    // across every HTTP call made within this single test method (the
    // app container isn't rebuilt between them). Without forgetting the
    // guard here, this next call would return the user resolved on the
    // switch-role request above instead of re-validating the (now
    // deleted) token against the database — a Laravel/Sanctum testing
    // quirk that never occurs in production, where each request runs in
    // its own process.
    $this->app['auth']->forgetGuards();

    $this->withHeader('Authorization', "Bearer {$plainTextToken}")
        ->getJson('/api/v1/me')
        ->assertStatus(401);
});

test('the tokens cookie issued by switch-role immediately unlocks the newly assumed roles routes', function () {
    $person = SystemUser::factory()->create(['role_id' => SystemUser::ROLE_STUDENT, 'status' => 'Activated']);

    RoleAssignment::create([
        'user_id' => $person->user_id,
        'role_id' => SystemUser::ROLE_STUDENT,
        'status'  => RoleAssignment::STATUS_ACTIVE,
    ]);

    RoleAssignment::create([
        'user_id' => $person->user_id,
        'role_id' => SystemUser::ROLE_SUPER_ADMIN,
        'status'  => RoleAssignment::STATUS_ACTIVE,
    ]);

    $plainTextToken = $person->createToken('sanctum-idp')->plainTextToken;

    // A Student session cannot reach a Super-Admin-only route yet.
    $this->withHeader('Authorization', "Bearer {$plainTextToken}")
        ->getJson('/api/v1/audit-logs')
        ->assertStatus(403);

    // Laravel's Sanctum guard (RequestGuard) caches the resolved user for
    // the lifetime of the guard instance, and that instance persists
    // across every HTTP call made within this single test method. Without
    // forgetting it here, the switch-role request below would reuse the
    // pre-switch (Student) user cached by the /api/v1/audit-logs call above
    // instead of re-resolving from the bearer token — a Laravel/Sanctum
    // testing quirk that never occurs in production.
    $this->app['auth']->forgetGuards();

    $switchResponse = $this->withHeader('Authorization', "Bearer {$plainTextToken}")
        ->postJson('/api/v1/auth/switch-role', ['role_id' => SystemUser::ROLE_SUPER_ADMIN]);

    $switchResponse->assertOk()->assertJsonPath('user.role_id', SystemUser::ROLE_SUPER_ADMIN);

    // 'token' is deliberately unencrypted (see EncryptCookies::$except) —
    // the same plaintext value a real browser would resend automatically
    // as a cookie on its next request.
    $newTokenCookie = collect($switchResponse->headers->getCookies())
        ->first(fn ($cookie) => $cookie->getName() === 'token');

    expect($newTokenCookie)->not->toBeNull();
    expect($newTokenCookie->getValue())->not->toBe($plainTextToken);

    // The freshly-issued cookie authenticates the Super-Admin route the
    // Student-only token above was rejected from.
    //
    // withHeader() sets a DEFAULT header that Laravel's test client keeps
    // attaching to every subsequent request in this method, so the old
    // (now-deleted) bearer token from the calls above is still present
    // here unless explicitly cleared. AuthenticateFromCookie only
    // promotes the 'token' cookie into the Authorization header when no
    // bearer token is already present, so without this the request would
    // keep trying — and failing — to authenticate with the dead old
    // token instead of the fresh cookie.
    $this->app['auth']->forgetGuards();
    $this->withCredentials()
        ->withoutHeader('Authorization')
        ->withUnencryptedCookie('token', $newTokenCookie->getValue())
        ->getJson('/api/v1/audit-logs')
        ->assertOk();

    // And the pre-switch token is dead — switchTo() deleted it.
    $this->app['auth']->forgetGuards();
    $this->withHeader('Authorization', "Bearer {$plainTextToken}")
        ->getJson('/api/v1/me')
        ->assertStatus(401);
});