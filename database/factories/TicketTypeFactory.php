<?php

namespace Database\Factories;

use App\Models\TicketType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TicketType>
 */
class TicketTypeFactory extends Factory
{
    protected $model = TicketType::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word(),
            'icon' => 'heroicon-o-check-circle',
            'color' => fake()->hexColor(),
            'is_default' => false,
        ];
    }

    public function default(): static
    {
        return $this->state(fn() => ['is_default' => true]);
    }
}
