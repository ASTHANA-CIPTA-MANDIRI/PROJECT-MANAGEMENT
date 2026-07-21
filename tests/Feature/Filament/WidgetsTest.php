<?php

namespace Tests\Feature\Filament;

use App\Models\Permission;
use App\Models\Project;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\TicketHour;
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

    public function test_widgets_render_with_no_data_at_all(): void
    {
        // No seedActivity(): empty aggregations must not blow up.
        Livewire::test(\App\Filament\Widgets\LatestProjects::class)->assertSuccessful();
        Livewire::test(\App\Filament\Widgets\LatestTickets::class)->assertSuccessful();
        Livewire::test(\App\Filament\Widgets\TicketsByType::class)->assertSuccessful();
    }
}
