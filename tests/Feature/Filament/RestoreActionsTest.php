<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ProjectResource\Pages\ListProjects;
use App\Filament\Resources\TicketResource\Pages\ListTickets;
use App\Filament\Resources\TicketTypeResource\Pages\ListTicketTypes;
use App\Models\Permission;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * M-7: the Restore action added to each restorable resource's table must be
 * gated by the same policy as delete (Policy::restore() mirrors delete()),
 * not just available to anyone who can reach the list page.
 *
 * M-1: RestoreBulkAction is auto-wired by Filament to the *policy's*
 * `restoreAny()` method (Filament\Resources\Pages\ListRecords::
 * configureRestoreBulkAction() -> Resource::canRestoreAny() ->
 * Gate::check('restoreAny', $model)), regardless of how the resource
 * declares the action. With fail-closed authorization
 * (AuthServiceProvider::boot()) a policy missing that method denies the
 * ability to everyone, including Super Admin - bulk restore was dead. These
 * cases prove the button is both gated (hidden without permission) and
 * functional (restores every selected record once granted), not just that
 * the button happens to be visible.
 */
class RestoreActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    private function userWith(array $permissions): User
    {
        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name]);
        }

        $role = Role::create(['name' => 'role_'.uniqid()]);
        $role->syncPermissions($permissions);

        $user = User::factory()->create();
        $user->syncRoles([$role]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    public function test_a_user_without_delete_ticket_type_cannot_see_the_restore_action(): void
    {
        $type = TicketType::factory()->create();
        $type->delete();
        $viewer = $this->userWith(['List ticket types', 'View ticket type']);
        $this->actingAs($viewer);

        Livewire::test(ListTicketTypes::class)
            ->filterTable('trashed', false)
            ->assertTableActionHidden('restore', $type);
    }

    public function test_a_user_with_delete_ticket_type_can_restore_it(): void
    {
        $type = TicketType::factory()->create();
        $type->delete();
        $manager = $this->userWith(['List ticket types', 'View ticket type', 'Delete ticket type']);
        $this->actingAs($manager);

        Livewire::test(ListTicketTypes::class)
            ->filterTable('trashed', false)
            ->callTableAction('restore', $type);

        $this->assertNull($type->fresh()->deleted_at);
    }

    public function test_a_ticket_manager_uninvolved_with_a_trashed_ticket_cannot_restore_it(): void
    {
        $project = Project::factory()->create();
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);
        $ticket->delete();

        // Holds "Delete ticket" but is neither the owner/responsible nor has
        // project access - TicketPolicy::isInvolved() must still refuse.
        $outsider = $this->userWith(['List tickets', 'View ticket', 'Delete ticket']);
        $this->actingAs($outsider);

        Livewire::test(ListTickets::class)
            ->filterTable('trashed', false)
            ->assertTableActionHidden('restore', $ticket);

        $this->assertNotNull($ticket->fresh()->deleted_at);
    }

    public function test_the_tickets_owner_can_restore_their_trashed_ticket(): void
    {
        $owner = $this->userWith(['List tickets', 'View ticket', 'Delete ticket']);
        $project = Project::factory()->create();
        $project->users()->attach($owner->id, ['role' => config('system.projects.affectations.roles.default')]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'owner_id' => $owner->id]);
        $ticket->delete();
        $this->actingAs($owner);

        Livewire::test(ListTickets::class)
            ->filterTable('trashed', false)
            ->callTableAction('restore', $ticket);

        $this->assertNull($ticket->fresh()->deleted_at);
    }

    // ---------------------------------------------------- bulk restore (M-1)

    public function test_a_user_without_delete_ticket_type_cannot_see_the_bulk_restore_action(): void
    {
        $type = TicketType::factory()->create();
        $type->delete();
        $viewer = $this->userWith(['List ticket types', 'View ticket type']);
        $this->actingAs($viewer);

        Livewire::test(ListTicketTypes::class)
            ->filterTable('trashed', false)
            ->assertTableBulkActionHidden('restore');

        $this->assertNotNull($type->fresh()->deleted_at);
    }

    public function test_a_user_with_delete_ticket_type_can_bulk_restore_several_records(): void
    {
        $first = TicketType::factory()->create();
        $second = TicketType::factory()->create();
        $first->delete();
        $second->delete();
        $manager = $this->userWith(['List ticket types', 'View ticket type', 'Delete ticket type']);
        $this->actingAs($manager);

        Livewire::test(ListTicketTypes::class)
            ->filterTable('trashed', false)
            ->callTableBulkAction('restore', [$first, $second]);

        $this->assertNull($first->fresh()->deleted_at);
        $this->assertNull($second->fresh()->deleted_at);
    }

    public function test_a_user_without_delete_project_cannot_see_the_bulk_restore_action(): void
    {
        $status = ProjectStatus::factory()->create();
        $project = Project::factory()->create(['status_id' => $status->id]);
        $project->delete();
        $viewer = $this->userWith(['List projects', 'View project']);
        $this->actingAs($viewer);

        Livewire::test(ListProjects::class)
            ->filterTable('trashed', false)
            ->assertTableBulkActionHidden('restore');

        $this->assertNotNull($project->fresh()->deleted_at);
    }

    public function test_a_user_with_delete_project_can_bulk_restore_several_projects(): void
    {
        $status = ProjectStatus::factory()->create();
        $manager = $this->userWith(['List projects', 'View project', 'Delete project']);
        // ProjectResource::getEloquentQuery() scopes every panel query (including
        // the one bulk actions restore records through) to accessibleBy() -
        // owner or member. Without that, the project would never appear in this
        // user's list to select in the first place, regardless of permissions.
        $first = Project::factory()->create(['status_id' => $status->id, 'owner_id' => $manager->id]);
        $second = Project::factory()->create(['status_id' => $status->id, 'owner_id' => $manager->id]);
        $first->delete();
        $second->delete();
        $this->actingAs($manager);

        Livewire::test(ListProjects::class)
            ->filterTable('trashed', false)
            ->callTableBulkAction('restore', [$first, $second]);

        $this->assertNull($first->fresh()->deleted_at);
        $this->assertNull($second->fresh()->deleted_at);
    }
}
