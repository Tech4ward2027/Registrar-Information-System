<?php

namespace Database\Factories;

use App\Enums\TicketSenderTypeEnum;
use App\Models\SystemUser;
use App\Models\Ticket;
use App\Models\TicketMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TicketMessage>
 */
class TicketMessageFactory extends Factory
{
    protected $model = TicketMessage::class;

    public function definition(): array
    {
        return [
            'ticket_id'      => Ticket::factory(),
            'sender_type'    => TicketSenderTypeEnum::Requester->value,
            'sender_user_id' => SystemUser::factory(),
            'body'           => $this->faker->paragraph(),
        ];
    }

    public function fromStaff(?SystemUser $staff = null): static
    {
        return $this->state(fn () => [
            'sender_type'    => TicketSenderTypeEnum::Staff->value,
            'sender_user_id' => $staff?->user_id
                ?? SystemUser::factory()->create(['role_id' => SystemUser::ROLE_ADMIN])->user_id,
        ]);
    }

    public function fromSystem(): static
    {
        return $this->state(fn () => [
            'sender_type'    => TicketSenderTypeEnum::System->value,
            'sender_user_id' => null,
        ]);
    }
}
