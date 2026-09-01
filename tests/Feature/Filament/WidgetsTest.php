<?php

namespace Tests\Feature\Filament;

use App\Models\Permission;
use App\Models\Project;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\TicketHour;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Dashboard widgets run their own queries and aggregations. Rendering them
 * against real records catches broken relations and bad column references.
 */
class WidgetsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $names = ['List projects', 'List tickets', 'List activities', 'View timesheet dashboard'];
        foreach ($names as $name) {
            Permission::firstOrCreate(['name' => $name]);
        }
        $role = Role::create(['name' => 'Viewer']);
        $role->syncPermissions($names);

        $this->user = User::factory()->create();
        $this->user->syncRoles([$role]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->user = $this->user->fresh();

        $this->actingAs($this->user);
    }

    /**
     * Give the dashboard something to aggregate.
     */
    private function seedActivity(): void
    {
        $project = Project::factory()->create(['owner_id' => $this->user->id]);
        $this->user->favoriteProjects()->attach($project->id);

        $tickets = Ticket::factory()->count(3)->create([
            'project_id' => $project->id,
            'owner_id' => $this->user->id,
        ]);

        foreach ($tickets as $ticket) {
            TicketHour::factory()->hours(2)->create([
                'ticket_id' => $ticket->id,
                'user_id' => $this->user->id,
            ]);
            TicketComment::factory()->create([
                'ticket_id' => $ticket->id,
                'user_id' => $this->user->id,
            ]);
        }
    }

    /**
     * A second tenant: another user, their own project, ticket and logged
     * hours. Nothing here may surface on $this->user's dashboard.
     */
    private function seedStranger(TicketType $type): array
    {
        $stranger = User::factory()->create(['name' => 'Orang Asing']);
        $project = Project::factory()->create(['owner_id' => $stranger->id]);

        $ticket = Ticket::factory()->create([
            'project_id' => $project->id,
            'owner_id' => $stranger->id,
            'type_id' => $type->id,
        ]);

        TicketHour::factory()->hours(40)->create([
            'ticket_id' => $ticket->id,
            'user_id' => $stranger->id,
        ]);

        return [$stranger, $ticket];
    }

    /**
     * Widget data is computed in a protected method; bind a closure to read it.
     */
    private function widgetData(string $widget): array
    {
        return (fn () => $this->getData())->call(new $widget);
    }

    public function test_the_favorite_projects_widget_renders(): void
    {
        $this->seedActivity();

        Livewire::test(\App\Filament\Widgets\FavoriteProjects::class)->assertSuccessful();
    }

    public function test_the_latest_projects_widget_renders(): void
    {
        $this->seedActivity();

        Livewire::test(\App\Filament\Widgets\LatestProjects::class)->assertSuccessful();
    }

    public function test_the_latest_tickets_widget_renders(): void
    {
        $this->seedActivity();

        Livewire::test(\App\Filament\Widgets\LatestTickets::class)->assertSuccessful();
    }

    public function test_the_latest_comments_widget_renders(): void
    {
        $this->seedActivity();

        Livewire::test(\App\Filament\Widgets\LatestComments::class)->assertSuccessful();
    }

    public function test_the_latest_activities_widget_renders(): void
    {
        $this->seedActivity();

        Livewire::test(\App\Filament\Widgets\LatestActivities::class)->assertSuccessful();
    }

    public function test_the_tickets_by_type_widget_renders(): void
    {
        $this->seedActivity();

        Livewire::test(\App\Filament\Widgets\TicketsByType::class)->assertSuccessful();
    }

    public function test_the_tickets_by_priority_widget_renders(): void
    {
        $this->seedActivity();

        Livewire::test(\App\Filament\Widgets\TicketsByPriority::class)->assertSuccessful();
    }

    public function test_the_ticket_time_logged_widget_renders(): void
    {
        $this->seedActivity();

        Livewire::test(\App\Filament\Widgets\TicketTimeLogged::class)->assertSuccessful();
    }

    public function test_the_user_time_logged_widget_renders(): void
    {
        $this->seedActivity();

        Livewire::test(\App\Filament\Widgets\UserTimeLogged::class)->assertSuccessful();
    }

    public function test_the_tickets_by_type_chart_only_counts_accessible_tickets(): void
    {
        $type = TicketType::factory()->create(['name' => 'Bug bersama']);
        $project = Project::factory()->create(['owner_id' => $this->user->id]);
        Ticket::factory()->create([
            'project_id' => $project->id,
            'owner_id' => $this->user->id,
            'type_id' => $type->id,
        ]);

        // Three more tickets of the same type, in a project we cannot reach.
        [$stranger] = $this->seedStranger($type);
        Ticket::factory()->count(2)->create([
            'project_id' => $stranger->projectsOwning()->first()->id,
            'owner_id' => $stranger->id,
            'type_id' => $type->id,
        ]);

        $data = $this->widgetData(\App\Filament\Widgets\TicketsByType::class);
        $index = array_search($type->name, $data['labels'], true);

        $this->assertNotFalse($index);
        $this->assertSame(1, $data['datasets'][0]['data'][$index]);
    }

    public function test_the_ticket_time_logged_chart_hides_tickets_from_other_projects(): void
    {
        $this->seedActivity();
        [, $strangerTicket] = $this->seedStranger(TicketType::factory()->create());

        $data = $this->widgetData(\App\Filament\Widgets\TicketTimeLogged::class);

        $this->assertNotEmpty($data['labels']);
        $this->assertNotContains($strangerTicket->code, $data['labels']);
    }

    public function test_the_user_time_logged_chart_hides_users_from_other_projects(): void
    {
        $this->seedActivity();
        [$stranger] = $this->seedStranger(TicketType::factory()->create());

        $data = $this->widgetData(\App\Filament\Widgets\UserTimeLogged::class);

        $this->assertContains($this->user->name, $data['labels']);
        $this->assertNotContains($stranger->name, $data['labels']);
    }

    public function test_the_user_time_logged_chart_shows_members_of_a_shared_project(): void
    {
        $project = Project::factory()->create(['owner_id' => $this->user->id]);
        $colleague = User::factory()->create(['name' => 'Rekan Proyek']);
        $project->users()->attach($colleague->id, ['role' => 'member']);

        $ticket = Ticket::factory()->create([
            'project_id' => $project->id,
            'owner_id' => $this->user->id,
        ]);
        TicketHour::factory()->hours(3)->create([
            'ticket_id' => $ticket->id,
            'user_id' => $colleague->id,
        ]);

        $data = $this->widgetData(\App\Filament\Widgets\UserTimeLogged::class);

        $this->assertContains($colleague->name, $data['labels']);
    }

    public function test_widget_data_is_cached_per_user_not_globally(): void
    {
        $this->seedActivity();
        [$stranger, $strangerTicket] = $this->seedStranger(TicketType::factory()->create());

        // Warm the cache as the first user, then look again as the stranger:
        // a shared cache key would hand over the first user's ticket codes.
        $mine = $this->widgetData(\App\Filament\Widgets\TicketTimeLogged::class);

        $this->actingAs($stranger);
        $theirs = $this->widgetData(\App\Filament\Widgets\TicketTimeLogged::class);

        $this->assertSame([$strangerTicket->code], $theirs['labels']);
        $this->assertEmpty(array_intersect($mine['labels'], $theirs['labels']));
    }

    public function test_widgets_render_with_no_data_at_all(): void
    {
        // No seedActivity(): empty aggregations must not blow up.
        Livewire::test(\App\Filament\Widgets\LatestProjects::class)->assertSuccessful();
        Livewire::test(\App\Filament\Widgets\LatestTickets::class)->assertSuccessful();
        Livewire::test(\App\Filament\Widgets\TicketsByType::class)->assertSuccessful();
    }

    // -------------------------------------------- widget cache invalidation (M-4)

    /**
     * TicketsByType/TicketsByPriority (TicketsGroupedByChartWidget) cache their
     * counts for an hour. A new ticket of a type must show up immediately, not
     * after the TTL expires.
     */
    public function test_tickets_by_type_widget_reflects_a_new_ticket_without_waiting_for_ttl(): void
    {
        $type = TicketType::factory()->create(['name' => 'Bug hangat']);
        $project = Project::factory()->create(['owner_id' => $this->user->id]);
        Ticket::factory()->create([
            'project_id' => $project->id,
            'owner_id' => $this->user->id,
            'type_id' => $type->id,
        ]);

        $before = $this->widgetData(\App\Filament\Widgets\TicketsByType::class);
        $indexBefore = array_search($type->name, $before['labels'], true);
        $this->assertSame(1, $before['datasets'][0]['data'][$indexBefore]);

        Ticket::factory()->create([
            'project_id' => $project->id,
            'owner_id' => $this->user->id,
            'type_id' => $type->id,
        ]);

        $after = $this->widgetData(\App\Filament\Widgets\TicketsByType::class);
        $indexAfter = array_search($type->name, $after['labels'], true);

        $this->assertSame(2, $after['datasets'][0]['data'][$indexAfter]);
    }

    /**
     * Changing a ticket's type (Filament resource "update") must not leave the
     * chart showing the ticket under its old type for up to an hour.
     */
    public function test_tickets_by_type_widget_reflects_a_tickets_type_being_changed(): void
    {
        $oldType = TicketType::factory()->create(['name' => 'Tipe lama']);
        $newType = TicketType::factory()->create(['name' => 'Tipe baru']);
        $project = Project::factory()->create(['owner_id' => $this->user->id]);
        $ticket = Ticket::factory()->create([
            'project_id' => $project->id,
            'owner_id' => $this->user->id,
            'type_id' => $oldType->id,
        ]);

        $before = $this->widgetData(\App\Filament\Widgets\TicketsByType::class);
        $this->assertSame(1, $before['datasets'][0]['data'][array_search($oldType->name, $before['labels'], true)]);

        $ticket->update(['type_id' => $newType->id]);

        $after = $this->widgetData(\App\Filament\Widgets\TicketsByType::class);
        $this->assertSame(0, $after['datasets'][0]['data'][array_search($oldType->name, $after['labels'], true)]);
        $this->assertSame(1, $after['datasets'][0]['data'][array_search($newType->name, $after['labels'], true)]);
    }

    /**
     * TicketTimeLogged/UserTimeLogged (TimeLoggedChartWidget) cache their sums
     * for an hour. Logging (or deleting) hours must show up immediately.
     */
    public function test_time_logged_widget_reflects_newly_logged_hours_without_waiting_for_ttl(): void
    {
        $project = Project::factory()->create(['owner_id' => $this->user->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'owner_id' => $this->user->id]);
        TicketHour::factory()->hours(2)->create(['ticket_id' => $ticket->id, 'user_id' => $this->user->id]);

        $before = $this->widgetData(\App\Filament\Widgets\TicketTimeLogged::class);
        $indexBefore = array_search($ticket->code, $before['labels'], true);
        $this->assertSame(2.0, $before['datasets'][0]['data'][$indexBefore]);

        TicketHour::factory()->hours(3)->create(['ticket_id' => $ticket->id, 'user_id' => $this->user->id]);

        $after = $this->widgetData(\App\Filament\Widgets\TicketTimeLogged::class);
        $indexAfter = array_search($ticket->code, $after['labels'], true);

        $this->assertSame(5.0, $after['datasets'][0]['data'][$indexAfter]);
    }
}
