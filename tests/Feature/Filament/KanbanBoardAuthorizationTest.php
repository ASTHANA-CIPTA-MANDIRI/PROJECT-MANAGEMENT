<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Kanban;
use App\Filament\Pages\Scrum;
use App\Models\Permission;
use App\Models\Project;
use App\Models\Role;
use App\Models\Sprint;
use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * recordUpdated() is a public Livewire listener: the ticket id, the new index
 * and the target status all arrive from the browser. These tests pin down that
 * a board only ever moves its own cards, and only for users the ticket policy
 * accepts.
 */
class KanbanBoardAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->user = $this->userWith([
            'List tickets', 'View ticket', 'Create ticket', 'Update ticket',
        ]);
        $this->actingAs($this->user);
    }

    private function userWith(array $permissions): User
    {
        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name]);
        }
        $role = Role::create(['name' => 'Role '.Role::count()]);
        $role->syncPermissions($permissions);

        $user = User::factory()->create();
        $user->syncRoles([$role]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    public function test_a_ticket_from_another_project_cannot_be_moved(): void
    {
        $ownProject = Project::factory()->create(['owner_id' => $this->user->id]);

        $victimProject = Project::factory()->create();
        $victimTicket = Ticket::factory()->create(['project_id' => $victimProject->id]);
        $originalStatus = $victimTicket->status_id;

        $target = TicketStatus::factory()->create();

        Livewire::test(Kanban::class, ['project' => $ownProject])
            ->call('recordUpdated', $victimTicket->id, 7, $target->id)
            ->assertSuccessful();

        $this->assertSame($originalStatus, $victimTicket->fresh()->status_id);
        $this->assertDatabaseCount('ticket_activities', 0);
    }

    public function test_a_ticket_cannot_be_moved_to_a_status_outside_the_board(): void
    {
        $project = Project::factory()->create(['owner_id' => $this->user->id]);
        $ticket = Ticket::factory()->create([
            'project_id' => $project->id,
            'owner_id' => $this->user->id,
        ]);
        $originalStatus = $ticket->status_id;

        // Belongs to another project's custom status set, so it is not a
        // column of this board.
        $foreignStatus = TicketStatus::factory()
            ->forProject(Project::factory()->customStatuses()->create())
            ->create();

        Livewire::test(Kanban::class, ['project' => $project])
            ->call('recordUpdated', $ticket->id, 0, $foreignStatus->id)
            ->assertSuccessful();

        $this->assertSame($originalStatus, $ticket->fresh()->status_id);
    }

    public function test_a_project_member_without_the_update_permission_cannot_move_a_ticket(): void
    {
        $viewer = $this->userWith(['List tickets', 'View ticket']);

        $project = Project::factory()->create(['owner_id' => $this->user->id]);
        $project->users()->attach($viewer->id, ['role' => 'employee']);

        $ticket = Ticket::factory()->create([
            'project_id' => $project->id,
            'owner_id' => $this->user->id,
        ]);
        $originalStatus = $ticket->status_id;
        $target = TicketStatus::factory()->create();

        $this->actingAs($viewer);

        Livewire::test(Kanban::class, ['project' => $project])
            ->call('recordUpdated', $ticket->id, 1, $target->id)
            ->assertSuccessful();

        $this->assertSame($originalStatus, $ticket->fresh()->status_id);
    }

    public function test_a_ticket_outside_the_current_sprint_cannot_be_moved_on_the_scrum_board(): void
    {
        $project = Project::factory()->scrum()->create(['owner_id' => $this->user->id]);
        $currentSprint = Sprint::factory()->started()->create(['project_id' => $project->id]);
        $backlogTicket = Ticket::factory()->create([
            'project_id' => $project->id,
            'owner_id' => $this->user->id,
            'sprint_id' => null,
        ]);
        $originalStatus = $backlogTicket->status_id;
        $target = TicketStatus::factory()->create();

        $this->assertTrue($currentSprint->is($project->fresh()->currentSprint));

        Livewire::test(Scrum::class, ['project' => $project])
            ->call('recordUpdated', $backlogTicket->id, 0, $target->id)
            ->assertSuccessful();

        $this->assertSame($originalStatus, $backlogTicket->fresh()->status_id);
    }

    public function test_nothing_can_be_moved_on_a_scrum_board_between_sprints(): void
    {
        // No sprint running: the board has no cards at all, so not even a
        // backlog ticket may be dragged. This listener and the refresh action
        // are reachable whatever the template chooses to show.
        $project = Project::factory()->scrum()->create(['owner_id' => $this->user->id]);
        Sprint::factory()->ended()->create(['project_id' => $project->id]);
        $ticket = Ticket::factory()->create([
            'project_id' => $project->id,
            'owner_id' => $this->user->id,
            'sprint_id' => null,
        ]);
        $originalStatus = $ticket->status_id;
        $target = TicketStatus::factory()->create();

        $this->assertNull($project->fresh()->currentSprint);

        Livewire::test(Scrum::class, ['project' => $project])
            ->call('recordUpdated', $ticket->id, 0, $target->id)
            ->assertSuccessful()
            ->call('filter')
            ->assertSuccessful();

        $this->assertSame($originalStatus, $ticket->fresh()->status_id);
    }

    public function test_the_project_owner_can_still_move_a_ticket_on_their_own_board(): void
    {
        $project = Project::factory()->create(['owner_id' => $this->user->id]);
        $ticket = Ticket::factory()->create([
            'project_id' => $project->id,
            'owner_id' => $this->user->id,
        ]);
        $target = TicketStatus::factory()->create();

        Livewire::test(Kanban::class, ['project' => $project])
            ->call('recordUpdated', $ticket->id, 3, $target->id)
            ->assertSuccessful();

        $ticket->refresh();
        $this->assertSame($target->id, $ticket->status_id);
        // The target column is renumbered 0..n, and this is its only card, so
        // the index the browser reported (3) settles at 0.
        $this->assertSame(0, $ticket->order);
    }

    // ------------------------------------------- mounting the wrong board

    public function test_a_scrum_project_opened_on_the_kanban_board_redirects(): void
    {
        $project = Project::factory()->scrum()->create(['owner_id' => $this->user->id]);

        Livewire::test(Kanban::class, ['project' => $project])
            ->assertRedirect(route('filament.pages.scrum/{project}', ['project' => $project]));
    }

    public function test_a_kanban_project_opened_on_the_scrum_board_redirects(): void
    {
        $project = Project::factory()->create(['owner_id' => $this->user->id]);

        Livewire::test(Scrum::class, ['project' => $project])
            ->assertRedirect(route('filament.pages.kanban/{project}', ['project' => $project]));
    }

    /**
     * The redirect used to be issued without `return`, so mount() ran on to
     * fill the form of the board the project does not use, and the access
     * check in the elseif branch never ran at all: a stranger got a redirect
     * instead of a 403.
     */
    public function test_a_stranger_is_forbidden_even_when_the_board_type_would_redirect(): void
    {
        $project = Project::factory()->scrum()->create();

        Livewire::test(Kanban::class, ['project' => $project])->assertForbidden();
    }

    public function test_a_stranger_is_forbidden_on_the_scrum_board_of_a_kanban_project(): void
    {
        $project = Project::factory()->create();

        Livewire::test(Scrum::class, ['project' => $project])->assertForbidden();
    }

    public function test_a_stranger_is_forbidden_on_a_board_of_the_matching_type(): void
    {
        $project = Project::factory()->create();

        Livewire::test(Kanban::class, ['project' => $project])->assertForbidden();
    }

    public function test_a_project_member_may_mount_the_board(): void
    {
        $project = Project::factory()->create();
        $project->users()->attach($this->user->id, ['role' => 'employee']);

        Livewire::test(Kanban::class, ['project' => $project])->assertSuccessful();
    }

    public function test_a_ticket_the_user_is_only_responsible_for_can_be_moved(): void
    {
        $project = Project::factory()->create();
        $project->users()->attach($this->user->id, ['role' => 'employee']);

        $ticket = Ticket::factory()->create([
            'project_id' => $project->id,
            'responsible_id' => $this->user->id,
        ]);
        $target = TicketStatus::factory()->create();

        Livewire::test(Kanban::class, ['project' => $project])
            ->call('recordUpdated', $ticket->id, 0, $target->id)
            ->assertSuccessful();

        $this->assertSame($target->id, $ticket->fresh()->status_id);
    }
}
