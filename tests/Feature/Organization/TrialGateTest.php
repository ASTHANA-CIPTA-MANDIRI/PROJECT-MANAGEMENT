<?php

namespace Tests\Feature\Organization;

use App\Models\Organization;
use App\Support\TrialGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrialGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_organization_with_no_trial_end_date_is_grandfathered_and_active(): void
    {
        $organization = Organization::factory()->create(['trial_ends_at' => null]);

        $this->assertTrue(TrialGate::active($organization));
    }

    public function test_an_organization_still_within_its_trial_window_is_active(): void
    {
        $organization = Organization::factory()->onTrial()->create();

        $this->assertTrue(TrialGate::active($organization));
    }

    public function test_an_organization_past_its_trial_window_and_not_subscribed_is_inactive(): void
    {
        $organization = Organization::factory()->trialExpired()->create();

        $this->assertFalse(TrialGate::active($organization));
    }

    /**
     * Organization::isSubscribed() (Fase 7) is real now — see
     * tests/Feature/Organization/SubscriptionTest.php for its full matrix
     * of active/expired/cancelled/isolation coverage. This class only needs
     * to keep asserting the case TrialGate::active() itself actually
     * depends on: an organization with no subscription row at all (the
     * default for every factory-created Organization in this file) is not
     * subscribed, so a trial-expired organization with none stays locked.
     */
    public function test_an_organization_with_no_subscription_never_reports_subscribed(): void
    {
        $organization = Organization::factory()->trialExpired()->create();

        $this->assertFalse($organization->isSubscribed());
    }
}
