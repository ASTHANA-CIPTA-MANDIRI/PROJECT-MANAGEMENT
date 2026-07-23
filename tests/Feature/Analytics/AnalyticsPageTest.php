<?php

namespace Tests\Feature\Analytics;

use App\Filament\Pages\Analytics;
use App\Models\Permission;
use App\Models\Project;
use App\Models\Role;
use App\Models\Sprint;
use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AnalyticsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    private function userWithAnalytics(): User
    {
        Permission::firstOrCreate(['name' => 'View analytics']);
        $role = Role::create(['name' => 'r_'.uniqid()]);
        $role->syncPermissions(['View analytics']);

        $user = User::factory()->create();
        $user->syncRoles([$role]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    public function test_the_page_renders_for_an_authorized_user(): void
    {
        $user = $this->userWithAnalytics();
        Project::factory()->create(['owner_id' => $user->id]);

        $this->actingAs($user);

        Livewire::test(Analytics::class)->assertSuccessful();
    }

    public function test_it_renders_with_full_analytics_data(): void
    {
        $user = $this->userWithAnalytics();
        $this->actingAs($user);

        $todo = TicketStatus::factory()->create(['order' => 1]);
        $done = TicketStatus::factory()->create(['order' => 5]);
        $project = Project::factory()->create(['owner_id' => $user->id]);

        $sprint = Sprint::factory()->ended()->create([
            'project_id' => $project->id,
            'starts_at' => now()->subDays(14),
            'ends_at' => now()->subDays(1),
        ]);
        Ticket::factory()->estimated(5)->create(['project_id' => $project->id, 'sprint_id' => $sprint->id, 'status_id' => $done->id]);
        Ticket::factory()->estimated(8)->create(['project_id' => $project->id, 'status_id' => $todo->id]);

        Livewire::test(Analytics::class)
            ->assertSet('projectId', $project->id)
            ->assertSuccessful()
            ->assertSee('Team velocity')
            ->assertSee('Sprint burn-down')
            ->assertSee('Resource utilization');
    }

    public function test_it_defaults_to_an_accessible_project(): void
    {
        $user = $this->userWithAnalytics();
        $project = Project::factory()->create(['owner_id' => $user->id]);
        Project::factory()->create(); // someone else's, must not be selected

        $this->actingAs($user);

        Livewire::test(Analytics::class)->assertSet('projectId', $project->id);
    }

    public function test_switching_project_resets_the_selected_sprint(): void
    {
        $user = $this->userWithAnalytics();
        $this->actingAs($user);

        $a = Project::factory()->create(['owner_id' => $user->id]);
        $b = Project::factory()->create(['owner_id' => $user->id]);
        $sprintB = Sprint::factory()->create(['project_id' => $b->id]);

        Livewire::test(Analytics::class)
            ->set('projectId', $b->id)
            ->assertSet('sprintId', $sprintB->id);
    }

    public function test_the_page_renders_cleanly_for_a_project_without_data(): void
    {
        $user = $this->userWithAnalytics();
        Project::factory()->create(['owner_id' => $user->id]);
        $this->actingAs($user);

        Livewire::test(Analytics::class)
            ->assertSuccessful()
            ->assertSee('Not enough data'); // forecast fallback
    }
}
