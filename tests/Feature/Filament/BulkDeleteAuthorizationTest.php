<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ProjectResource\Pages\ListProjects;
use App\Models\Permission;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The per-row delete button runs the model's policy, but DeleteBulkAction was
 * only gated by the "Delete project" permission. Selecting the whole table let
 * a plain project member delete projects the row button refused.
 */
class BulkDeleteAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private ProjectStatus $status;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['List projects', 'View project', 'Delete project'] as $name) {
            Permission::firstOrCreate(['name' => $name]);
        }

        $role = Role::create(['name' => 'Member']);
        $role->syncPermissions(['List projects', 'View project', 'Delete project']);

        $this->user = User::factory()->create();
        $this->user->syncRoles([$role]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->user = $this->user->fresh();

        $this->status = ProjectStatus::factory()->create();

        $this->actingAs($this->user);
    }

    private function project(string $name, string $prefix): Project
    {
        return Project::factory()->create([
            'name' => $name,
            'ticket_prefix' => $prefix,
            'status_id' => $this->status->id,
            'owner_id' => User::factory()->create()->id,
        ]);
    }

    public function test_a_plain_member_cannot_bulk_delete_a_project_they_do_not_manage(): void
    {
        $project = $this->project('Not mine', 'NMI');
        // A member, but not with the "can manage" role: ProjectPolicy::delete
        // refuses, so the row button would be hidden.
        $project->users()->attach($this->user->id, [
            'role' => config('system.projects.affectations.roles.default'),
        ]);

        Livewire::test(ListProjects::class)
            ->callTableBulkAction('delete', [$project]);

        $this->assertNotNull(
            Project::find($project->id),
            'a project the member may not delete must survive a bulk delete'
        );
    }

    public function test_an_owner_can_still_bulk_delete_their_own_project(): void
    {
        $project = $this->project('Mine', 'MIN');
        $project->owner_id = $this->user->id;
        $project->save();
        $project->users()->attach($this->user->id, [
            'role' => config('system.projects.affectations.roles.default'),
        ]);

        Livewire::test(ListProjects::class)
            ->callTableBulkAction('delete', [$project]);

        $this->assertNull(Project::find($project->id));
    }

    public function test_a_mixed_selection_deletes_only_the_allowed_records(): void
    {
        $mine = $this->project('Mine', 'MIN');
        $mine->owner_id = $this->user->id;
        $mine->save();
        $mine->users()->attach($this->user->id, [
            'role' => config('system.projects.affectations.roles.default'),
        ]);

        $theirs = $this->project('Theirs', 'THR');
        $theirs->users()->attach($this->user->id, [
            'role' => config('system.projects.affectations.roles.default'),
        ]);

        Livewire::test(ListProjects::class)
            ->callTableBulkAction('delete', [$mine, $theirs]);

        $this->assertNull(Project::find($mine->id), 'the owned project should be deleted');
        $this->assertNotNull(Project::find($theirs->id), 'the other project must survive');
    }

    public function test_a_project_manager_can_bulk_delete_a_project_they_manage(): void
    {
        $project = $this->project('Managed', 'MAN');
        $project->users()->attach($this->user->id, [
            'role' => config('system.projects.affectations.roles.can_manage'),
        ]);

        Livewire::test(ListProjects::class)
            ->callTableBulkAction('delete', [$project]);

        $this->assertNull(Project::find($project->id));
    }

    // ---------------------------------------- cascade via the Filament UI (M-6)

    /**
     * The bulk delete action (ProjectResource) was given its own ->using()
     * override so a cascading project is deleted atomically there too, not
     * just from the row-level DeleteAction.
     */
    public function test_bulk_deleting_a_project_cascades_to_its_tickets(): void
    {
        $project = $this->project('Mine', 'MIN');
        $project->owner_id = $this->user->id;
        $project->save();
        $project->users()->attach($this->user->id, [
            'role' => config('system.projects.affectations.roles.default'),
        ]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);

        Livewire::test(ListProjects::class)
            ->callTableBulkAction('delete', [$project]);

        $this->assertNull(Project::find($project->id));
        $this->assertNull(Ticket::find($ticket->id));
    }
}
