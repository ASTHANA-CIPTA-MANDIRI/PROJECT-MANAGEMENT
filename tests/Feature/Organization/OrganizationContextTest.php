<?php

namespace Tests\Feature\Organization;

use App\Models\Organization;
use App\Models\User;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_with_no_organization_has_no_current_context(): void
    {
        $user = User::factory()->create();

        $this->assertNull(OrganizationContext::current($user));
    }

    public function test_falls_back_to_first_membership_when_nothing_selected(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create();
        $organization->users()->attach($user);

        $current = OrganizationContext::current($user);

        $this->assertNotNull($current);
        $this->assertTrue($current->is($organization));
    }

    public function test_switch_selects_a_membership_the_user_actually_holds(): void
    {
        $organizationA = Organization::factory()->create();
        $organizationB = Organization::factory()->create();
        $user = User::factory()->create();
        $organizationA->users()->attach($user);
        $organizationB->users()->attach($user);

        $this->assertTrue(OrganizationContext::switch($user, $organizationB->id));
        $this->assertTrue(OrganizationContext::current($user)->is($organizationB));

        $this->assertTrue(OrganizationContext::switch($user, $organizationA->id));
        $this->assertTrue(OrganizationContext::current($user)->is($organizationA));
    }

    public function test_switch_rejects_an_organization_the_user_is_not_a_member_of(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create();

        $this->assertFalse(OrganizationContext::switch($user, $organization->id));
        $this->assertNull(OrganizationContext::current($user));
    }

    public function test_a_stale_session_selection_is_never_trusted_on_its_own(): void
    {
        $organizationA = Organization::factory()->create();
        $organizationB = Organization::factory()->create();
        $user = User::factory()->create();
        $organizationA->users()->attach($user);

        // Select A, then lose membership in A after the fact (e.g. removed
        // from the organization) while B is never joined either.
        OrganizationContext::switch($user, $organizationA->id);
        $organizationA->users()->detach($user);

        $this->assertNull(OrganizationContext::current($user));

        $organizationB->users()->attach($user);
        $this->assertTrue(OrganizationContext::current($user)->is($organizationB));
    }
}
