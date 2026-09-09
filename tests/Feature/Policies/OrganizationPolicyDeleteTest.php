<?php

namespace Tests\Feature\Policies;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 6b: OrganizationPolicy::delete() had no dedicated test coverage at
 * all before this phase — the ability existed (used by no UI action yet)
 * but its Owner-vs-Admin behavior was never pinned down.
 */
class OrganizationPolicyDeleteTest extends TestCase
{
    use RefreshDatabase;

    private function memberOf(Organization $organization, string $role): User
    {
        $user = User::factory()->create();
        $organization->users()->attach($user->id, ['role' => $role]);

        return $user;
    }

    public function test_the_owner_can_delete_the_organization(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->memberOf($organization, 'owner');

        $this->assertTrue($owner->can('delete', $organization));
    }

    public function test_an_admin_cannot_delete_the_organization(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->memberOf($organization, 'admin');

        $this->assertFalse($admin->can('delete', $organization));
    }

    public function test_a_plain_member_cannot_delete_the_organization(): void
    {
        $organization = Organization::factory()->create();
        $member = $this->memberOf($organization, 'member');

        $this->assertFalse($member->can('delete', $organization));
    }

    public function test_an_unrelated_user_cannot_delete_the_organization(): void
    {
        $organization = Organization::factory()->create();
        $stranger = User::factory()->create();

        $this->assertFalse($stranger->can('delete', $organization));
    }

    public function test_an_owner_of_another_organization_cannot_delete_this_one(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $ownerElsewhere = $this->memberOf($otherOrganization, 'owner');

        $this->assertFalse($ownerElsewhere->can('delete', $organization));
    }
}
