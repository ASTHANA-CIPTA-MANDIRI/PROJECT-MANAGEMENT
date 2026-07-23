<?php

namespace Tests\Feature\Filament;

use App\Models\Activity;
use App\Models\Permission;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\TicketPriority;
use App\Models\TicketStatus;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Renders every resource page. These exercise the form() and table() schemas,
 * which is where most of the Filament code lives - a broken column, a renamed
 * relation or a bad closure surfaces here rather than in production.
 */
class ResourcePagesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->admin = $this->makeAdmin();
        $this->actingAs($this->admin);
    }

    /**
     * A user holding every permission the seeder defines.
     */
    private function makeAdmin(): User
    {
        $modules = [
            'permission', 'project', 'project status', 'role', 'ticket',
            'ticket priority', 'ticket status', 'ticket type', 'user',
            'activity', 'sprint',
        ];

        $names = [];
        foreach ($modules as $module) {
            $names[] = 'List '.\Illuminate\Support\Str::plural($module);
            foreach (['View', 'Create', 'Update', 'Delete'] as $action) {
                $names[] = $action.' '.$module;
            }
        }
        $names = array_merge($names, [
            'Manage general settings', 'Import from Jira',
            'List timesheet data', 'View timesheet dashboard',
        ]);

        foreach ($names as $name) {
            Permission::firstOrCreate(['name' => $name]);
        }

        $role = Role::create(['name' => 'Administrator']);
        $role->syncPermissions($names);

        $user = User::factory()->create();
        $user->syncRoles([$role]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    // --------------------------------------------------------------- Projects

    public function test_the_project_list_page_renders(): void
    {
        Project::factory()->count(3)->create();

        Livewire::test(\App\Filament\Resources\ProjectResource\Pages\ListProjects::class)
            ->assertSuccessful();
    }

    public function test_the_project_create_page_renders(): void
    {
        Livewire::test(\App\Filament\Resources\ProjectResource\Pages\CreateProject::class)
            ->assertSuccessful();
    }

    public function test_the_project_edit_page_renders(): void
    {
        $project = Project::factory()->create(['owner_id' => $this->admin->id]);

        Livewire::test(
            \App\Filament\Resources\ProjectResource\Pages\EditProject::class,
            ['record' => $project->getRouteKey()]
        )->assertSuccessful();
    }

    public function test_the_project_view_page_renders(): void
    {
        $project = Project::factory()->create(['owner_id' => $this->admin->id]);

        Livewire::test(
            \App\Filament\Resources\ProjectResource\Pages\ViewProject::class,
            ['record' => $project->getRouteKey()]
        )->assertSuccessful();
    }

    // ---------------------------------------------------------------- Tickets

    public function test_the_ticket_list_page_renders(): void
    {
        Ticket::factory()->count(3)->create();

        Livewire::test(\App\Filament\Resources\TicketResource\Pages\ListTickets::class)
            ->assertSuccessful();
    }

    public function test_the_ticket_create_page_renders(): void
    {
        Livewire::test(\App\Filament\Resources\TicketResource\Pages\CreateTicket::class)
            ->assertSuccessful();
    }

    public function test_the_ticket_edit_page_renders(): void
    {
        $ticket = Ticket::factory()->create(['owner_id' => $this->admin->id]);

        Livewire::test(
            \App\Filament\Resources\TicketResource\Pages\EditTicket::class,
            ['record' => $ticket->getRouteKey()]
        )->assertSuccessful();
    }

    public function test_the_ticket_view_page_renders(): void
    {
        $ticket = Ticket::factory()->create(['owner_id' => $this->admin->id]);

        Livewire::test(
            \App\Filament\Resources\TicketResource\Pages\ViewTicket::class,
            ['record' => $ticket->getRouteKey()]
        )->assertSuccessful();
    }

    // ------------------------------------------------------------------ Users

    public function test_the_user_list_page_renders(): void
    {
        User::factory()->count(3)->create();

        Livewire::test(\App\Filament\Resources\UserResource\Pages\ListUsers::class)
            ->assertSuccessful();
    }

    public function test_the_user_create_page_renders(): void
    {
        Livewire::test(\App\Filament\Resources\UserResource\Pages\CreateUser::class)
            ->assertSuccessful();
    }

    public function test_the_user_edit_page_renders(): void
    {
        $user = User::factory()->create();

        Livewire::test(
            \App\Filament\Resources\UserResource\Pages\EditUser::class,
            ['record' => $user->getRouteKey()]
        )->assertSuccessful();
    }

    // ------------------------------------------------------------------ Roles

    public function test_the_role_list_page_renders(): void
    {
        Livewire::test(\App\Filament\Resources\RoleResource\Pages\ListRoles::class)
            ->assertSuccessful();
    }

    public function test_the_role_create_page_renders(): void
    {
        Livewire::test(\App\Filament\Resources\RoleResource\Pages\CreateRole::class)
            ->assertSuccessful();
    }

    public function test_the_role_edit_page_renders(): void
    {
        $role = Role::create(['name' => 'Editable']);

        Livewire::test(
            \App\Filament\Resources\RoleResource\Pages\EditRole::class,
            ['record' => $role->getRouteKey()]
        )->assertSuccessful();
    }

    // ------------------------------------------------------------ Permissions

    public function test_the_permission_list_page_renders(): void
    {
        Livewire::test(\App\Filament\Resources\PermissionResource\Pages\ListPermissions::class)
            ->assertSuccessful();
    }

    public function test_the_permission_create_page_renders(): void
    {
        Livewire::test(\App\Filament\Resources\PermissionResource\Pages\CreatePermission::class)
            ->assertSuccessful();
    }

    // ------------------------------------------------------------- Activities

    public function test_the_activity_list_page_renders(): void
    {
        Activity::factory()->count(2)->create();

        Livewire::test(\App\Filament\Resources\ActivityResource\Pages\ListActivities::class)
            ->assertSuccessful();
    }

    public function test_the_activity_create_page_renders(): void
    {
        Livewire::test(\App\Filament\Resources\ActivityResource\Pages\CreateActivity::class)
            ->assertSuccessful();
    }

    public function test_the_activity_edit_page_renders(): void
    {
        $activity = Activity::factory()->create();

        Livewire::test(
            \App\Filament\Resources\ActivityResource\Pages\EditActivity::class,
            ['record' => $activity->getRouteKey()]
        )->assertSuccessful();
    }

    // -------------------------------------------------------- Project statuses

    public function test_the_project_status_list_page_renders(): void
    {
        ProjectStatus::factory()->count(2)->create();

        Livewire::test(\App\Filament\Resources\ProjectStatusResource\Pages\ListProjectStatuses::class)
            ->assertSuccessful();
    }

    public function test_the_project_status_create_page_renders(): void
    {
        Livewire::test(\App\Filament\Resources\ProjectStatusResource\Pages\CreateProjectStatus::class)
            ->assertSuccessful();
    }

    public function test_the_project_status_edit_page_renders(): void
    {
        $status = ProjectStatus::factory()->create();

        Livewire::test(
            \App\Filament\Resources\ProjectStatusResource\Pages\EditProjectStatus::class,
            ['record' => $status->getRouteKey()]
        )->assertSuccessful();
    }

    // --------------------------------------------------------- Ticket statuses

    public function test_the_ticket_status_list_page_renders(): void
    {
        TicketStatus::factory()->count(2)->create();

        Livewire::test(\App\Filament\Resources\TicketStatusResource\Pages\ListTicketStatuses::class)
            ->assertSuccessful();
    }

    public function test_the_ticket_status_create_page_renders(): void
    {
        Livewire::test(\App\Filament\Resources\TicketStatusResource\Pages\CreateTicketStatus::class)
            ->assertSuccessful();
    }

    public function test_the_ticket_status_edit_page_renders(): void
    {
        $status = TicketStatus::factory()->create();

        Livewire::test(
            \App\Filament\Resources\TicketStatusResource\Pages\EditTicketStatus::class,
            ['record' => $status->getRouteKey()]
        )->assertSuccessful();
    }

    // ------------------------------------------------------------ Ticket types

    public function test_the_ticket_type_list_page_renders(): void
    {
        TicketType::factory()->count(2)->create();

        Livewire::test(\App\Filament\Resources\TicketTypeResource\Pages\ListTicketTypes::class)
            ->assertSuccessful();
    }

    public function test_the_ticket_type_create_page_renders(): void
    {
        Livewire::test(\App\Filament\Resources\TicketTypeResource\Pages\CreateTicketType::class)
            ->assertSuccessful();
    }

    public function test_the_ticket_type_edit_page_renders(): void
    {
        $type = TicketType::factory()->create();

        Livewire::test(
            \App\Filament\Resources\TicketTypeResource\Pages\EditTicketType::class,
            ['record' => $type->getRouteKey()]
        )->assertSuccessful();
    }

    // ------------------------------------------------------- Ticket priorities

    public function test_the_ticket_priority_list_page_renders(): void
    {
        TicketPriority::factory()->count(2)->create();

        Livewire::test(\App\Filament\Resources\TicketPriorityResource\Pages\ListTicketPriorities::class)
            ->assertSuccessful();
    }

    public function test_the_ticket_priority_create_page_renders(): void
    {
        Livewire::test(\App\Filament\Resources\TicketPriorityResource\Pages\CreateTicketPriority::class)
            ->assertSuccessful();
    }

    public function test_the_ticket_priority_edit_page_renders(): void
    {
        $priority = TicketPriority::factory()->create();

        Livewire::test(
            \App\Filament\Resources\TicketPriorityResource\Pages\EditTicketPriority::class,
            ['record' => $priority->getRouteKey()]
        )->assertSuccessful();
    }
}
