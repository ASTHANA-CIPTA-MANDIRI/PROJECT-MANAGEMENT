<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'description' => fake()->paragraph(),
            'owner_id' => User::factory(),
            'status_id' => ProjectStatus::factory(),
            // Prefix column is limited to 3 characters and must stay unique.
            'ticket_prefix' => Str::upper(fake()->unique()->lexify('???')),
            'status_type' => 'default',
            'type' => 'kanban',
        ];
    }

    public function scrum(): static
    {
        return $this->state(fn () => ['type' => 'scrum']);
    }

    /**
     * Project that defines its own ticket statuses instead of the global ones.
     */
    public function customStatuses(): static
    {
        return $this->state(fn () => ['status_type' => 'custom']);
    }
}
