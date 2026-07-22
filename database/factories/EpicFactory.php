<?php

namespace Database\Factories;

use App\Models\Epic;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Epic>
 */
class EpicFactory extends Factory
{
    protected $model = Epic::class;

    public function definition(): array
    {
        $startsAt = now()->startOfDay();

        return [
            'name' => fake()->unique()->sentence(3),
            'project_id' => Project::factory(),
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMonth(),
            'parent_id' => null,
        ];
    }
}
