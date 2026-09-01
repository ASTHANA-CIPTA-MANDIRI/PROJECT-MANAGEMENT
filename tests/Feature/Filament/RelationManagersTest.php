<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ProjectResource\RelationManagers\SprintsRelationManager;
use App\Filament\Resources\ProjectResource\RelationManagers\StatusesRelationManager;
use App\Filament\Resources\ProjectResource\RelationManagers\UsersRelationManager;
use App\Models\Permission;
use App\Models\Project;
use App\Models\Role;
use App\Models\Sprint;
use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Relation managers hold the project's sub-tables (sprints, members, custom
 * statuses). They carry a lot of schema code that only runs when rendered.
 */
class RelationManagersTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $names = [
            'List projects', 'View project', 'Update project', 'Create project', 'Delete project',
            'List sprints', 'View sprint', 'Create sprint', 'Update sprint', 'Delete sprint',
            'List tickets', 'View ticket', 'Create ticket', 'Update ticket', 'Delete ticket',
            'List users', 'View user', 'Create user', 'Update user', 'Delete user',
            'List ticket statuses', 'View ticket status', 'Create ticket status',
            'Update ticket status', 'Delete ticket status',
        ];
        foreach ($names as $name) {
            Permission::firstOrCreate(['name' => $name]);
        }
        $role = Role::create(['name' => 'Administrator']);
        $role->syncPermissions($names);

        $this->user = User::factory()->create();
        $this->user->syncRoles([$role]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->user = $this->user->fresh();

        $this->actingAs($this->user);
    }

    private function ownedProject(): Project
    {
        return Project::factory()->create(['owner_id' => $this->user->id]);
    }

    /**
     * Relation managers are rendered with their owner record attached.
     */
    private function renderManager(string $manager, Project $project): \Livewire\Testing\TestableLivewire
    {
        return Livewire::test($manager, [
            'ownerRecord' => $project,
            'pageClass' => \App\Filament\Resources\ProjectResource\Pages\EditProject::class,
        ]);
    }

    // --------------------------------------------------------------- sprints

    public function test_the_sprints_relation_manager_renders(): void
    {
        $this->renderManager(SprintsRelationManager::class, $this->ownedProject())
            ->assertSuccessful();
    }

    public function test_the_sprints_relation_manager_lists_sprints(): void
    {
        $project = $this->ownedProject();
        Sprint::factory()->count(3)->create(['project_id' => $project->id]);

        $this->renderManager(SprintsRelationManager::class, $project)->assertSuccessful();
    }

    public function test_the_sprints_relation_manager_renders_with_a_running_sprint(): void
    {
        $project = Project::factory()->scrum()->create(['owner_id' => $this->user->id]);
        $sprint = Sprint::factory()->started()->create(['project_id' => $project->id]);
        Ticket::factory()->count(2)->create([
            'project_id' => $project->id,
            'sprint_id' => $sprint->id,
            'owner_id' => $this->user->id,
        ]);

        $this->renderManager(SprintsRelationManager::class, $project)->assertSuccessful();
    }

    public function test_the_sprints_relation_manager_renders_with_a_closed_sprint(): void
    {
        $project = Project::factory()->scrum()->create(['owner_id' => $this->user->id]);
        Sprint::factory()->ended()->create(['project_id' => $project->id]);

        $this->renderManager(SprintsRelationManager::class, $project)->assertSuccessful();
    }

    /**
     * The "Tickets" action lists the whole project's tickets, so it must stay
     * scoped to that project and must not lazy-load a sprint per ticket.
     */
    public function test_the_sprint_tickets_modal_lists_only_the_projects_own_tickets(): void
    {
        $project = Project::factory()->scrum()->create(['owner_id' => $this->user->id]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);
        $mine = Ticket::factory()->create([
            'project_id' => $project->id,
            'owner_id' => $this->user->id,
            'name' => 'Ticket of this project',
        ]);

        $otherProject = Project::factory()->scrum()->create(['owner_id' => $this->user->id]);
        $theirs = Ticket::factory()->create([
            'project_id' => $otherProject->id,
            'owner_id' => $this->user->id,
            'name' => 'Ticket of another project',
        ]);

        $this->renderManager(SprintsRelationManager::class, $project)
            ->mountTableAction('tickets', $sprint)
            ->assertSuccessful()
            ->assertSee($mine->name)
            ->assertDontSee($theirs->name);
    }

    /**
     * Opens the modal on a project holding $ticketCount tickets and counts the
     * queries hitting the sprints table. The project always has exactly two
     * sprints, so the sprints table itself costs the same either way and only
     * the checkbox labels can make the count grow.
     */
    private function sprintQueriesWhenOpeningTicketsModal(int $ticketCount): int
    {
        $project = Project::factory()->scrum()->create(['owner_id' => $this->user->id]);
        $target = Sprint::factory()->create(['project_id' => $project->id]);
        $other = Sprint::factory()->create(['project_id' => $project->id]);

        // Every ticket sits in the other sprint, so each one renders the sprint
        // badge — a lazy load would cost one query per ticket even though they
        // all point at the same sprint.
        foreach (range(1, $ticketCount) as $i) {
            Ticket::factory()->create([
                'project_id' => $project->id,
                'owner_id' => $this->user->id,
                'sprint_id' => $other->id,
            ]);
        }

        $manager = $this->renderManager(SprintsRelationManager::class, $project);

        // The log is shared for the whole test, so clear it before measuring.
        DB::flushQueryLog();
        DB::enableQueryLog();
        $manager->mountTableAction('tickets', $target)->assertSuccessful();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        return collect($queries)
            ->filter(fn ($query) => str_contains($query['query'], 'from "sprints"'))
            ->count();
    }

    public function test_the_sprint_tickets_modal_eager_loads_the_sprint_of_each_ticket(): void
    {
        // Both projects keep their sprints on a single table page, so the only
        // thing that could make the query count grow is a lazy load per ticket.
        $few = $this->sprintQueriesWhenOpeningTicketsModal(2);
        $many = $this->sprintQueriesWhenOpeningTicketsModal(8);

        $this->assertSame(
            $few,
            $many,
            'The sprint tickets modal lazy-loads a sprint per ticket.'
        );
    }

    /**
     * F-05: start/stop/tickets are custom table actions, not the standard
     * EditAction/DeleteAction Filament auto-wires to the policy. Reaching this
     * relation manager only proves ProjectPolicy::update (via EditProject) —
     * that does not imply SprintPolicy::update on a specific sprint, so each
     * action re-checks it inline, same as EditAction already does implicitly.
     * A project owner who lacks "Update sprint" is exactly this gap: allowed
     * onto the page, but should not be allowed to mutate the sprint from here.
     */
    private function projectOwnerWithoutUpdateSprintPermission(): User
    {
        foreach (['List projects', 'View project', 'Update project'] as $name) {
            Permission::firstOrCreate(['name' => $name]);
        }
        $role = Role::firstOrCreate(['name' => 'Project Manager Without Sprint Rights']);
        $role->syncPermissions(['List projects', 'View project', 'Update project']);

        $manager = User::factory()->create();
        $manager->syncRoles([$role]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $manager->fresh();
    }

    public function test_starting_a_sprint_is_blocked_without_the_update_sprint_permission(): void
    {
        $manager = $this->projectOwnerWithoutUpdateSprintPermission();
        $project = Project::factory()->scrum()->create(['owner_id' => $manager->id]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);
        $this->actingAs($manager);

        $this->renderManager(SprintsRelationManager::class, $project)
            ->callTableAction('start', $sprint)
            ->assertForbidden();

        $this->assertNull($sprint->fresh()->started_at);
    }

    public function test_stopping_a_sprint_is_blocked_without_the_update_sprint_permission(): void
    {
        $manager = $this->projectOwnerWithoutUpdateSprintPermission();
        $project = Project::factory()->scrum()->create(['owner_id' => $manager->id]);
        $sprint = Sprint::factory()->started()->create(['project_id' => $project->id]);
        $this->actingAs($manager);

        $this->renderManager(SprintsRelationManager::class, $project)
            ->callTableAction('stop', $sprint)
            ->assertForbidden();

        $this->assertNull($sprint->fresh()->ended_at);
    }

    public function test_reassigning_a_sprints_tickets_is_blocked_without_the_update_sprint_permission(): void
    {
        $manager = $this->projectOwnerWithoutUpdateSprintPermission();
        $project = Project::factory()->scrum()->create(['owner_id' => $manager->id]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'owner_id' => $manager->id]);
        $this->actingAs($manager);

        $this->renderManager(SprintsRelationManager::class, $project)
            ->callTableAction('tickets', $sprint, data: ['tickets' => [$ticket->id]])
            ->assertForbidden();

        $this->assertNull($ticket->fresh()->sprint_id);
    }

    // --------------------------------------------------------------- members

    public function test_the_users_relation_manager_renders(): void
    {
        $this->renderManager(UsersRelationManager::class, $this->ownedProject())
            ->assertSuccessful();
    }

    public function test_the_users_relation_manager_lists_members(): void
    {
        $project = $this->ownedProject();
        foreach (User::factory()->count(3)->create() as $member) {
            $project->users()->attach($member->id, ['role' => 'employee']);
        }

        $this->renderManager(UsersRelationManager::class, $project)->assertSuccessful();
    }

    public function test_the_users_relation_manager_renders_every_member_role(): void
    {
        $project = $this->ownedProject();
        foreach (['employee', 'customer', 'administrator'] as $role) {
            $project->users()->attach(User::factory()->create()->id, ['role' => $role]);
        }

        $this->renderManager(UsersRelationManager::class, $project)->assertSuccessful();
    }

    // -------------------------------------------------------------- statuses

    public function test_the_statuses_relation_manager_renders(): void
    {
        $project = Project::factory()->customStatuses()->create(['owner_id' => $this->user->id]);

        $this->renderManager(StatusesRelationManager::class, $project)->assertSuccessful();
    }

    public function test_the_statuses_relation_manager_lists_custom_statuses(): void
    {
        $project = Project::factory()->customStatuses()->create(['owner_id' => $this->user->id]);
        TicketStatus::factory()->count(3)->forProject($project)->create();

        $this->renderManager(StatusesRelationManager::class, $project)->assertSuccessful();
    }

    public function test_the_statuses_relation_manager_renders_for_a_default_project(): void
    {
        $this->renderManager(StatusesRelationManager::class, $this->ownedProject())
            ->assertSuccessful();
    }
}
