<?php

namespace Tests\Feature\Policies;

use App\Models\Project;
use App\Models\Sprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\InteractsWithPermissions;
use Tests\TestCase;

class SprintPolicyTest extends TestCase
{
    use InteractsWithPermissions, RefreshDatabase;

    private function manageRole(): string
    {
        return config('system.projects.affectations.roles.can_manage');
    }

    public function test_listing_requires_the_list_permission(): void
    {
        $this->assertTrue($this->userWithPermissions(['List sprints'])->can('viewAny', Sprint::class));
    }

    public function test_listing_is_denied_without_the_permission(): void
    {
        $this->assertFalse($this->userWithoutPermissions()->can('viewAny', Sprint::class));
    }

    public function test_the_project_owner_can_view_the_sprint(): void
    {
        $user = $this->userWithPermissions(['View sprint']);
        $project = Project::factory()->create(['owner_id' => $user->id]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);

        $this->assertTrue($user->can('view', $sprint));
    }

    public function test_a_project_member_can_view_the_sprint(): void
    {
        $user = $this->userWithPermissions(['View sprint']);
        $project = Project::factory()->create();
        $project->users()->attach($user->id, ['role' => 'employee']);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);

        $this->assertTrue($user->can('view', $sprint));
    }

    public function test_an_unrelated_user_cannot_view_the_sprint(): void
    {
        $user = $this->userWithPermissions(['View sprint']);
        $sprint = Sprint::factory()->create();

        $this->assertFalse($user->can('view', $sprint));
    }

    public function test_creating_requires_the_create_permission(): void
    {
        $this->assertTrue($this->userWithPermissions(['Create sprint'])->can('create', Sprint::class));
    }

    public function test_creating_is_denied_without_the_permission(): void
    {
        $this->assertFalse($this->userWithoutPermissions()->can('create', Sprint::class));
    }

    public function test_the_project_owner_can_update_the_sprint(): void
    {
        $user = $this->userWithPermissions(['Update sprint']);
        $project = Project::factory()->create(['owner_id' => $user->id]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);

        $this->assertTrue($user->can('update', $sprint));
    }

    public function test_a_member_with_the_manage_role_can_update_the_sprint(): void
    {
        $user = $this->userWithPermissions(['Update sprint']);
        $project = Project::factory()->create();
        $project->users()->attach($user->id, ['role' => $this->manageRole()]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);

        $this->assertTrue($user->can('update', $sprint));
    }

    public function test_a_plain_member_cannot_update_the_sprint(): void
    {
        $user = $this->userWithPermissions(['Update sprint']);
        $project = Project::factory()->create();
        $project->users()->attach($user->id, ['role' => 'employee']);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);

        $this->assertFalse($user->can('update', $sprint));
    }

    public function test_the_project_owner_can_delete_the_sprint(): void
    {
        $user = $this->userWithPermissions(['Delete sprint']);
        $project = Project::factory()->create(['owner_id' => $user->id]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);

        $this->assertTrue($user->can('delete', $sprint));
    }

    public function test_a_member_with_the_manage_role_can_delete_the_sprint(): void
    {
        $user = $this->userWithPermissions(['Delete sprint']);
        $project = Project::factory()->create();
        $project->users()->attach($user->id, ['role' => $this->manageRole()]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);

        $this->assertTrue($user->can('delete', $sprint));
    }

    public function test_a_plain_member_cannot_delete_the_sprint(): void
    {
        $user = $this->userWithPermissions(['Delete sprint']);
        $project = Project::factory()->create();
        $project->users()->attach($user->id, ['role' => 'employee']);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);

        $this->assertFalse($user->can('delete', $sprint));
    }

    public function test_an_unrelated_user_cannot_delete_the_sprint(): void
    {
        $user = $this->userWithPermissions(['Delete sprint']);
        $sprint = Sprint::factory()->create();

        $this->assertFalse($user->can('delete', $sprint));
    }

    public function test_deleting_is_denied_without_the_permission(): void
    {
        $user = $this->userWithoutPermissions();
        $project = Project::factory()->create(['owner_id' => $user->id]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);

        $this->assertFalse($user->can('delete', $sprint));
    }

    // ----------------------------------------------------------- deleteAny

    public function test_bulk_deleting_requires_the_delete_permission(): void
    {
        $this->assertTrue($this->userWithPermissions(['Delete sprint'])->can('deleteAny', Sprint::class));
    }

    public function test_bulk_deleting_is_denied_without_the_permission(): void
    {
        $this->assertFalse($this->userWithoutPermissions()->can('deleteAny', Sprint::class));
    }
}
