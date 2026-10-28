<?php

namespace Database\Factories;

use App\Enums\TicketPriorityEnum;
use App\Models\TicketCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TicketCategory>
 */
class TicketCategoryFactory extends Factory
{
    protected $model = TicketCategory::class;

    public function definition(): array
    {
        return [
            // CAT-01..CAT-05 already exist from the seeder; factory rows use a
            // distinct code space so they can never collide with real ones.
            'code'        => 'TST-' . str_pad((string) $this->faker->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT),
            'name'        => $this->faker->unique()->words(3, true),
            'description' => $this->faker->sentence(),
            'priority'    => TicketPriorityEnum::Medium->value,
            'is_active'   => true,
            'sort_order'  => 100,
        ];
    }

    public function priority(TicketPriorityEnum $priority): static
    {
        return $this->state(fn () => ['priority' => $priority->value]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
