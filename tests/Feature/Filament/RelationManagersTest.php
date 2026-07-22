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
