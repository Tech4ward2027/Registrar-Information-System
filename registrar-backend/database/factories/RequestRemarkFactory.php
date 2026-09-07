<?php

namespace Database\Factories;

use App\Enums\DeficiencyItemEnum;
use App\Models\DocumentRequest;
use App\Models\RequestRemark;
use App\Models\SystemUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Deficiency Notice & Withdrawn Status — Phase 3.
 */
class RequestRemarkFactory extends Factory
{
    protected $model = RequestRemark::class;

    public function definition(): array
    {
        return [
            'request_id'  => DocumentRequest::factory(),
            'remark_type' => 'deficiency',
            'item_key'    => DeficiencyItemEnum::MissingSignature->value,
            'item_label'  => DeficiencyItemEnum::MissingSignature->label(),
            'detail'      => null,
            'status'      => RequestRemark::STATUS_OPEN,
            'issued_by'   => SystemUser::factory(),
            'issued_at'   => now(),
        ];
    }

    public function cleared(): static
    {
        return $this->state(fn () => [
            'status'     => RequestRemark::STATUS_CLEARED,
            'cleared_by' => SystemUser::factory(),
            'cleared_at' => now(),
        ]);
    }

    public function voided(): static
    {
        return $this->state(fn () => [
            'status'      => RequestRemark::STATUS_VOIDED,
            'voided_by'   => SystemUser::factory(),
            'voided_at'   => now(),
            'void_reason' => 'Student unreachable after repeated attempts.',
        ]);
    }

    /**
     * Data Retention & Disposal Policy — Section 3.3. Convenience state
     * for tests exercising already-escalated notices, or a notice aged
     * past the 30-day compliance window before escalation has run.
     */
    public function escalated(): static
    {
        return $this->state(fn () => [
            'escalated_at' => now(),
        ]);
    }
}