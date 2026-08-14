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

    public function test_project_cover_url_markup_is_escaped_in_the_project_list(): void
    {
        // No media uploaded, so Project::cover() falls back to a ui-avatars
        // URL built from the raw name, which is what formatStateUsing prints.
        Project::factory()->create([
            'owner_id' => $this->user->id,
            'name' => self::PAYLOAD,
        ]);

        Livewire::test(\App\Filament\Resources\ProjectResource\Pages\ListProjects::class)
            ->assertSuccessful()
            ->assertDontSeeHtml(self::PAYLOAD);
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

    public function test_project_name_markup_is_escaped_in_the_kanban_board_heading(): void
    {
        $project = Project::factory()->create([
            'owner_id' => $this->user->id,
            'name' => self::PAYLOAD,
        ]);

        Livewire::test(\App\Filament\Pages\Kanban::class, ['project' => $project])
            ->assertSuccessful()
            ->assertDontSeeHtml(self::PAYLOAD)
            ->assertSee(self::PAYLOAD);
    }

    /**
     * The Jira wizard is the one place where the rendered values come from a
     * remote server the user chose, so a hostile "Jira" is enough to reach the
     * panel — no compromise of a real Jira required.
     */
    private function allowJiraImport(): void
    {
        Permission::firstOrCreate(['name' => 'Import from Jira']);
        $this->user->roles()->first()->givePermissionTo('Import from Jira');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->user = $this->user->fresh();
        $this->actingAs($this->user);
    }

    public function test_jira_project_name_key_and_avatar_are_escaped_in_the_import_wizard(): void
    {
        $this->allowJiraImport();

        $projects = [(object) [
            'key' => self::PAYLOAD,
            'name' => self::PAYLOAD,
            // Breaking out of src='...' needs only an apostrophe.
            'avatarUrls' => (object) ['16x16' => "https://evil.example.com/a.png' onerror='alert(1)"],
        ]];

        $service = \Mockery::mock(\App\Services\JiraImportService::class);
        $service->shouldReceive('connect')->andReturn(\Mockery::mock(\GuzzleHttp\Client::class));
        $service->shouldReceive('fetchProjects')->andReturn($projects);
        $this->app->instance(\App\Services\JiraImportService::class, $service);

        Livewire::test(\App\Filament\Pages\JiraImport::class)
            ->set('host', 'https://example.atlassian.net')
            ->set('username', 'user@example.com')
            ->set('token', 'secret')
            ->call('updateJiraProjects')
            ->assertSuccessful()
            ->assertDontSeeHtml(self::PAYLOAD)
            ->assertDontSeeHtml("onerror='alert(1)")
            ->assertSee(self::PAYLOAD);
    }

    public function test_a_non_https_jira_avatar_is_not_rendered_at_all(): void
    {
        $this->allowJiraImport();

        $projects = [(object) [
            'key' => 'ALP',
            'name' => 'Alpha',
            'avatarUrls' => (object) ['16x16' => 'javascript:alert(1)'],
        ]];

        $service = \Mockery::mock(\App\Services\JiraImportService::class);
        $service->shouldReceive('connect')->andReturn(\Mockery::mock(\GuzzleHttp\Client::class));
        $service->shouldReceive('fetchProjects')->andReturn($projects);
        $this->app->instance(\App\Services\JiraImportService::class, $service);

        Livewire::test(\App\Filament\Pages\JiraImport::class)
            ->set('host', 'https://example.atlassian.net')
            ->set('username', 'user@example.com')
            ->set('token', 'secret')
            ->call('updateJiraProjects')
            ->assertSuccessful()
            ->assertDontSee('javascript:alert(1)');
    }

    public function test_jira_issue_code_and_name_are_escaped_in_the_import_wizard(): void
    {
        $this->allowJiraImport();

        $tickets = ['ALP' => [
            'total' => 1,
            'issues' => [[
                'code' => 'ALP-1',
                'name' => self::PAYLOAD,
                'data' => (object) ['self' => 'https://example.atlassian.net/rest/api/2/issue/10001'],
            ]],
        ]];

        $service = \Mockery::mock(\App\Services\JiraImportService::class);
        $service->shouldReceive('connect')->andReturn(\Mockery::mock(\GuzzleHttp\Client::class));
        $service->shouldReceive('fetchTicketsByProject')->andReturn($tickets);
        $this->app->instance(\App\Services\JiraImportService::class, $service);

        Livewire::test(\App\Filament\Pages\JiraImport::class)
            ->set('host', 'https://example.atlassian.net')
            ->set('username', 'user@example.com')
            ->set('token', 'secret')
            ->set('selected_projects', ['ALP'])
            ->call('updateJiraTickets')
            ->assertSuccessful()
            ->assertDontSeeHtml(self::PAYLOAD)
            ->assertSee(self::PAYLOAD);
    }

    public function test_project_and_sprint_names_are_escaped_in_the_scrum_board_heading(): void
    {
        $project = Project::factory()->scrum()->create([
            'owner_id' => $this->user->id,
            'name' => self::PAYLOAD,
        ]);
        \App\Models\Sprint::factory()->started()->create([
            'project_id' => $project->id,
            'name' => self::PAYLOAD,
        ]);

        Livewire::test(\App\Filament\Pages\Scrum::class, ['project' => $project->fresh()])
            ->assertSuccessful()
            ->assertDontSeeHtml(self::PAYLOAD)
            ->assertSee(self::PAYLOAD);
    }
}
