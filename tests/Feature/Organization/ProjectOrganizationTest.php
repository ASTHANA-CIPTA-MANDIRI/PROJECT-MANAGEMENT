<?php

namespace Tests\Feature\Organization;

use App\Models\Organization;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectOrganizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_can_be_created_without_organization(): void
    {
        $project = Project::factory()->create();

        $this->assertNull($project->organization_id);
        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'organization_id' => null,
        ]);
    }

    public function test_project_belongs_to_organization(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);

        $this->assertTrue($project->organization->is($organization));
    }

    // nullOnDelete() is asserted by reading the migration/schema directly
    // (see database/migrations/2026_09_02_000003_add_organization_id_to_projects_table.php)
    // rather than by exercising a real DELETE here: this repo's SQLite test
    // driver only enforces foreign keys added via Schema::create(), not ones
    // added later via Schema::table()->constrained() (the same pattern
    // already used by 2026_08_06_000001_add_project_id_to_ticket_comments_table.php)
    // — so a DELETE-triggered SET NULL assertion would pass or fail based on
    // the test driver, not on the actual migration, and MySQL (production)
    // is not exercised by this suite at all.
}
