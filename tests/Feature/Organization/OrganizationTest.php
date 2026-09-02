<?php

namespace Tests\Feature\Organization;

use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_organization_can_be_created(): void
    {
        $organization = Organization::factory()->create(['name' => 'Acme Inc']);

        $this->assertDatabaseHas('organizations', [
            'id' => $organization->id,
            'name' => 'Acme Inc',
        ]);
    }

    public function test_organization_name_must_be_unique(): void
    {
        Organization::factory()->create(['name' => 'Acme Inc']);

        $this->expectException(\Illuminate\Database\QueryException::class);

        Organization::factory()->create(['name' => 'Acme Inc']);
    }

    public function test_organization_has_many_projects(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        Project::factory()->create(); // unrelated project, must not appear

        $this->assertTrue($organization->projects->contains($project));
        $this->assertCount(1, $organization->projects);
    }

    public function test_organization_belongs_to_many_users(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create();

        $organization->users()->attach($user);

        $this->assertTrue($organization->users->contains($user));
    }
}
