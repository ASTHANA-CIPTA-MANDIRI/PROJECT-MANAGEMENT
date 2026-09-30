<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    /**
     * Base state: started a day ago, no end date (unlimited), not
     * cancelled — already active by Subscription::isActive()'s own
     * definition. active()/expired()/cancelled() below exist for tests
     * that need to be explicit about which condition they are exercising
     * rather than relying on this default.
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'starts_at' => now()->subDay(),
            'ends_at' => null,
            'cancelled_at' => null,
        ];
    }

    /** Currently paid through a future end date (Fase 7). */
    public function active(): static
    {
        return $this->state(fn () => [
            'ends_at' => now()->addMonth(),
            'cancelled_at' => null,
        ]);
    }

    /** Its paid period has already passed, and it was never cancelled (Fase 7). */
    public function expired(): static
    {
        return $this->state(fn () => [
            'ends_at' => now()->subDay(),
            'cancelled_at' => null,
        ]);
    }

    /** Cancelled, regardless of whether its paid period has run out yet (Fase 7). */
    public function cancelled(): static
    {
        return $this->state(fn () => [
            'cancelled_at' => now(),
        ]);
    }
}
