<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\Organization;
use App\Support\SubscriptionPlans;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    /**
     * Base state: a freshly created, still-pending invoice for the
     * "monthly" plan - mirrors what
     * App\Services\SubscriptionCheckoutService itself writes before it
     * ever calls DOKU, doku_request_id included (checkout always sets it
     * in the same transaction that creates the row).
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'subscription_id' => null,
            'invoice_number' => 'INV-'.now()->format('Ymd').'-'.strtoupper(Str::random(10)),
            'plan_code' => 'monthly',
            // Snapshot columns follow plan_code (resolved lazily so
            // ->create(['plan_code' => 'yearly']) gets the yearly snapshot).
            'duration_days' => fn (array $attributes) => SubscriptionPlans::find($attributes['plan_code'])['duration_days'] ?? null,
            'plan_name' => fn (array $attributes) => SubscriptionPlans::find($attributes['plan_code'])['name'] ?? null,
            'amount' => 150000,
            'currency' => 'IDR',
            'status' => Invoice::STATUS_PENDING,
            'doku_request_id' => (string) Str::uuid(),
            'doku_reference' => null,
            'doku_token_id' => (string) Str::uuid(),
            'doku_payment_url' => 'https://api-sandbox.doku.com/checkout/v1/session/'.Str::random(20),
            'expires_at' => now()->addHour(),
            'paid_at' => null,
        ];
    }

    /**
     * A row from before the snapshot columns existed: activation must fall
     * back to the config lookup for it.
     */
    public function withoutPlanSnapshot(): static
    {
        return $this->state(fn () => [
            'duration_days' => null,
            'plan_name' => null,
        ]);
    }

    public function paid(): static
    {
        return $this->state(fn () => [
            'status' => Invoice::STATUS_PAID,
            'paid_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => Invoice::STATUS_FAILED,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'status' => Invoice::STATUS_EXPIRED,
            'expires_at' => now()->subHour(),
        ]);
    }
}
