<?php

namespace Tests\Feature\Filament;

use App\Models\Permission;
use App\Models\Project;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\TicketPriority;
use App\Models\TicketStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Ticket/project/status/priority names and colors are printed as raw HTML
 * (new HtmlString(...)) in several tables and dashboard widgets. A record
 * whose name/color contains markup must come out escaped everywhere it is
 * rendered, the same way Label already handles it.
 */
class XssEscapingTest extends TestCase
{
    use RefreshDatabase;

    private const PAYLOAD = '<img src=x onerror=alert(1)>';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $names = ['List projects', 'List tickets', 'View project', 'View ticket'];
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

    public function test_ticket_status_and_priority_markup_is_escaped_in_the_ticket_list(): void
    {
        $status = TicketStatus::factory()->create(['name' => self::PAYLOAD]);
        $priority = TicketPriority::factory()->create(['name' => self::PAYLOAD]);
        Ticket::factory()->create([
            'owner_id' => $this->user->id,
            'status_id' => $status->id,
            'priority_id' => $priority->id,
        ]);

        Livewire::test(\App\Filament\Resources\TicketResource\Pages\ListTickets::class)
            ->assertSuccessful()
            ->assertDontSeeHtml(self::PAYLOAD)
            ->assertSee(self::PAYLOAD);
    }

    public function test_project_status_markup_is_escaped_in_the_project_list(): void
    {
        $status = \App\Models\ProjectStatus::factory()->create(['name' => self::PAYLOAD]);
        Project::factory()->create([
            'owner_id' => $this->user->id,
            'status_id' => $status->id,
        ]);

        Livewire::test(\App\Filament\Resources\ProjectResource\Pages\ListProjects::class)
            ->assertSuccessful()
            ->assertDontSeeHtml(self::PAYLOAD)
            ->assertSee(self::PAYLOAD);
    }

    public function test_ticket_name_and_status_markup_is_escaped_in_the_latest_tickets_widget(): void
    {
        $status = TicketStatus::factory()->create(['name' => self::PAYLOAD]);
        $project = Project::factory()->create(['owner_id' => $this->user->id, 'name' => self::PAYLOAD]);
        Ticket::factory()->create([
            'name' => self::PAYLOAD,
            'owner_id' => $this->user->id,
            'project_id' => $project->id,
            'status_id' => $status->id,
        ]);

        Livewire::test(\App\Filament\Widgets\LatestTickets::class)
            ->assertSuccessful()
            ->assertDontSeeHtml(self::PAYLOAD)
            ->assertSee(self::PAYLOAD);
    }

    public function test_project_name_markup_is_escaped_in_the_latest_projects_and_favorites_widgets(): void
    {
        $project = Project::factory()->create(['owner_id' => $this->user->id, 'name' => self::PAYLOAD]);
        $this->user->favoriteProjects()->attach($project->id);

        Livewire::test(\App\Filament\Widgets\LatestProjects::class)
            ->assertSuccessful()
            ->assertDontSeeHtml(self::PAYLOAD)
            ->assertSee(self::PAYLOAD);

        Livewire::test(\App\Filament\Widgets\FavoriteProjects::class)
            ->assertSuccessful()
            ->assertDontSeeHtml(self::PAYLOAD)
            ->assertSee(self::PAYLOAD);
    }
}
