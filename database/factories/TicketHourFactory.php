<?php

namespace Database\Factories;

use App\Models\Ticket;
use App\Models\TicketHour;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TicketHour>
 */
class TicketHourFactory extends Factory
{
    protected $model = TicketHour::class;

    public function definition(): array
    {
        return [
            'ticket_id' => Ticket::factory(),
            'user_id' => User::factory(),
            'value' => fake()->randomFloat(2, 0.5, 8),
            'comment' => fake()->sentence(),
            'activity_id' => null,
        ];
    }

    public function hours(float $value): static
    {
        return $this->state(fn() => ['value' => $value]);
    }
}
