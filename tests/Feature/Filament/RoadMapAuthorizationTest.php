<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\RoadMap;
use App\Http\Livewire\RoadMap\EpicForm;
use App\Models\Epic;
use App\Models\Permission;
use App\Models\Project;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The Road Map takes three ids straight from the browser: the epic id passed
 * to the updateEpic listener, the project id posted back by the filter select,
 * and the epic bound into EpicForm. These tests pin down that none of the
 * three reaches a project the user cannot access.
 */
class RoadMapAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->user = $this->userWith([
            'List projects', 'View project', 'Update project',
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

    private function strangerEpic(): Epic
    {
        return Epic::factory()->create(['project_id' => Project::factory()->create()->id]);
    }

    // ------------------------------------------------- updateEpic listener

    public function test_an_epic_from_another_project_cannot_be_loaded(): void
    {
        Project::factory()->create(['owner_id' => $this->user->id]);
        $theirEpic = $this->strangerEpic();

        $this->expectException(ModelNotFoundException::class);

        Livewire::test(RoadMap::class)->call('updateEpic', $theirEpic->id);
    }

    public function test_an_epic_from_an_own_project_still_loads(): void
    {
        $project = Project::factory()->create(['owner_id' => $this->user->id]);
        $epic = Epic::factory()->create(['project_id' => $project->id]);

        Livewire::test(RoadMap::class)
            ->call('updateEpic', $epic->id)
            ->assertSet('epic.id', $epic->id);
    }

    public function test_a_project_member_can_load_an_epic_of_that_project(): void
    {
        $project = Project::factory()->create();
        $project->users()->attach($this->user->id, ['role' => 'developer']);
        $epic = Epic::factory()->create(['project_id' => $project->id]);

        Livewire::test(RoadMap::class)
            ->call('updateEpic', $epic->id)
            ->assertSet('epic.id', $epic->id);
    }

    // ------------------------------------------------------- filter select

    public function test_the_filter_ignores_a_project_the_user_cannot_access(): void
    {
        $mine = Project::factory()->create(['owner_id' => $this->user->id]);
        $theirs = Project::factory()->create();

        $page = Livewire::test(RoadMap::class)
            ->assertSet('project.id', $mine->id)
            ->set('selectedProject', $theirs->id)
            ->call('filter');

        // The posted selection is rejected; the page stays on the accessible one.
        $this->assertSame($mine->id, $page->instance()->project->id);
    }

    public function test_the_filter_switches_between_accessible_projects(): void
    {
        Project::factory()->create(['owner_id' => $this->user->id]);
        $other = Project::factory()->create(['owner_id' => $this->user->id]);

        $page = Livewire::test(RoadMap::class)
            ->set('selectedProject', $other->id)
            ->call('filter');

        $this->assertSame($other->id, $page->instance()->project->id);
    }

    // ------------------------------------------------------------ EpicForm

    public function test_an_epic_of_another_project_cannot_be_opened_in_the_form(): void
    {
        $theirEpic = $this->strangerEpic();

        // Without this, Livewire's harness renders the 403 instead of surfacing
        // the exception, and the assertion below could not tell them apart.
        $this->withoutExceptionHandling();

        $this->expectException(AuthorizationException::class);

        Livewire::test(EpicForm::class, ['epic' => $theirEpic]);
    }

    public function test_an_epic_of_another_project_cannot_be_deleted(): void
    {
        $theirEpic = $this->strangerEpic();
        $ticket = Ticket::factory()->create([
            'project_id' => $theirEpic->project_id,
            'epic_id' => $theirEpic->id,
        ]);

        // mount() already refuses such an epic, so delete() is exercised
        // directly — it must not rely on the caller having checked first.
        $component = new EpicForm;
        $component->epic = $theirEpic;

        try {
            $component->delete();
            $this->fail('delete() accepted an epic from another project.');
        } catch (AuthorizationException) {
            // expected
        }

        $this->assertDatabaseHas('epics', ['id' => $theirEpic->id, 'deleted_at' => null]);
        $this->assertSame($theirEpic->id, $ticket->fresh()->epic_id);
    }

    public function test_an_own_epic_can_still_be_deleted(): void
    {
        $project = Project::factory()->create(['owner_id' => $this->user->id]);
        $epic = Epic::factory()->create(['project_id' => $project->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'epic_id' => $epic->id]);

        Livewire::test(EpicForm::class, ['epic' => $epic])->call('delete');

        $this->assertSoftDeleted('epics', ['id' => $epic->id]);
        $this->assertNull($ticket->fresh()->epic_id);
    }

    public function test_an_epic_cannot_be_moved_into_another_users_project(): void
    {
        $mine = Project::factory()->create(['owner_id' => $this->user->id]);
        $theirs = Project::factory()->create();
        $epic = Epic::factory()->create(['project_id' => $mine->id]);

        // project_id is only visually disabled; the posted value is client state.
        $this->expectException(ModelNotFoundException::class);

        try {
            Livewire::test(EpicForm::class, ['epic' => $epic])
                ->set('project_id', $theirs->id)
                ->call('submit');
        } finally {
            $this->assertSame($mine->id, $epic->fresh()->project_id);
        }
    }

    public function test_a_parent_epic_from_another_project_is_discarded(): void
    {
        $mine = Project::factory()->create(['owner_id' => $this->user->id]);
        $epic = Epic::factory()->create(['project_id' => $mine->id]);
        $foreignParent = $this->strangerEpic();

        Livewire::test(EpicForm::class, ['epic' => $epic])
            ->set('parent_id', $foreignParent->id)
            ->call('submit');

        $this->assertNull($epic->fresh()->parent_id);
    }

    public function test_a_parent_epic_from_the_same_project_is_kept(): void
    {
        $mine = Project::factory()->create(['owner_id' => $this->user->id]);
        $epic = Epic::factory()->create(['project_id' => $mine->id]);
        $parent = Epic::factory()->create(['project_id' => $mine->id]);

        Livewire::test(EpicForm::class, ['epic' => $epic])
            ->set('parent_id', $parent->id)
            ->call('submit');

        $this->assertSame($parent->id, $epic->fresh()->parent_id);
    }

    public function test_the_form_only_offers_projects_the_user_can_access(): void
    {
        $mine = Project::factory()->create(['owner_id' => $this->user->id, 'name' => 'Mine To See']);
        Project::factory()->create(['name' => 'Secret Other Company']);
        $epic = Epic::factory()->create(['project_id' => $mine->id]);

        Livewire::test(EpicForm::class, ['epic' => $epic])
            ->assertSee('Mine To See')
            ->assertDontSee('Secret Other Company');
    }
}
