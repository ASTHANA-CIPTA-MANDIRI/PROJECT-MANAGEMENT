<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\TicketResource\Pages\ListTickets;
use App\Filament\Resources\TicketTypeResource\Pages\ListTicketTypes;
use App\Models\Permission;
use App\Models\Project;
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
}
