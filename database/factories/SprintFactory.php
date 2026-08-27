<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\Sprint;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sprint>
 */
class SprintFactory extends Factory
{
    protected $model = Sprint::class;

    public function definition(): array
    {
        $startsAt = now()->startOfDay();

        return [
            'name' => 'Sprint '.fake()->unique()->numberBetween(1, 9999),
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addWeek()->subDay(),
            'description' => fake()->sentence(),
            'project_id' => Project::factory(),
            'started_at' => null,
            'ended_at' => null,
        ];
    }

    /**
     * A sprint that is currently running (started, not yet ended).
     */
    public function started(): static
    {
        return $this->state(fn () => ['started_at' => now()]);
    }

    /**
     * A sprint that has been closed.
     */
    public function ended(): static
    {
        return $this->state(fn () => [
            'started_at' => now()->subWeek(),
            'ended_at' => now(),
        ]);
    }
}
