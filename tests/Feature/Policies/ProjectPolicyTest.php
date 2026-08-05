<?php

namespace Tests\Feature\Policies;

use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\InteractsWithPermissions;
use Tests\TestCase;

class ProjectPolicyTest extends TestCase
{
    use InteractsWithPermissions, RefreshDatabase;

    private function manageRole(): string
    {
        return config('system.projects.affectations.roles.can_manage');
    }

    // ------------------------------------------------------------- viewAny

    public function test_listing_requires_the_list_permission(): void
    {
        $user = $this->userWithPermissions(['List projects']);

        $this->assertTrue($user->can('viewAny', Project::class));
    }

    public function test_listing_is_denied_without_the_permission(): void
    {
        $user = $this->userWithoutPermissions();

        $this->assertFalse($user->can('viewAny', Project::class));
    }

    // ---------------------------------------------------------------- view

    public function test_owner_with_permission_can_view_the_project(): void
    {
        $user = $this->userWithPermissions(['View project']);
        $project = Project::factory()->create(['owner_id' => $user->id]);

        $this->assertTrue($user->can('view', $project));
    }

    public function test_project_member_with_permission_can_view_the_project(): void
    {
        $user = $this->userWithPermissions(['View project']);
        $project = Project::factory()->create();
        $project->users()->attach($user->id, ['role' => 'employee']);

        $this->assertTrue($user->can('view', $project));
    }

    public function test_an_unrelated_user_cannot_view_the_project(): void
    {
        $user = $this->userWithPermissions(['View project']);
        $project = Project::factory()->create();

        $this->assertFalse($user->can('view', $project));
    }

    public function test_the_owner_cannot_view_without_the_permission(): void
    {
        $user = $this->userWithoutPermissions();
        $project = Project::factory()->create(['owner_id' => $user->id]);

        $this->assertFalse($user->can('view', $project));
    }

    // -------------------------------------------------------------- create

    public function test_creating_requires_the_create_permission(): void
    {
        $this->assertTrue($this->userWithPermissions(['Create project'])->can('create', Project::class));
    }

    public function test_creating_is_denied_without_the_permission(): void
    {
        $this->assertFalse($this->userWithoutPermissions()->can('create', Project::class));
    }

    // -------------------------------------------------------------- update

    public function test_owner_with_permission_can_update_the_project(): void
    {
        $user = $this->userWithPermissions(['Update project']);
        $project = Project::factory()->create(['owner_id' => $user->id]);

        $this->assertTrue($user->can('update', $project));
    }

    public function test_a_member_with_the_manage_role_can_update_the_project(): void
    {
        $user = $this->userWithPermissions(['Update project']);
        $project = Project::factory()->create();
        $project->users()->attach($user->id, ['role' => $this->manageRole()]);

        $this->assertTrue($user->can('update', $project));
    }

    public function test_a_plain_member_cannot_update_the_project(): void
    {
        $user = $this->userWithPermissions(['Update project']);
        $project = Project::factory()->create();
        $project->users()->attach($user->id, ['role' => 'employee']);

        $this->assertFalse($user->can('update', $project));
    }

    public function test_an_unrelated_user_cannot_update_the_project(): void
    {
        $user = $this->userWithPermissions(['Update project']);
        $project = Project::factory()->create();

        $this->assertFalse($user->can('update', $project));
    }

    // -------------------------------------------------------------- delete

    public function test_owner_with_permission_can_delete_the_project(): void
    {
        $user = $this->userWithPermissions(['Delete project']);
        $project = Project::factory()->create(['owner_id' => $user->id]);

        $this->assertTrue($user->can('delete', $project));
    }

    public function test_a_member_with_the_manage_role_can_delete_the_project(): void
    {
        $user = $this->userWithPermissions(['Delete project']);
        $project = Project::factory()->create();
        $project->users()->attach($user->id, ['role' => $this->manageRole()]);

        $this->assertTrue($user->can('delete', $project));
    }

    public function test_a_plain_member_cannot_delete_the_project(): void
    {
        $user = $this->userWithPermissions(['Delete project']);
        $project = Project::factory()->create();
        $project->users()->attach($user->id, ['role' => 'employee']);

        $this->assertFalse($user->can('delete', $project));
    }

    public function test_an_unrelated_user_cannot_delete_the_project(): void
    {
        $user = $this->userWithPermissions(['Delete project']);
        $project = Project::factory()->create();

        $this->assertFalse($user->can('delete', $project));
    }

    public function test_deleting_is_denied_without_the_permission(): void
    {
        $user = $this->userWithoutPermissions();
        $project = Project::factory()->create(['owner_id' => $user->id]);

        $this->assertFalse($user->can('delete', $project));
    }

    // ----------------------------------------------------------- deleteAny

    public function test_bulk_deleting_requires_the_delete_permission(): void
    {
        $this->assertTrue($this->userWithPermissions(['Delete project'])->can('deleteAny', Project::class));
    }

    public function test_bulk_deleting_is_denied_without_the_permission(): void
    {
        $this->assertFalse($this->userWithoutPermissions()->can('deleteAny', Project::class));
    }
}
