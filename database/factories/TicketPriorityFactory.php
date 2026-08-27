<?php

namespace Database\Factories;

use App\Models\TicketPriority;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TicketPriority>
 */
class TicketPriorityFactory extends Factory
{
    protected $model = TicketPriority::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word(),
            'color' => fake()->hexColor(),
            'is_default' => false,
        ];
    }

    public function default(): static
    {
        return $this->state(fn () => ['is_default' => true]);
    }
}
