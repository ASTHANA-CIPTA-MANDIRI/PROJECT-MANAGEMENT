<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ProjectResource\Pages\ListProjects;
use App\Filament\Resources\TicketResource\Pages\ListTickets;
use App\Filament\Resources\TicketTypeResource\Pages\ListTicketTypes;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\Permission;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Settings\GeneralSettings;
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
 *
 * F-01: canRestoreAny() only proves the actor holds a permission - it ignores
 * per-record conditions the row-level restore() enforces (ownership,
 * involvement, or - for User - the Super Admin guard). RestoreBulkAction is
 * now filtered through BulkRestoreAuthorizer (AppServiceProvider), the
 * restore-side mirror of BulkDeleteAuthorizer, so a mixed selection only
 * restores the records restore() itself would have allowed.
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

    // ------------------------------------------- per-record enforcement (F-01)

    /**
     * ProjectResource::getEloquentQuery() scopes the list to accessibleBy()
     * (owner or any member), but ProjectPolicy::restore() - mirroring delete()
     * - requires isManageableBy() (owner or the "can manage" role). A plain
     * member can therefore see and select a trashed project in the table that
     * the row-level restore button would refuse.
     */
    public function test_a_mixed_project_selection_only_bulk_restores_the_projects_the_member_manages(): void
    {
        $status = ProjectStatus::factory()->create();
        $manager = $this->userWith(['List projects', 'View project', 'Delete project']);

        $managed = Project::factory()->create(['status_id' => $status->id]);
        $managed->users()->attach($manager->id, [
            'role' => config('system.projects.affectations.roles.can_manage'),
        ]);
        $managed->delete();

        // A plain member (not owner, not "can manage"): accessible enough to
        // appear in the trashed list, but ProjectPolicy::restore() must refuse.
        $notManaged = Project::factory()->create(['status_id' => $status->id]);
        $notManaged->users()->attach($manager->id, [
            'role' => config('system.projects.affectations.roles.default'),
        ]);
        $notManaged->delete();

        $this->actingAs($manager);

        Livewire::test(ListProjects::class)
            ->filterTable('trashed', false)
            ->callTableBulkAction('restore', [$managed, $notManaged]);

        $this->assertNull($managed->fresh()->deleted_at, 'the managed project should be restored');
        $this->assertNotNull($notManaged->fresh()->deleted_at, 'the unmanaged project must survive the bulk restore');
    }

    public function test_a_plain_user_manager_cannot_bulk_restore_a_deleted_super_admin(): void
    {
        GeneralSettings::fake(['super_admin_role' => null]);

        $superAdminRole = Role::create(['name' => 'Super Admin']);
        $superAdmin = User::factory()->create();
        $superAdmin->syncRoles([$superAdminRole]);
        // A second Super Admin, so UserObserver::deleting()'s "last admin"
        // guard isn't what cancels the delete() below.
        User::factory()->create()->syncRoles([$superAdminRole]);
        $superAdmin->delete();

        $manager = $this->userWith(['List users', 'View user', 'Delete user']);
        $this->actingAs($manager);

        Livewire::test(ListUsers::class)
            ->filterTable('trashed', false)
            ->callTableBulkAction('restore', [$superAdmin]);

        $this->assertNotNull(
            $superAdmin->fresh()->deleted_at,
            'a deleted Super Admin must survive a bulk restore run by a non-Super-Admin'
        );
    }

    public function test_a_mixed_user_selection_bulk_restores_the_regular_account_but_not_the_super_admin(): void
    {
        GeneralSettings::fake(['super_admin_role' => null]);

        $superAdminRole = Role::create(['name' => 'Super Admin']);
        $superAdmin = User::factory()->create();
        $superAdmin->syncRoles([$superAdminRole]);
        // A second Super Admin, so UserObserver::deleting()'s "last admin"
        // guard isn't what cancels the delete() below.
        User::factory()->create()->syncRoles([$superAdminRole]);
        $superAdmin->delete();

        $regular = User::factory()->create();
        $regular->delete();

        $manager = $this->userWith(['List users', 'View user', 'Delete user']);
        $this->actingAs($manager);

        Livewire::test(ListUsers::class)
            ->filterTable('trashed', false)
            ->callTableBulkAction('restore', [$superAdmin, $regular]);

        $this->assertNotNull($superAdmin->fresh()->deleted_at, 'the Super Admin must not be restored');
        $this->assertNull($regular->fresh()->deleted_at, 'the regular account should still be restored');
    }

    public function test_a_super_admin_can_bulk_restore_a_deleted_super_admin(): void
    {
        GeneralSettings::fake(['super_admin_role' => null]);

        $superAdminRole = Role::create(['name' => 'Super Admin']);
        foreach (['List users', 'View user', 'Delete user'] as $name) {
            Permission::firstOrCreate(['name' => $name]);
        }
        $superAdminRole->syncPermissions(['List users', 'View user', 'Delete user']);

        $target = User::factory()->create();
        $target->syncRoles([$superAdminRole]);
        $target->delete();

        $actingSuperAdmin = User::factory()->create();
        $actingSuperAdmin->syncRoles([$superAdminRole]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($actingSuperAdmin->fresh());

        Livewire::test(ListUsers::class)
            ->filterTable('trashed', false)
            ->callTableBulkAction('restore', [$target]);

        $this->assertNull($target->fresh()->deleted_at, 'a Super Admin can restore another Super Admin');
    }
}
