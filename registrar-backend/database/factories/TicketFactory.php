<?php

namespace Database\Factories;

use App\Enums\TicketPriorityEnum;
use App\Enums\TicketStatusEnum;
use App\Models\SystemUser;
use App\Models\Ticket;
use App\Models\TicketCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Builds tickets directly in the database. It does NOT go through
 * TicketService, so it records no events and sends no notifications; tests
 * that need the real lifecycle should call the service.
 *
 * Defaults to an authenticated student requester in the Open state. The
 * ticket number is a plausible unique value, not drawn from the real
 * counter, so factory tickets never advance ticket_number_sequences.
 *
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
{
    protected $model = Ticket::class;

    public function definition(): array
    {
        return [
            'ticket_number'     => sprintf('TKT-%d-%05d', now()->year, $this->faker->unique()->numberBetween(1, 99999)),
            'category_id'       => TicketCategory::query()->where('code', 'CAT-05')->value('id')
                ?? TicketCategory::factory(),
            'priority'          => TicketPriorityEnum::Low->value,
            'status'            => TicketStatusEnum::Open->value,
            'subject'           => $this->faker->sentence(5),
            'requester_user_id' => SystemUser::factory(),
            'last_activity_at'  => now(),
        ];
    }

    public function status(TicketStatusEnum $status): static
    {
        return $this->state(fn () => ['status' => $status->value]);
    }

    public function priority(TicketPriorityEnum $priority): static
    {
        return $this->state(fn () => ['priority' => $priority->value]);
    }

    /**
     * A verified public submitter: no user account, a name and an email.
     */
    public function public(): static
    {
        return $this->state(fn () => [
            'requester_user_id' => null,
            'requester_name'    => $this->faker->name(),
            'requester_email'   => strtolower($this->faker->unique()->safeEmail()),
            'requester_ip'      => $this->faker->ipv4(),
        ]);
    }

    public function claimedBy(SystemUser $staff): static
    {
        return $this->state(fn () => [
            'status'             => TicketStatusEnum::InProgress->value,
            'claimed_by_user_id' => $staff->user_id,
            'claimed_at'         => now(),
        ]);
    }

    public function resolved(): static
    {
        return $this->state(fn () => [
            'status'          => TicketStatusEnum::Resolved->value,
            'resolution_note' => $this->faker->sentence(),
            'resolved_at'     => now(),
            'auto_close_at'   => now()->addDays((int) config('tickets.auto_close_days', 3)),
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn () => [
            'status'       => TicketStatusEnum::Closed->value,
            'closed_at'    => now(),
            'close_reason' => Ticket::CLOSE_REASON_CONFIRMED,
        ]);
    }
}
