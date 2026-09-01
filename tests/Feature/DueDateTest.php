<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DueDateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    // ------------------------------------------------------------ isOverdue

    public function test_a_ticket_due_yesterday_is_overdue(): void
    {
        $ticket = Ticket::factory()->create(['due_date' => now()->subDay()]);

        $this->assertTrue($ticket->isOverdue);
    }

    public function test_a_ticket_due_today_is_not_yet_overdue(): void
    {
        $ticket = Ticket::factory()->create(['due_date' => now()]);

        $this->assertFalse($ticket->isOverdue);
    }

    public function test_a_ticket_due_tomorrow_is_not_overdue(): void
    {
        $ticket = Ticket::factory()->create(['due_date' => now()->addDay()]);

        $this->assertFalse($ticket->isOverdue);
    }

    public function test_a_ticket_without_a_due_date_is_not_overdue(): void
    {
        $ticket = Ticket::factory()->create(['due_date' => null]);

        $this->assertFalse($ticket->isOverdue);
    }

    // ----------------------------------------------------------------- form

    public function test_the_ticket_edit_form_saves_a_due_date(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);
        $ticket = Ticket::factory()->create(['owner_id' => $admin->id]);
        $dueDate = now()->addWeek()->startOfDay();

        Livewire::test(
            \App\Filament\Resources\TicketResource\Pages\EditTicket::class,
            ['record' => $ticket->getRouteKey()]
        )
            ->fillForm(['due_date' => $dueDate->toDateString()])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($ticket->fresh()->due_date->isSameDay($dueDate));
    }

    // ---------------------------------------------------------------- table

    public function test_the_ticket_list_shows_the_due_date_column(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);
        Ticket::factory()->create(['owner_id' => $admin->id, 'due_date' => now()->subDay()]);

        Livewire::test(\App\Filament\Resources\TicketResource\Pages\ListTickets::class)
            ->assertSuccessful();
    }

    // ---------------------------------------------------------------- board

    public function test_the_kanban_board_flags_an_overdue_ticket(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);
        $project = \App\Models\Project::factory()->create(['owner_id' => $admin->id]);
        $ticket = Ticket::factory()->create([
            'project_id' => $project->id,
            'owner_id' => $admin->id,
            'due_date' => now()->subDay(),
        ]);

        $board = Livewire::test(\App\Filament\Pages\Kanban::class, ['project' => $project]);
        $record = $board->instance()->getRecords()->firstWhere('id', $ticket->id);

        $this->assertTrue($record['is_overdue']);
        $this->assertNotNull($record['due_date']);
    }

    public function test_the_kanban_card_due_date_is_not_mangled_by_a_translation_override(): void
    {
        // Force the 'id' JSON group to load, then override just the "Y-m-d"
        // line - simulating a translator turning it into a localized (and
        // Carbon-invalid) date format. The card must still render the real
        // ISO date: the format string must never pass through __().
        app('translator')->get('force-load');
        app('translator')->addLines(['*.Y-m-d' => 'BROKEN-FORMAT'], 'id');

        $admin = $this->makeAdmin();
        $this->actingAs($admin);
        $project = \App\Models\Project::factory()->create(['owner_id' => $admin->id]);
        $dueDate = now()->addDays(3)->startOfDay();
        Ticket::factory()->create([
            'project_id' => $project->id,
            'owner_id' => $admin->id,
            'due_date' => $dueDate,
        ]);

        Livewire::test(\App\Filament\Pages\Kanban::class, ['project' => $project])
            ->assertSee($dueDate->format('Y-m-d'))
            ->assertDontSee('BROKEN-FORMAT');
    }

    private function makeAdmin(): User
    {
        $modules = ['project', 'ticket'];

        $names = [];
        foreach ($modules as $module) {
            $names[] = 'List '.\Illuminate\Support\Str::plural($module);
            foreach (['View', 'Create', 'Update', 'Delete'] as $action) {
                $names[] = $action.' '.$module;
            }
        }

        foreach ($names as $name) {
            Permission::firstOrCreate(['name' => $name]);
        }

        $role = Role::create(['name' => 'Ticket admin '.uniqid()]);
        $role->syncPermissions($names);

        $user = User::factory()->create();
        $user->syncRoles([$role]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }
}
