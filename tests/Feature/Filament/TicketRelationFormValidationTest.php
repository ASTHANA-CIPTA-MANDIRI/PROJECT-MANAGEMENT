<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\TicketResource\Pages\EditTicket;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * TicketForm::relationsRepeater() rejects a (type, relation_id) pair already
 * present among the submitted items, before Filament's relationship-repeater
 * sync ever reaches the database. This is what lets
 * 2026_08_24_000001_add_unique_index_to_ticket_relations_table.php add a
 * unique constraint without turning an accidental duplicate pick into a raw
 * QueryException on save.
 */
class TicketRelationFormValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['List tickets', 'View ticket', 'Update ticket'] as $name) {
            Permission::firstOrCreate(['name' => $name]);
        }
        $role = Role::create(['name' => 'Ticket manager']);
        $role->syncPermissions(['List tickets', 'View ticket', 'Update ticket']);

        $user = User::factory()->create();
        $user->syncRoles([$role]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->user = $user->fresh();
        $this->actingAs($this->user);
    }

    public function test_the_same_relation_cannot_be_added_twice_in_one_submission(): void
    {
        $ticket = Ticket::factory()->create(['owner_id' => $this->user->id]);
        $other = Ticket::factory()->create();

        Livewire::test(EditTicket::class, ['record' => $ticket->getRouteKey()])
            ->fillForm([
                'relations' => [
                    ['type' => 'related_to', 'relation_id' => $other->id],
                    ['type' => 'related_to', 'relation_id' => $other->id],
                ],
            ])
            ->call('save')
            ->assertHasFormErrors(['relations']);

        $this->assertSame(0, $ticket->fresh()->relations()->count());
    }

    public function test_different_relation_types_to_the_same_ticket_are_still_allowed(): void
    {
        $ticket = Ticket::factory()->create(['owner_id' => $this->user->id]);
        $other = Ticket::factory()->create();

        Livewire::test(EditTicket::class, ['record' => $ticket->getRouteKey()])
            ->fillForm([
                'relations' => [
                    ['type' => 'related_to', 'relation_id' => $other->id],
                    ['type' => 'blocked_by', 'relation_id' => $other->id],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(2, $ticket->fresh()->relations()->count());
    }
}
