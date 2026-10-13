<?php

namespace Tests\Feature\Filament;

use App\Models\Activity;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\TicketComment;
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
            'activity', 'sprint', 'label',
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

        // Phase 5.3B: Create Project also requires the actor to be the
        // Owner/Admin of their current Organization — this fixture is meant
        // to render every resource's pages successfully, Project's included.
        Organization::factory()->create()->users()->attach($user->id, ['role' => 'owner']);

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

    /**
     * The status cards count through TicketResource::getEloquentQuery() (the
     * table's own access-scoped base query), so a ticket the user cannot see
     * is never counted, and a card click filters through the table filter.
     */
    public function test_the_ticket_status_cards_only_count_visible_tickets_and_filter_the_table(): void
    {
        $todo = TicketStatus::factory()->create(['name' => 'Todo', 'project_id' => null]);
        $done = TicketStatus::factory()->create(['name' => 'Done', 'project_id' => null]);
        $stranger = User::factory()->create();

        $mine = Project::factory()->create(['owner_id' => $this->admin->id]);
        $visibleTodoA = Ticket::factory()->create(['project_id' => $mine->id, 'status_id' => $todo->id, 'owner_id' => $this->admin->id]);
        $visibleTodoB = Ticket::factory()->create(['project_id' => $mine->id, 'status_id' => $todo->id, 'owner_id' => $this->admin->id]);
        $visibleDone = Ticket::factory()->create(['project_id' => $mine->id, 'status_id' => $done->id, 'owner_id' => $this->admin->id]);

        // A project of another Organization: invisible to the admin (the
        // Organization axis), so it must not leak into any count.
        $foreign = Project::factory()->create([
            'owner_id' => $stranger->id,
            'organization_id' => Organization::factory()->create()->id,
        ]);
        foreach (range(1, 4) as $unused) {
            Ticket::factory()->create([
                'project_id' => $foreign->id, 'status_id' => $todo->id,
                'owner_id' => $stranger->id, 'responsible_id' => $stranger->id,
            ]);
        }

        $component = Livewire::test(\App\Filament\Resources\TicketResource\Pages\ListTickets::class)
            ->assertSeeHtml('<span class="rk-stat-cards__value">3</span>')   // Total
            ->assertSeeHtml('<span class="rk-stat-cards__value">2</span>')   // Todo
            ->assertSeeHtml('<span class="rk-stat-cards__value">1</span>')   // Done
            ->assertDontSeeHtml('<span class="rk-stat-cards__value">7</span>');

        $component->call('applyStatusCard', 'done')
            ->assertSet('tableFilters.status_id.values', [(string) $done->id])
            ->assertCanSeeTableRecords([$visibleDone])
            ->assertCanNotSeeTableRecords([$visibleTodoA, $visibleTodoB]);

        // Clicking the active card clears the filter again.
        $component->call('applyStatusCard', 'done')
            ->assertSet('tableFilters.status_id.values', [])
            ->assertCanSeeTableRecords([$visibleTodoA, $visibleTodoB, $visibleDone]);

        // An unknown / client-forged key never produces a filter.
        $component->call('applyStatusCard', 'does-not-exist')
            ->assertSet('tableFilters.status_id.values', []);
    }

    /**
     * The filter tabs narrow the table's own access-scoped query and their
     * counts come from that same base query, so a ticket of another
     * Organization is never counted or listed; tabs combine with the status
     * cards, and an unknown tab key falls back to "all".
     */
    public function test_the_ticket_tabs_are_scoped_filter_the_table_and_combine_with_cards(): void
    {
        $open = TicketStatus::factory()->create(['name' => 'Todo', 'project_id' => null, 'is_final' => false]);
        $done = TicketStatus::factory()->create(['name' => 'Done', 'project_id' => null, 'is_final' => true]);
        $other = User::factory()->create();
        $mine = Project::factory()->create(['owner_id' => $this->admin->id]);

        $assigned = Ticket::factory()->create(['project_id' => $mine->id, 'status_id' => $open->id, 'owner_id' => $other->id, 'responsible_id' => $this->admin->id, 'due_date' => now()->addDays(5)]);
        $unassigned = Ticket::factory()->create(['project_id' => $mine->id, 'status_id' => $open->id, 'owner_id' => $this->admin->id, 'responsible_id' => null, 'due_date' => now()->subDays(2)]);
        $lateButDone = Ticket::factory()->create(['project_id' => $mine->id, 'status_id' => $done->id, 'owner_id' => $this->admin->id, 'responsible_id' => $other->id, 'due_date' => now()->subDays(9)]);

        // Another Organization's project: invisible, must never be counted.
        $foreign = Project::factory()->create([
            'owner_id' => $other->id,
            'organization_id' => Organization::factory()->create()->id,
        ]);
        Ticket::factory()->count(3)->create([
            'project_id' => $foreign->id, 'status_id' => $open->id, 'owner_id' => $other->id,
            'responsible_id' => null, 'due_date' => now()->subDays(4),
        ]);

        $component = Livewire::test(\App\Filament\Resources\TicketResource\Pages\ListTickets::class)
            ->assertSeeHtml('Semua')
            ->assertSeeHtml('<span class="rk-tabs__count">(3)</span>')      // all: only the 3 visible
            ->assertDontSeeHtml('(6)');

        $component->call('selectTab', 'mine')
            ->assertCanSeeTableRecords([$assigned])
            ->assertCanNotSeeTableRecords([$unassigned, $lateButDone]);

        $component->call('selectTab', 'unassigned')
            ->assertCanSeeTableRecords([$unassigned])
            ->assertCanNotSeeTableRecords([$assigned, $lateButDone]);

        // Overdue = due before today AND not a final status.
        $component->call('selectTab', 'overdue')
            ->assertCanSeeTableRecords([$unassigned])
            ->assertCanNotSeeTableRecords([$assigned, $lateButDone]);

        $component->call('selectTab', 'byMe')
            ->assertCanSeeTableRecords([$unassigned, $lateButDone])
            ->assertCanNotSeeTableRecords([$assigned]);

        // Tab + status card: "Done" within "assigned by me".
        $component->call('applyStatusCard', 'done')
            ->assertCanSeeTableRecords([$lateButDone])
            ->assertCanNotSeeTableRecords([$unassigned]);

        $component->call('applyStatusCard', 'done')
            ->call('selectTab', 'nonsense')
            ->assertSet('activeTab', 'all')
            ->assertCanSeeTableRecords([$assigned, $unassigned, $lateButDone]);
    }

    /**
     * The sub-line under a ticket title shows the type as a small pill built
     * from the TicketType record (name + validated colour), never from raw
     * strings, followed by the project name.
     */
    public function test_the_ticket_name_cell_shows_the_type_as_a_safe_colour_pill(): void
    {
        $type = \App\Models\TicketType::factory()->create(['name' => 'Bug', 'color' => '#ef4444', 'icon' => 'heroicon-o-x']);
        $badType = \App\Models\TicketType::factory()->create(['name' => 'Odd', 'color' => 'red;"><script>alert(1)</script>', 'icon' => 'heroicon-o-does-not-exist']);
        $mine = Project::factory()->create(['owner_id' => $this->admin->id, 'name' => 'Alpha - Site']);
        $ok = Ticket::factory()->create(['project_id' => $mine->id, 'owner_id' => $this->admin->id, 'type_id' => $type->id]);
        $odd = Ticket::factory()->create(['project_id' => $mine->id, 'owner_id' => $this->admin->id, 'type_id' => $badType->id]);

        Livewire::test(\App\Filament\Resources\TicketResource\Pages\ListTickets::class)
            ->assertSuccessful()
            ->assertSeeHtml('<span class="color-pill-label">Bug</span>')
            ->assertSeeHtml('background-color: #ef444426')
            ->assertSeeHtml('<span class="rk-cell-sub-project">Alpha - Site</span>')
            ->assertDontSeeHtml('<script>alert(1)</script>')
            ->assertSeeHtml('<span class="color-pill-label">Odd</span>')
            ->assertCanSeeTableRecords([$ok, $odd]);
    }

    /**
     * Cards, percentages and tab counts always come from the live scoped
     * query: after creating, re-statusing, assigning, deleting and restoring
     * tickets (and with zero tickets) a re-render shows the new numbers.
     */
    public function test_the_ticket_cards_percentages_and_tabs_follow_the_data(): void
    {
        $todo = TicketStatus::factory()->create(['name' => 'Todo', 'project_id' => null, 'order' => 1]);
        $done = TicketStatus::factory()->create(['name' => 'Done', 'project_id' => null, 'order' => 2, 'is_final' => true]);
        $mine = Project::factory()->create(['owner_id' => $this->admin->id]);

        $page = Livewire::test(\App\Filament\Resources\TicketResource\Pages\ListTickets::class)
            ->assertSeeHtml('<span class="rk-stat-cards__value">0</span>')
            ->assertSeeHtml('<span class="rk-stat-cards__percent">0%</span>')   // total 0: no division error
            ->assertSeeHtml('<span class="rk-tabs__count">(0)</span>');

        $a = Ticket::factory()->create(['project_id' => $mine->id, 'status_id' => $todo->id, 'owner_id' => $this->admin->id]);
        $b = Ticket::factory()->create(['project_id' => $mine->id, 'status_id' => $todo->id, 'owner_id' => $this->admin->id]);
        $c = Ticket::factory()->create(['project_id' => $mine->id, 'status_id' => $todo->id, 'owner_id' => $this->admin->id]);
        $d = Ticket::factory()->create(['project_id' => $mine->id, 'status_id' => $done->id, 'owner_id' => $this->admin->id]);

        // 4 total: Todo 3 = 75%, Done 1 = 25%.
        $page->call('$refresh')
            ->assertSeeHtml('<span class="rk-stat-cards__value">4</span>')
            ->assertSeeHtml('<span class="rk-stat-cards__percent">75%</span>')
            ->assertSeeHtml('<span class="rk-stat-cards__percent">25%</span>');

        // Re-status + assign: Todo 2 / Done 2, "Tugas Saya" becomes 1.
        $c->update(['status_id' => $done->id, 'responsible_id' => $this->admin->id]);
        $page->call('$refresh')
            ->assertSeeHtml('<span class="rk-stat-cards__percent">50%</span>')
            ->assertSeeHtml('<span class="rk-tabs__count">(1)</span>');

        // Soft delete one: total 3 (Todo 1 = 33%, Done 2 = 67%); restore: back to 4 (2 / 2 = 50%).
        $a->delete();
        $page->call('$refresh')
            ->assertSeeHtml('<span class="rk-stat-cards__value">3</span>')
            ->assertSeeHtml('<span class="rk-stat-cards__percent">33%</span>')
            ->assertSeeHtml('<span class="rk-stat-cards__percent">67%</span>');

        $a->restore();
        $page->call('$refresh')
            ->assertSeeHtml('<span class="rk-stat-cards__value">4</span>')
            ->assertDontSeeHtml('<span class="rk-stat-cards__percent">33%</span>')
            ->assertSeeHtml('<span class="rk-stat-cards__percent">50%</span>');
    }

    public function test_the_ticket_list_polls_so_counts_follow_outside_changes(): void
    {
        Livewire::test(\App\Filament\Resources\TicketResource\Pages\ListTickets::class)
            ->assertSeeHtml('wire:poll.15s');
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

    public function test_the_ticket_view_page_keeps_actions_out_of_the_header(): void
    {
        $ticket = Ticket::factory()->create(['owner_id' => $this->admin->id]);

        $html = Livewire::test(
            \App\Filament\Resources\TicketResource\Pages\ViewTicket::class,
            ['record' => $ticket->getRouteKey()]
        )->payload['effects']['html'];

        preg_match('#<header[^>]*filament-header.*?</header>#s', $html, $header);

        $this->assertNotEmpty($header);
        $this->assertStringNotContainsString('filament-page-actions', $header[0]);
        $this->assertStringContainsString('rk-td__back', $html);
        $this->assertMatchesRegularExpression('#rk-td__bar.*filament-page-actions#s', $html);
    }

    public function test_the_ticket_view_page_renders_attachments_in_content_and_comments(): void
    {
        $image = '<a href="https://example.test/a.png"><img src="https://example.test/a.png" width="946" height="348" alt="a.png" />'
            .'<span>shot-image.png</span> <span>51.01 KB</span></a>';
        $file = '<a href="https://example.test/doc.pdf"><span>requirement-doc.pdf</span> <span>1.2 MB</span></a>';

        $ticket = Ticket::factory()->create(['owner_id' => $this->admin->id, 'content' => '<p>Body</p>'.$image.$file]);
        TicketComment::create([
            'user_id' => $this->admin->id,
            'ticket_id' => $ticket->id,
            'content' => '<p>Look</p>'.$image.$file,
        ]);

        $html = Livewire::test(
            \App\Filament\Resources\TicketResource\Pages\ViewTicket::class,
            ['record' => $ticket->getRouteKey()]
        )->payload['effects']['html'];

        // Markup is untouched (captions are hidden by CSS, not stripped), so
        // the image canvas and the file chip selectors keep matching.
        $this->assertSame(2, substr_count($html, '<img src="https://example.test/a.png"'));
        $this->assertSame(2, substr_count($html, '<span>requirement-doc.pdf</span>'));
        $this->assertStringContainsString('class="w-full prose max-w-none"', $html);
    }

    // ------------------------------------------------------------------ Users

    public function test_the_user_list_page_renders(): void
    {
        User::factory()->count(3)->create();

        Livewire::test(\App\Filament\Resources\UserResource\Pages\ListUsers::class)
            ->assertSuccessful();
    }

    public function test_the_user_list_query_eager_loads_roles_and_socials_to_avoid_n_plus_one(): void
    {
        $eagerLoads = array_keys(
            \App\Filament\Resources\UserResource::getEloquentQuery()->getEagerLoads()
        );

        $this->assertContains('roles', $eagerLoads);
        $this->assertContains('socials', $eagerLoads);
    }

    public function test_pending_approval_filter_shows_only_users_without_a_role(): void
    {
        // The admin ($this->admin) holds a role; a fresh registrant has none.
        $pending = User::factory()->create();

        Livewire::test(\App\Filament\Resources\UserResource\Pages\ListUsers::class)
            ->assertCanSeeTableRecords([$pending, $this->admin])
            ->filterTable('pending_approval')
            ->assertCanSeeTableRecords([$pending])
            ->assertCanNotSeeTableRecords([$this->admin]);
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

    // -------------------------------------------------------------- Timesheet

    public function test_the_timesheet_list_page_renders(): void
    {
        \App\Models\TicketHour::factory()->count(3)->create(['user_id' => $this->admin->id]);

        Livewire::test(\App\Filament\Resources\TimesheetResource\Pages\ListTimesheet::class)
            ->assertSuccessful();
    }

    public function test_the_timesheet_list_query_eager_loads_user_activity_and_ticket(): void
    {
        $eagerLoads = array_keys(
            \App\Filament\Resources\TimesheetResource::getEloquentQuery()->getEagerLoads()
        );

        $this->assertContains('user', $eagerLoads);
        $this->assertContains('activity', $eagerLoads);
        $this->assertContains('ticket', $eagerLoads);
    }

    // ------------------------------------------------------------------ Roles

    public function test_the_role_list_page_renders(): void
    {
        Livewire::test(\App\Filament\Resources\RoleResource\Pages\ListRoles::class)
            ->assertSuccessful();
    }

    public function test_the_role_list_query_eager_loads_permissions_to_avoid_n_plus_one(): void
    {
        $eagerLoads = array_keys(
            \App\Filament\Resources\RoleResource::getEloquentQuery()->getEagerLoads()
        );

        $this->assertContains('permissions', $eagerLoads);
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

    // -------------------------------------------------------------- Labels

    public function test_the_label_list_page_renders(): void
    {
        \App\Models\Label::factory()->count(3)->create();

        Livewire::test(\App\Filament\Resources\LabelResource\Pages\ListLabels::class)
            ->assertSuccessful();
    }

    public function test_the_label_create_page_renders(): void
    {
        Livewire::test(\App\Filament\Resources\LabelResource\Pages\CreateLabel::class)
            ->assertSuccessful();
    }

    public function test_the_label_edit_page_renders(): void
    {
        $label = \App\Models\Label::factory()->create();

        Livewire::test(
            \App\Filament\Resources\LabelResource\Pages\EditLabel::class,
            ['record' => $label->getRouteKey()]
        )->assertSuccessful();
    }
}
