<?php

namespace Tests\Feature\Organization;

use App\Models\Organization;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 7 — App\Models\Organization::isSubscribed() and its backing
 * Subscription model/table. Deliberately does not touch App\Support\TrialGate
 * itself (TrialGateTest already covers the trial_ends_at branches); these
 * tests are only about the "genuinely subscribed" branch TrialGate::active()
 * calls out to.
 */
class SubscriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_organization_with_no_subscription_at_all_is_not_subscribed(): void
    {
        $organization = Organization::factory()->create();

        $this->assertFalse($organization->isSubscribed());
    }

    public function test_an_active_subscription_not_yet_expired_makes_the_organization_subscribed(): void
    {
        $organization = Organization::factory()->create();
        Subscription::factory()->for($organization)->active()->create();

        $this->assertTrue($organization->isSubscribed());
    }

    public function test_an_expired_subscription_does_not_make_the_organization_subscribed(): void
    {
        $organization = Organization::factory()->create();
        Subscription::factory()->for($organization)->expired()->create();

        $this->assertFalse($organization->isSubscribed());
    }

    public function test_a_cancelled_subscription_does_not_make_the_organization_subscribed(): void
    {
        $organization = Organization::factory()->create();
        Subscription::factory()->for($organization)->cancelled()->create();

        $this->assertFalse($organization->isSubscribed());
    }

    /**
     * cancelled_at takes precedence over a still-future ends_at — a
     * subscription cancelled effective immediately must not count as
     * active just because its paid period has not technically run out yet.
     */
    public function test_a_cancelled_subscription_with_a_future_end_date_is_still_not_subscribed(): void
    {
        $organization = Organization::factory()->create();
        Subscription::factory()->for($organization)->create([
            'ends_at' => now()->addMonth(),
            'cancelled_at' => now(),
        ]);

        $this->assertFalse($organization->isSubscribed());
    }

    public function test_a_subscription_with_no_end_date_makes_the_organization_subscribed(): void
    {
        $organization = Organization::factory()->create();
        Subscription::factory()->for($organization)->create([
            'ends_at' => null,
            'cancelled_at' => null,
        ]);

        $this->assertTrue($organization->isSubscribed());
    }

    /**
     * Boundary: ends_at in the past by even one second is expired, matching
     * ->isFuture()'s strict comparison (the same boundary semantics
     * organizations.trial_ends_at already uses in App\Support\TrialGate).
     */
    public function test_a_subscription_one_second_past_its_end_date_is_not_subscribed(): void
    {
        $organization = Organization::factory()->create();
        Subscription::factory()->for($organization)->create([
            'ends_at' => now()->subSecond(),
        ]);

        $this->assertFalse($organization->isSubscribed());
    }

    /**
     * Boundary: ends_at one second in the future is still active.
     */
    public function test_a_subscription_one_second_before_its_end_date_is_subscribed(): void
    {
        $organization = Organization::factory()->create();
        Subscription::factory()->for($organization)->create([
            'ends_at' => now()->addSecond(),
        ]);

        $this->assertTrue($organization->isSubscribed());
    }

    /**
     * Organization isolation (Fase 7, section 10/16-F of the brief):
     * Organization A's active subscription must never make Organization B
     * report subscribed, and B's own (empty) relation must never see A's row.
     */
    public function test_a_subscription_never_leaks_across_organizations(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();

        Subscription::factory()->for($orgA)->active()->create();

        $this->assertTrue($orgA->isSubscribed());
        $this->assertFalse($orgB->isSubscribed());
        $this->assertCount(0, $orgB->subscriptions);
    }

    /**
     * The schema deliberately does not prevent two active subscription rows
     * for the same organization (see this table's migration docblock) —
     * Fase 7 only needs isSubscribed() to still answer correctly (true) when
     * that happens, via a plain `exists()` check rather than assuming
     * exactly zero or one row.
     */
    public function test_multiple_active_subscriptions_for_the_same_organization_still_report_subscribed(): void
    {
        $organization = Organization::factory()->create();
        Subscription::factory()->for($organization)->active()->create();
        Subscription::factory()->for($organization)->active()->create();

        $this->assertTrue($organization->isSubscribed());
        $this->assertCount(2, $organization->subscriptions);
    }

    /**
     * An organization can have both an expired/cancelled subscription
     * (history) and a currently active one — isSubscribed() must be true
     * because at least one row is active, not false because not every row is.
     */
    public function test_an_expired_subscription_alongside_an_active_one_still_reports_subscribed(): void
    {
        $organization = Organization::factory()->create();
        Subscription::factory()->for($organization)->expired()->create();
        Subscription::factory()->for($organization)->active()->create();

        $this->assertTrue($organization->isSubscribed());
    }

    /**
     * Deleting the Organization cascades to its subscription rows
     * (subscriptions.organization_id is cascadeOnDelete) — no orphaned
     * subscription can outlive its organization, and nothing throws when
     * isSubscribed() is not called again afterward.
     */
    public function test_deleting_an_organization_cascades_to_its_subscriptions(): void
    {
        $organization = Organization::factory()->create();
        $subscription = Subscription::factory()->for($organization)->active()->create();

        $organization->delete();

        $this->assertDatabaseMissing('subscriptions', ['id' => $subscription->id]);
    }

    public function test_organization_has_many_subscriptions(): void
    {
        $organization = Organization::factory()->create();
        $subscription = Subscription::factory()->for($organization)->create();
        Subscription::factory()->create(); // unrelated subscription, must not appear

        $this->assertTrue($organization->subscriptions->contains($subscription));
        $this->assertCount(1, $organization->subscriptions);
    }
}
