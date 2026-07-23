<?php

namespace Tests\Feature\Api;

use App\Models\Permission;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ProjectApiTest extends TestCase
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

    // ------------------------------------------------------------- auth

    public function test_listing_projects_requires_authentication(): void
    {
        $this->getJson('/api/v1/projects')->assertUnauthorized();
    }

    public function test_listing_requires_the_list_permission(): void
    {
        $this->actingWith([]); // authenticated but no permission

        $this->getJson('/api/v1/projects')->assertForbidden();
    }

    // ------------------------------------------------------------- index

    public function test_it_lists_projects_the_user_can_access(): void
    {
        $user = $this->actingWith(['List projects']);
        Project::factory()->count(2)->create(['owner_id' => $user->id]);
        Project::factory()->create(); // owned by someone else

        $response = $this->getJson('/api/v1/projects');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure([
                'data' => [['id', 'name', 'ticket_prefix', 'type', 'owner_id']],
                'links',
                'meta' => ['current_page', 'per_page', 'total'],
            ]);
    }

    public function test_it_includes_projects_the_user_is_a_member_of(): void
    {
        $user = $this->actingWith(['List projects']);
        $project = Project::factory()->create();
        $project->users()->attach($user->id, ['role' => 'employee']);

        $this->getJson('/api/v1/projects')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $project->id);
    }

    // -------------------------------------------------------- pagination

    public function test_it_paginates_with_per_page(): void
    {
        $user = $this->actingWith(['List projects']);
        Project::factory()->count(5)->create(['owner_id' => $user->id]);

        $this->getJson('/api/v1/projects?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 5);
    }

    public function test_per_page_is_capped(): void
    {
        $user = $this->actingWith(['List projects']);
        Project::factory()->count(3)->create(['owner_id' => $user->id]);

        // Requesting an absurd page size is clamped, not honoured verbatim.
        $this->getJson('/api/v1/projects?per_page=99999')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100);
    }

    // ----------------------------------------------------------- filtering

    public function test_it_filters_by_type(): void
    {
        $user = $this->actingWith(['List projects']);
        Project::factory()->create(['owner_id' => $user->id, 'type' => 'kanban']);
        Project::factory()->scrum()->create(['owner_id' => $user->id]);

        $this->getJson('/api/v1/projects?filter[type]=scrum')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'scrum');
    }

    // ------------------------------------------------------------ sorting

    public function test_it_sorts_by_name(): void
    {
        $user = $this->actingWith(['List projects']);
        Project::factory()->create(['owner_id' => $user->id, 'name' => 'Zeta']);
        Project::factory()->create(['owner_id' => $user->id, 'name' => 'Alpha']);

        $this->getJson('/api/v1/projects?sort=name')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Alpha');
    }

    // ------------------------------------------------------------- store

    public function test_it_creates_a_project(): void
    {
        $user = $this->actingWith(['Create project']);
        $status = ProjectStatus::factory()->create();

        $payload = [
            'name' => 'API Project',
            'ticket_prefix' => 'API',
            'status_id' => $status->id,
            'type' => 'kanban',
            'status_type' => 'default',
        ];

        $response = $this->postJson('/api/v1/projects', $payload);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'API Project')
            ->assertJsonPath('data.owner_id', $user->id); // defaulted to caller

        $this->assertDatabaseHas('projects', ['name' => 'API Project', 'owner_id' => $user->id]);
    }

    public function test_creating_requires_the_create_permission(): void
    {
        $this->actingWith(['List projects']); // wrong permission
        $status = ProjectStatus::factory()->create();

        $this->postJson('/api/v1/projects', [
            'name' => 'Nope', 'ticket_prefix' => 'NOP',
            'status_id' => $status->id, 'type' => 'kanban', 'status_type' => 'default',
        ])->assertForbidden();
    }

    public function test_it_validates_the_payload(): void
    {
        $this->actingWith(['Create project']);

        $this->postJson('/api/v1/projects', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'ticket_prefix', 'status_id', 'type', 'status_type']);
    }

    public function test_it_rejects_a_too_long_prefix(): void
    {
        $this->actingWith(['Create project']);
        $status = ProjectStatus::factory()->create();

        $this->postJson('/api/v1/projects', [
            'name' => 'X', 'ticket_prefix' => 'TOOLONG',
            'status_id' => $status->id, 'type' => 'kanban', 'status_type' => 'default',
        ])->assertStatus(422)->assertJsonValidationErrors(['ticket_prefix']);
    }

    // -------------------------------------------------------------- show

    public function test_it_shows_a_project_the_user_owns(): void
    {
        $user = $this->actingWith(['View project']);
        $project = Project::factory()->create(['owner_id' => $user->id]);

        $this->getJson("/api/v1/projects/{$project->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $project->id);
    }

    public function test_it_forbids_showing_a_project_the_user_cannot_access(): void
    {
        $this->actingWith(['View project']);
        $project = Project::factory()->create();

        $this->getJson("/api/v1/projects/{$project->id}")->assertForbidden();
    }

    public function test_it_returns_404_for_a_missing_project(): void
    {
        $this->actingWith(['View project']);

        $this->getJson('/api/v1/projects/999999')
            ->assertNotFound()
            ->assertJsonStructure(['message']);
    }
}
