<?php

namespace Database\Factories;

use App\Models\SystemUser;
use App\Models\UndergradRequestorProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Undergrad Requestor Registration — Phase 1.
 */
class UndergradRequestorProfileFactory extends Factory
{
    protected $model = UndergradRequestorProfile::class;

    public function definition(): array
    {
        return [
            'user_id'                    => SystemUser::factory()->undergradRequestor(),
            'first_name'                 => $this->faker->firstName(),
            'middle_name'                => $this->faker->optional()->lastName(),
            'last_name'                  => $this->faker->lastName(),
            'suffix'                     => null,
            'student_number'             => $this->faker->unique()->numerify('####-#####-MN-#'),
            'program'                    => $this->faker->randomElement([
                'BS Information Technology',
                'BS Computer Science',
                'BS Business Administration',
                'BS Accountancy',
            ]),
            'last_school_year_attended'  => $this->faker->randomElement(['2019-2020', '2020-2021', '2021-2022', '2022-2023']),
            'date_of_birth'              => $this->faker->date('Y-m-d', '-18 years'),
            'present_address'            => $this->faker->address(),
            'reason_for_non_enrollment'  => null,
            'phone'                      => $this->faker->numerify('09#########'),
            'email_verified_at'          => null,

            // Phase 6 (RA 10173). The default is a CONSENTED submission,
            // because that is the only kind the onboarding form can
            // produce since Phase 6 made consent mandatory — a factory
            // whose default state is unreachable in production is a
            // factory that quietly stops testing production.
            'data_privacy_consent_at'      => now(),
            'data_privacy_consent_version' => (string) config('undergrad_requestor.data_privacy.consent_version', '1.0'),
            'data_privacy_consent_ip'      => $this->faker->ipv4(),
        ];
    }

    /**
     * Phase 6 — a submission with NO recorded Data Privacy Act consent.
     *
     * Only reachable for rows created before the consent columns
     * existed. Kept as an explicit state so the Admin detail view's
     * "consent not recorded" flag has something to be tested against;
     * without it, that branch would be dead code nobody ever exercises
     * until it appears in front of a reviewer for the first time in
     * production.
     */
    public function withoutDataPrivacyConsent(): static
    {
        return $this->state(fn () => [
            'data_privacy_consent_at'      => null,
            'data_privacy_consent_version' => null,
            'data_privacy_consent_ip'      => null,
        ]);
    }

    /**
     * Phase 2/D10 — a submission whose confirmation-link email has
     * already been confirmed, i.e. eligible to appear in the Admin
     * verification queue (Phase 4).
     */
    public function emailVerified(): static
    {
        return $this->state(fn () => [
            'email_verified_at' => now(),
        ]);
    }

    /**
     * A submission that declared a reason for not currently being
     * enrolled — the one genuinely optional free-text field on the
     * onboarding form.
     */
    public function withReasonForNonEnrollment(): static
    {
        return $this->state(fn () => [
            'reason_for_non_enrollment' => $this->faker->sentence(),
        ]);
    }
}