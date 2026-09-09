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
     * Organization::isSubscribed() is a Fase 7 stub that always returns
     * false today. Asserting that here too would just be re-testing a
     * hardcoded `return false;` — the real coverage this class needs is
     * the trial_ends_at branch above; the subscribed branch becomes
     * meaningful (and testable for real) once Fase 7 gives it a body.
     */
    public function test_an_organization_never_reports_subscribed_before_fase_7_exists(): void
    {
        $organization = Organization::factory()->trialExpired()->create();

        $this->assertFalse($organization->isSubscribed());
    }
}
