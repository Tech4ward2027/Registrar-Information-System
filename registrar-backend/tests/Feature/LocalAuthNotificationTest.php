<?php

use App\Contracts\NotificationServiceInterface;
use App\Models\SystemUser;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ═════════════════════════════════════════════════════════════════════════════
// LocalAuthController::login() — admin-facing notification on success
// ═════════════════════════════════════════════════════════════════════════════

test('a successful local (break-glass) login fires an admin-facing notification', function () {
    $user = SystemUser::factory()->create([
        'role_id'             => SystemUser::ROLE_SUPER_ADMIN,
        'status'              => 'Activated',
        'password'            => bcrypt('CorrectPass1'),
        'local_auth_enabled'  => 1,
    ]);

    $this->mock(NotificationServiceInterface::class, function ($mock) use ($user) {
        // Allow-list, not an exclusion list: only Super Admin receives the
        // alert (it discloses an account email + source IP), and a role
        // added later is never included by default.
        $mock->shouldReceive('sendToRoles')
            ->once()
            ->withArgs(function ($roleIds, $triggerEvent, $data) use ($user) {
                return $triggerEvent === 'local_auth_login_used'
                    && $roleIds === [SystemUser::ROLE_SUPER_ADMIN]
                    && $data['user_id'] === $user->user_id
                    && $data['email'] === $user->email
                    && array_key_exists('ip', $data);
            });
        $mock->shouldReceive('sendToAllExcept')->never();
    });

    $this->postJson('/api/v1/auth/local-login', [
        'email'    => $user->email,
        'password' => 'CorrectPass1',
    ])->assertOk();
});

test('a failed local login does not fire the break-glass notification', function () {
    $user = SystemUser::factory()->create([
        'role_id'             => SystemUser::ROLE_SUPER_ADMIN,
        'status'              => 'Activated',
        'password'            => bcrypt('CorrectPass1'),
        'local_auth_enabled'  => 1,
    ]);

    $this->mock(NotificationServiceInterface::class, function ($mock) {
        $mock->shouldReceive('sendToAllExcept')->never();
    });

    $this->postJson('/api/v1/auth/local-login', [
        'email'    => $user->email,
        'password' => 'WrongPassword1',
    ])->assertStatus(401);
});