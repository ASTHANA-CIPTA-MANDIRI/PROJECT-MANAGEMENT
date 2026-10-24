<?php

namespace Tests\Feature\Billing;

use App\Models\Organization;
use App\Models\User;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * POST /organizations/subscribe honours subscriptions.checkout_enabled itself:
 * hiding the buy buttons is UX, the endpoint is the boundary. The behaviour
 * with checkout on is covered by SubscriptionCheckoutTest.
 */
class SubscriptionCheckoutGateTest extends TestCase
{
    use RefreshDatabase;

    private function owner(Organization $organization): User
    {
        $user = User::factory()->create();
        $organization->users()->attach($user->id, ['role' => 'owner']);
        OrganizationContext::switch($user, $organization->id);

        return $user;
    }

    public function test_with_checkout_disabled_the_endpoint_is_a_404_and_creates_no_invoice_nor_calls_doku(): void
    {
        config(['subscriptions.checkout_enabled' => false]);
        Http::fake();
        $owner = $this->owner(Organization::factory()->create());

        $this->actingAs($owner)->post('/organizations/subscribe', ['plan' => 'monthly'])->assertNotFound();
        // Even a request that would fail validation gets the 404, not a validation error.
        $this->actingAs($owner)->post('/organizations/subscribe', ['plan' => 'nope'])->assertNotFound();

        $this->assertDatabaseCount('invoices', 0);
        Http::assertNothingSent();
    }

    public function test_with_checkout_enabled_the_old_behaviour_is_unchanged(): void
    {
        config(['subscriptions.checkout_enabled' => true]);
        $owner = $this->owner(Organization::factory()->create());

        $this->actingAs($owner)->post('/organizations/subscribe', ['plan' => 'nope'])->assertSessionHasErrors('plan');
    }
}
