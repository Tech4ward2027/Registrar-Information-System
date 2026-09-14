<?php

namespace Database\Factories;

use App\Enums\UndergradRequestorVerificationStatusEnum;
use App\Models\SystemUser;
use App\Models\UndergradRequestorVerification;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Undergrad Requestor Registration — Phase 1 (D6, D8).
 */
class UndergradRequestorVerificationFactory extends Factory
{
    protected $model = UndergradRequestorVerification::class;

    public function definition(): array
    {
        return [
            'user_id'                    => SystemUser::factory()->undergradRequestor(),
            'status'                     => UndergradRequestorVerificationStatusEnum::Pending,
            'local_match_found'          => false,
            'matched_student_profile_id' => null,
            'ogos_lookup_performed_at'   => null,
            'ogos_match_found'           => null,
            'reviewed_by'                => null,
            'reviewed_at'                => null,
            'rejection_reason'           => null,
        ];
    }

    /**
     * An Admin-approved verification — unlocks that account's SSO
     * auto-activation (Phase 3) and request-flow access (Phase 5).
     */
    public function approved(): static
    {
        return $this->state(fn () => [
            'status'      => UndergradRequestorVerificationStatusEnum::Approved,
            'reviewed_by' => SystemUser::factory(),
            'reviewed_at' => now(),
        ]);
    }

    /**
     * An Admin-rejected verification — requires rejection_reason (D8);
     * triggers AccountRejectedException + IdP token revocation on that
     * account's next login attempt (Phase 3).
     */
    public function rejected(): static
    {
        return $this->state(fn () => [
            'status'           => UndergradRequestorVerificationStatusEnum::Rejected,
            'reviewed_by'      => SystemUser::factory(),
            'reviewed_at'      => now(),
            'rejection_reason' => 'Declared student number could not be matched to any historical or current record.',
        ]);
    }

    /**
     * D6 — the live OGOS advisory check ran and found the person
     * currently active, a misclassification/fraud signal worth
     * surfacing to the reviewing Admin (not a confirmation of
     * eligibility either way).
     */
    public function ogosMatchFound(): static
    {
        return $this->state(fn () => [
            'ogos_lookup_performed_at' => now(),
            'ogos_match_found'         => true,
        ]);
    }

    /**
     * D6 — the live OGOS advisory check ran and found no match, the
     * expected/meaningless case for this population (OGOS only
     * indexes currently-enrolled students).
     */
    public function ogosLookupPerformedNoMatch(): static
    {
        return $this->state(fn () => [
            'ogos_lookup_performed_at' => now(),
            'ogos_match_found'         => false,
        ]);
    }
}
