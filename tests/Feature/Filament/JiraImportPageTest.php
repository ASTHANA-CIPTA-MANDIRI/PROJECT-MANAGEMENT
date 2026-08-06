<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\JiraImport;
use App\Jobs\ImportJiraTicketsJob;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\JiraImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * JiraImport's wizard schema (App\Filament\Pages\Forms\JiraImportForm) and API
 * calls (App\Services\JiraImportService) were extracted out of the page. These
 * tests guard that the page still wires them together correctly: the form
 * renders, and its action methods still resolve the injected service through
 * Livewire's method-call DI.
 */
class JiraImportPageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'Import from Jira']);
        $role = Role::create(['name' => 'Jira importer']);
        $role->syncPermissions(['Import from Jira']);

        $this->user = User::factory()->create();
        $this->user->syncRoles([$role]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->user = $this->user->fresh();

        $this->actingAs($this->user);
    }

    public function test_the_page_renders_for_an_authorized_user(): void
    {
        Livewire::test(JiraImport::class)->assertSuccessful();
    }

    public function test_update_jira_projects_fetches_projects_through_the_injected_service(): void
    {
        $projects = [(object) [
            'key' => 'ALP',
            'name' => 'Alpha',
            'avatarUrls' => (object) ['16x16' => 'https://example.atlassian.net/avatar.png'],
        ]];

        $service = Mockery::mock(JiraImportService::class);
        $service->shouldReceive('connect')->once()->andReturn(Mockery::mock(\GuzzleHttp\Client::class));
        $service->shouldReceive('fetchProjects')->once()->andReturn($projects);
        $this->app->instance(JiraImportService::class, $service);

        $component = Livewire::test(JiraImport::class)
            ->set('host', 'https://example.atlassian.net')
            ->set('username', 'user@example.com')
            ->set('token', 'secret')
            ->call('updateJiraProjects');

        $this->assertSame($projects, $component->instance()->getProjects());
        $this->assertFalse($component->instance()->isLoadingProjects());
    }

    public function test_import_dispatches_the_job_with_fetched_ticket_details(): void
    {
        Queue::fake();

        $ticketDetails = (object) ['key' => 'ALP-1'];

        $service = Mockery::mock(JiraImportService::class);
        $service->shouldReceive('fetchTicketDetails')->once()->andReturn($ticketDetails);
        $this->app->instance(JiraImportService::class, $service);

        $component = Livewire::test(JiraImport::class)
            ->set('host', 'https://example.atlassian.net')
            ->set('username', 'user@example.com')
            ->set('token', 'secret')
            ->set('data', ['alp_alp_1' => true])
            ->set('ticketsDataApi', ['alp_alp_1' => 'https://example.atlassian.net/rest/api/2/issue/10001']);

        $component->call('import');

        Queue::assertPushed(ImportJiraTicketsJob::class, 1);
    }
}
