<?php

namespace Database\Factories;

use App\Models\SystemUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class SystemUserFactory extends Factory
{
    protected $model = SystemUser::class;

    public function definition(): array
    {
        return [
            'email'    => $this->faker->unique()->safeEmail(),
            'password' => bcrypt('password'),
            'role_id'  => SystemUser::ROLE_STUDENT,
            'status'   => 'Activated',
        ];
    }

    /**
     * Undergrad Requestor Registration — Phase 1 (D3, D4). IDP is the
     * sole authenticator for this role: no password, no idp_user_id
     * until the person's first matched SSO login (Phase 3), and
     * status starts at 'Pending Verification' — exactly the shape
     * UndergradRequestorRegistrationService creates at onboarding
     * submission time (Phase 2).
     */
    public function undergradRequestor(): static
    {
        return $this->state(fn () => [
            'role_id'            => SystemUser::ROLE_UNDERGRAD_REQUESTOR,
            'status'             => 'Pending Verification',
            'password'           => null,
            'idp_user_id'        => null,
            'local_auth_enabled' => 0,
        ]);
    }
}