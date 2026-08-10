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
        $this->assertSame(3, $ticket->order);
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
