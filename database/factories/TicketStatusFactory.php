<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\TicketStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TicketStatus>
 */
class TicketStatusFactory extends Factory
{
    protected $model = TicketStatus::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word(),
            'color' => fake()->hexColor(),
            'is_default' => false,
            'is_final' => false,
            'order' => 0,
            // Null means a global status shared by all "default" projects.
            'project_id' => null,
        ];
    }

    public function default(): static
    {
        return $this->state(fn () => ['is_default' => true]);
    }

    /**
     * A status considered "done" — tickets in it should stop receiving
     * due-date reminders.
     */
    public function final(): static
    {
        return $this->state(fn () => ['is_final' => true]);
    }

    /**
     * A status that belongs to one specific project (custom status config).
     */
    public function forProject(Project $project): static
    {
        return $this->state(fn () => ['project_id' => $project->id]);
    }
}
