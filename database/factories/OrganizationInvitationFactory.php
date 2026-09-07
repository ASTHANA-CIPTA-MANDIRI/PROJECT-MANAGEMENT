<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\OrganizationInvitation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<OrganizationInvitation>
 */
class OrganizationInvitationFactory extends Factory
{
    protected $model = OrganizationInvitation::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'role' => 'member',
            'token_hash' => OrganizationInvitation::hashToken(OrganizationInvitation::generateToken()),
            'expires_at' => Carbon::now()->addDays(OrganizationInvitation::LIFETIME_DAYS),
        ];
    }

    /**
     * Phase 5.4.2 backward-compatibility case: an invitation created before
     * the `name` column existed.
     */
    public function withoutName(): static
    {
        return $this->state(fn () => ['name' => null]);
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => Carbon::now()->subDay()]);
    }

    public function accepted(): static
    {
        return $this->state(fn () => ['accepted_at' => Carbon::now()]);
    }

    public function revoked(): static
    {
        return $this->state(fn () => ['revoked_at' => Carbon::now()]);
    }
}
