<?php

namespace Tests\Feature\Analytics;

use App\Filament\Pages\Analytics;
use App\Models\Permission;
use App\Models\Project;
use App\Models\Role;
use App\Models\Sprint;
use App\Models\Ticket;
use App\Models\TicketHour;
use App\Models\TicketStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    /**
     * currentProject() is memoized per request because every report on the
     * page calls it. The memo has to be dropped when the selection changes,
     * or the page would keep reporting on the project that was selected
     * before - including after a switch to one the user may not see.
     */
    public function test_switching_project_re_resolves_the_current_project(): void
    {
        $user = $this->userWithAnalytics();
        $this->actingAs($user);

        $a = Project::factory()->create(['owner_id' => $user->id]);
        $b = Project::factory()->create(['owner_id' => $user->id]);

        $page = Livewire::test(Analytics::class)->set('projectId', $a->id);
        $this->assertSame($a->id, $page->instance()->currentProject()->id);

        $page->set('projectId', $b->id);
        $this->assertSame($b->id, $page->instance()->currentProject()->id);
    }

    public function test_the_selected_project_is_looked_up_once_per_render(): void
    {
        $user = $this->userWithAnalytics();
        $this->actingAs($user);
        $project = Project::factory()->create(['owner_id' => $user->id]);

        // Driven directly rather than through Livewire::test(), whose mount
        // would resolve the project before the measurement even starts.
        $page = new Analytics;
        $page->projectId = $project->id;

        DB::connection()->flushQueryLog();
        DB::connection()->enableQueryLog();

        // What one render does: every report resolves the project first.
        $page->velocity();
        $page->burndown();
        $page->utilization();
        $page->forecast();
        $page->sprintOptions();

        $lookups = collect(DB::connection()->getQueryLog())
            ->filter(fn (array $query) => str_contains($query['query'], 'from "projects"'))
            ->count();
        DB::connection()->disableQueryLog();

        $this->assertSame(1, $lookups, "Expected one project lookup per render, ran {$lookups}");
    }

    /**
     * Builds a project belonging to somebody else, with enough data that any
     * leak is visible: outstanding estimation, a closed sprint, and logged
     * hours attributed to a named person.
     */
    private function someoneElsesProjectWithData(): Project
    {
        $stranger = User::factory()->create(['name' => 'Stranger Danger']);
        $project = Project::factory()->create(['owner_id' => $stranger->id]);

        $todo = TicketStatus::factory()->create(['order' => 1]);
        $done = TicketStatus::factory()->create(['order' => 5]);

        $sprint = Sprint::factory()->ended()->create([
            'project_id' => $project->id,
            'starts_at' => now()->subDays(14),
            'ends_at' => now()->subDays(1),
        ]);

        Ticket::factory()->estimated(5)->create([
            'project_id' => $project->id,
            'sprint_id' => $sprint->id,
            'status_id' => $done->id,
        ]);
        $open = Ticket::factory()->estimated(15)->create([
            'project_id' => $project->id,
            'status_id' => $todo->id,
        ]);

        TicketHour::factory()->hours(7.5)->create([
            'ticket_id' => $open->id,
            'user_id' => $stranger->id,
        ]);

        return $project;
    }

    public function test_it_refuses_a_project_the_user_cannot_access(): void
    {
        $user = $this->userWithAnalytics();
        Project::factory()->create(['owner_id' => $user->id]);
        $theirs = $this->someoneElsesProjectWithData();

        $this->actingAs($user);

        $page = Livewire::test(Analytics::class)->set('projectId', $theirs->id);

        // The selection itself is discarded, not merely ignored downstream.
        $page->assertSet('projectId', null);
        $this->assertNull($page->instance()->currentProject());
    }

    public function test_it_leaks_no_report_data_for_an_inaccessible_project(): void
    {
        $user = $this->userWithAnalytics();
        Project::factory()->create(['owner_id' => $user->id]);
        $theirs = $this->someoneElsesProjectWithData();

        $this->actingAs($user);

        $page = Livewire::test(Analytics::class)->set('projectId', $theirs->id)->instance();

        $this->assertSame([], $page->velocity());
        // The proof-of-concept for this bug read 15.0 here.
        $this->assertEquals(0, $page->forecast()['remaining_points']);
        $this->assertFalse($page->forecast()['confident']);

        // Resource utilization carries people's names and logged hours.
        $this->assertTrue($page->utilization()->isEmpty());

        $this->assertSame(
            ['total' => 0, 'labels' => [], 'ideal' => [], 'remaining' => []],
            $page->burndown()
        );
        $this->assertTrue($page->sprintOptions()->isEmpty());
    }

    public function test_a_stranger_sprint_cannot_be_burned_down_through_the_sprint_id(): void
    {
        $user = $this->userWithAnalytics();
        $mine = Project::factory()->create(['owner_id' => $user->id]);
        $theirs = $this->someoneElsesProjectWithData();
        $theirSprint = $theirs->sprints()->first();

        $this->actingAs($user);

        $page = Livewire::test(Analytics::class)
            ->assertSet('projectId', $mine->id)
            ->set('sprintId', $theirSprint->id)
            ->instance();

        $this->assertSame(
            ['total' => 0, 'labels' => [], 'ideal' => [], 'remaining' => []],
            $page->burndown()
        );
    }

    public function test_a_project_member_still_sees_their_project(): void
    {
        $user = $this->userWithAnalytics();
        $project = Project::factory()->create(); // owned by somebody else
        $project->users()->attach($user->id, ['role' => 'developer']);

        $this->actingAs($user);

        $page = Livewire::test(Analytics::class)->set('projectId', $project->id);

        $page->assertSet('projectId', $project->id);
        $this->assertTrue($project->is($page->instance()->currentProject()));
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
