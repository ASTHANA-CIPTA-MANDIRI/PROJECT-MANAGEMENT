<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Board;
use App\Filament\Pages\Kanban;
use App\Filament\Pages\RoadMap;
use App\Filament\Pages\Scrum;
use App\Models\Permission;
use App\Models\Project;
use App\Models\Role;
use App\Models\Sprint;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The board pages carry the heaviest custom logic in the panel: they build
 * their columns through KanbanScrumHelper. Rendering them with real projects
 * exercises that grouping code end to end.
 */
class CustomPagesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $names = [
            'List projects', 'View project', 'Update project',
            'List tickets', 'View ticket', 'Create ticket', 'Update ticket',
            'List sprints', 'View sprint', 'Create sprint', 'Update sprint',
        ];
        foreach ($names as $name) {
            Permission::firstOrCreate(['name' => $name]);
        }
        $role = Role::create(['name' => 'Member']);
        $role->syncPermissions($names);

        $this->user = User::factory()->create();
        $this->user->syncRoles([$role]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->user = $this->user->fresh();

        $this->actingAs($this->user);
    }

    private function kanbanProject(): Project
    {
        return Project::factory()->create(['owner_id' => $this->user->id]);
    }

    private function scrumProject(): Project
    {
        return Project::factory()->scrum()->create(['owner_id' => $this->user->id]);
    }

    // ---------------------------------------------------------------- kanban

    public function test_the_kanban_board_renders_for_a_kanban_project(): void
    {
        $project = $this->kanbanProject();

        Livewire::test(Kanban::class, ['project' => $project])->assertSuccessful();
    }

    public function test_the_kanban_board_renders_with_tickets(): void
    {
        $project = $this->kanbanProject();
        Ticket::factory()->count(5)->create([
            'project_id' => $project->id,
            'owner_id' => $this->user->id,
        ]);

        Livewire::test(Kanban::class, ['project' => $project])->assertSuccessful();
    }

    public function test_the_kanban_board_renders_for_an_empty_project(): void
    {
        Livewire::test(Kanban::class, ['project' => $this->kanbanProject()])
            ->assertSuccessful();
    }

    public function test_a_project_member_can_open_the_kanban_board(): void
    {
        $project = Project::factory()->create();
        $project->users()->attach($this->user->id, ['role' => 'employee']);

        Livewire::test(Kanban::class, ['project' => $project])->assertSuccessful();
    }

    public function test_the_kanban_board_subscribes_to_live_project_updates(): void
    {
        $project = $this->kanbanProject();

        $listeners = Livewire::test(Kanban::class, ['project' => $project])
            ->instance()
            ->getListeners();

        $this->assertArrayHasKey("echo-private:project.{$project->id},.ticket.status.changed", $listeners);
        $this->assertArrayHasKey("echo-private:project.{$project->id},.ticket.comment.posted", $listeners);
        $this->assertSame('refreshBoard', $listeners["echo-private:project.{$project->id},.ticket.status.changed"]);
    }

    public function test_a_live_broadcast_refreshes_the_kanban_board(): void
    {
        $project = $this->kanbanProject();

        Livewire::test(Kanban::class, ['project' => $project])
            ->call('refreshBoard')
            ->assertSuccessful();
    }

    // ----------------------------------------------------------------- scrum

    public function test_the_scrum_board_renders_for_a_scrum_project(): void
    {
        $project = $this->scrumProject();

        Livewire::test(Scrum::class, ['project' => $project])->assertSuccessful();
    }

    public function test_the_scrum_board_renders_with_a_running_sprint(): void
    {
        $project = $this->scrumProject();
        $sprint = Sprint::factory()->started()->create(['project_id' => $project->id]);
        Ticket::factory()->count(3)->create([
            'project_id' => $project->id,
            'sprint_id' => $sprint->id,
            'owner_id' => $this->user->id,
        ]);

        Livewire::test(Scrum::class, ['project' => $project])->assertSuccessful();
    }

    public function test_the_scrum_board_renders_without_any_sprint(): void
    {
        Livewire::test(Scrum::class, ['project' => $this->scrumProject()])
            ->assertSuccessful();
    }

    // -------------------------------------------------------------- road map

    public function test_the_road_map_page_renders(): void
    {
        Livewire::test(RoadMap::class)->assertSuccessful();
    }

    public function test_the_road_map_renders_with_projects_and_sprints(): void
    {
        $project = $this->scrumProject();
        Sprint::factory()->count(2)->create(['project_id' => $project->id]);

        Livewire::test(RoadMap::class)->assertSuccessful();
    }

    // ----------------------------------------------------------------- board

    public function test_the_board_page_renders(): void
    {
        Livewire::test(Board::class)->assertSuccessful();
    }

    public function test_the_board_page_renders_with_projects(): void
    {
        $this->kanbanProject();
        $this->scrumProject();

        Livewire::test(Board::class)->assertSuccessful();
    }
}
