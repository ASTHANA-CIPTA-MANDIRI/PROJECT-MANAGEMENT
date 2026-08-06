<?php

namespace Tests\Feature;

use App\Models\Epic;
use App\Models\Permission;
use App\Models\Project;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\TicketHour;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The road map front-end fetches its rows from this JSON endpoint. It must be
 * reachable for members of the project, and must not leak other projects.
 */
class RoadMapDataTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        foreach (['List projects', 'View project', 'List tickets', 'View ticket'] as $name) {
            Permission::firstOrCreate(['name' => $name]);
        }
        $role = Role::create(['name' => 'Member']);
        $role->syncPermissions(['List projects', 'View project', 'List tickets', 'View ticket']);

        $this->user = User::factory()->create(['email_verified_at' => now()]);
        $this->user->syncRoles([$role]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->user = $this->user->fresh();
    }

    private function url(Project $project): string
    {
        return route('road-map.data', ['project' => $project->id]);
    }

    public function test_a_guest_cannot_fetch_road_map_data(): void
    {
        $project = Project::factory()->create();

        $this->get($this->url($project))->assertRedirect();
    }

    public function test_the_project_owner_can_fetch_road_map_data(): void
    {
        $project = Project::factory()->create(['owner_id' => $this->user->id]);

        $this->actingAs($this->user)
            ->get($this->url($project))
            ->assertSuccessful()
            ->assertJsonStructure([]);
    }

    public function test_a_project_member_can_fetch_road_map_data(): void
    {
        $project = Project::factory()->create();
        $project->users()->attach($this->user->id, ['role' => 'employee']);

        $this->actingAs($this->user)->get($this->url($project))->assertSuccessful();
    }

    public function test_road_map_data_includes_epics(): void
    {
        $project = Project::factory()->create(['owner_id' => $this->user->id]);
        Epic::factory()->count(2)->create(['project_id' => $project->id]);

        $response = $this->actingAs($this->user)->get($this->url($project));

        $response->assertSuccessful();
        $this->assertNotEmpty($response->json());
    }

    public function test_road_map_data_includes_epics_with_tickets(): void
    {
        $project = Project::factory()->create(['owner_id' => $this->user->id]);
        $epic = Epic::factory()->create(['project_id' => $project->id]);
        Ticket::factory()->count(3)->create([
            'project_id' => $project->id,
            'epic_id' => $epic->id,
            'owner_id' => $this->user->id,
        ]);

        $this->actingAs($this->user)->get($this->url($project))->assertSuccessful();
    }

    public function test_road_map_data_handles_a_project_without_epics(): void
    {
        $project = Project::factory()->create(['owner_id' => $this->user->id]);

        $this->actingAs($this->user)->get($this->url($project))->assertSuccessful();
    }

    public function test_road_map_data_eager_loads_ticket_hours_and_responsible(): void
    {
        $project = Project::factory()->create(['owner_id' => $this->user->id]);
        $epic = Epic::factory()->create(['project_id' => $project->id]);
        $epicTickets = Ticket::factory()->count(2)->create([
            'project_id' => $project->id,
            'epic_id' => $epic->id,
            'owner_id' => $this->user->id,
            'responsible_id' => $this->user->id,
        ]);
        $looseTickets = Ticket::factory()->count(2)->create([
            'project_id' => $project->id,
            'epic_id' => null,
            'owner_id' => $this->user->id,
            'responsible_id' => $this->user->id,
        ]);
        foreach ($epicTickets->merge($looseTickets) as $ticket) {
            TicketHour::factory()->create(['ticket_id' => $ticket->id, 'user_id' => $this->user->id]);
        }

        $hourQueries = 0;
        $userQueries = 0;
        DB::listen(function ($query) use (&$hourQueries, &$userQueries) {
            if (str_contains($query->sql, 'select * from "ticket_hours"')) {
                $hourQueries++;
            }
            if (str_contains($query->sql, 'select * from "users" where "users"."id" in')) {
                $userQueries++;
            }
        });

        $this->actingAs($this->user)->get($this->url($project))->assertSuccessful();

        // Two eager-load batches (epic-attached tickets, then the loose ones)
        // instead of one query per ticket (N+1) — constant regardless of how
        // many tickets exist in each group.
        $this->assertSame(2, $hourQueries);
        $this->assertSame(2, $userQueries);
    }

    public function test_road_map_data_handles_nested_epics(): void
    {
        $project = Project::factory()->create(['owner_id' => $this->user->id]);
        $parent = Epic::factory()->create(['project_id' => $project->id]);
        Epic::factory()->count(2)->create([
            'project_id' => $project->id,
            'parent_id' => $parent->id,
        ]);

        $this->actingAs($this->user)->get($this->url($project))->assertSuccessful();
    }
}
