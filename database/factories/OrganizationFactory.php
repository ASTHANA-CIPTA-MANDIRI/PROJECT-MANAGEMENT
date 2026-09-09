<?php

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
        ];
    }

    /** Trial still running (Fase 6). */
    public function onTrial(): static
    {
        return $this->state(fn () => ['trial_ends_at' => now()->addDays(7)]);
    }

    /** Trial window has passed and no subscription exists (Fase 6). */
    public function trialExpired(): static
    {
        return $this->state(fn () => ['trial_ends_at' => now()->subDay()]);
    }
}
