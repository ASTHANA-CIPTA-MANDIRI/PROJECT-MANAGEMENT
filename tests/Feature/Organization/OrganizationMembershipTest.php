<?php

namespace Tests\Feature\Organization;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationMembershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_become_organization_member(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create();

        $organization->users()->attach($user);

        $this->assertDatabaseHas('organization_users', [
            'organization_id' => $organization->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_duplicate_membership_is_rejected(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create();

        $organization->users()->attach($user);

        $this->expectException(\Illuminate\Database\QueryException::class);

        $organization->users()->attach($user);
    }

    public function test_membership_relationship_works_both_directions(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create();

        $organization->users()->attach($user);

        $this->assertTrue($organization->users->contains($user));
        $this->assertTrue($user->organizations->contains($organization));
    }
}
