<?php

namespace Tests\Feature\Api;

use App\Models\Permission;
use App\Models\Project;
use App\Models\Role;
use App\Models\Sprint;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SprintApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    private function actingWith(array $permissions = []): User
    {
        foreach ($permissions as $p) {
            Permission::firstOrCreate(['name' => $p]);
        }
        $role = Role::create(['name' => 'r_'.uniqid()]);
        $role->syncPermissions($permissions);

        $user = User::factory()->create();
        $user->syncRoles([$role]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user = $user->fresh();

        Sanctum::actingAs($user);

        return $user;
    }

    public function test_listing_sprints_requires_authentication(): void
    {
        $this->getJson('/api/v1/sprints')->assertUnauthorized();
    }

    public function test_listing_requires_the_list_permission(): void
    {
        $this->actingWith([]);

        $this->getJson('/api/v1/sprints')->assertForbidden();
    }

    public function test_it_lists_sprints_of_accessible_projects(): void
    {
        $user = $this->actingWith(['List sprints']);
        $mine = Project::factory()->create(['owner_id' => $user->id]);
        Sprint::factory()->count(2)->create(['project_id' => $mine->id]);
        Sprint::factory()->create(); // in someone else's project

        $this->getJson('/api/v1/sprints')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure([
                'data' => [['id', 'name', 'project_id', 'starts_at', 'ends_at', 'is_active']],
                'meta',
            ]);
    }

    public function test_it_filters_sprints_by_project(): void
    {
        $user = $this->actingWith(['List sprints']);
        $a = Project::factory()->create(['owner_id' => $user->id]);
        $b = Project::factory()->create(['owner_id' => $user->id]);
        Sprint::factory()->create(['project_id' => $a->id]);
        Sprint::factory()->count(2)->create(['project_id' => $b->id]);

        $this->getJson("/api/v1/sprints?filter[project_id]={$b->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_it_paginates_sprints(): void
    {
        $user = $this->actingWith(['List sprints']);
        $project = Project::factory()->create(['owner_id' => $user->id]);
        Sprint::factory()->count(4)->create(['project_id' => $project->id]);

        $this->getJson('/api/v1/sprints?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 4);
    }

    public function test_it_creates_a_sprint(): void
    {
        $user = $this->actingWith(['Create sprint']);
        $project = Project::factory()->create(['owner_id' => $user->id]);

        $response = $this->postJson('/api/v1/sprints', [
            'name' => 'Sprint 1',
            'project_id' => $project->id,
            'starts_at' => '2026-02-01',
            'ends_at' => '2026-02-14',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Sprint 1')
            ->assertJsonPath('data.project_id', $project->id);

        $this->assertDatabaseHas('sprints', ['name' => 'Sprint 1', 'project_id' => $project->id]);
    }

    public function test_creating_requires_the_create_permission(): void
    {
        $user = $this->actingWith(['List sprints']);
        $project = Project::factory()->create(['owner_id' => $user->id]);

        $this->postJson('/api/v1/sprints', [
            'name' => 'X', 'project_id' => $project->id,
            'starts_at' => '2026-02-01', 'ends_at' => '2026-02-14',
        ])->assertForbidden();
    }

    public function test_it_validates_the_sprint_payload(): void
    {
        $this->actingWith(['Create sprint']);

        $this->postJson('/api/v1/sprints', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'project_id', 'starts_at', 'ends_at']);
    }

    public function test_it_rejects_an_end_date_before_the_start(): void
    {
        $user = $this->actingWith(['Create sprint']);
        $project = Project::factory()->create(['owner_id' => $user->id]);

        $this->postJson('/api/v1/sprints', [
            'name' => 'X', 'project_id' => $project->id,
            'starts_at' => '2026-02-14', 'ends_at' => '2026-02-01',
        ])->assertStatus(422)->assertJsonValidationErrors(['ends_at']);
    }

    public function test_it_shows_a_sprint_of_an_accessible_project(): void
    {
        $user = $this->actingWith(['View sprint']);
        $project = Project::factory()->create(['owner_id' => $user->id]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);

        $this->getJson("/api/v1/sprints/{$sprint->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $sprint->id);
    }

    public function test_it_forbids_showing_a_sprint_of_an_inaccessible_project(): void
    {
        $this->actingWith(['View sprint']);
        $sprint = Sprint::factory()->create();

        $this->getJson("/api/v1/sprints/{$sprint->id}")->assertForbidden();
    }
}
