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
        $mock->shouldReceive('sendToAllExcept')
            ->once()
            ->withArgs(function ($excludedRoleIds, $triggerEvent, $data) use ($user) {
                // Must exclude student/alumni, but must NOT exclude admin or
                // super admin — otherwise the Super Admin who owns this
                // break-glass account would never see the alert (see
                // LocalAuthController::login() for why sendToAdmins() alone
                // would be wrong here).
                return $triggerEvent === 'local_auth_login_used'
                    && in_array(SystemUser::ROLE_STUDENT, $excludedRoleIds, true)
                    && in_array(SystemUser::ROLE_ALUMNI, $excludedRoleIds, true)
                    && !in_array(SystemUser::ROLE_ADMIN, $excludedRoleIds, true)
                    && !in_array(SystemUser::ROLE_SUPER_ADMIN, $excludedRoleIds, true)
                    && $data['user_id'] === $user->user_id
                    && $data['email'] === $user->email
                    && array_key_exists('ip', $data);
            });
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