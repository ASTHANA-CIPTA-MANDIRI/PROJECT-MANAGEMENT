<?php

namespace Tests\Feature\Api;

use App\Models\Permission;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\Role;
use App\Models\Sprint;
use App\Models\Ticket;
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

    public function test_creating_ignores_a_spoofed_owner_id(): void
    {
        $user = $this->actingWith(['Create project']);
        $status = ProjectStatus::factory()->create();
        $victim = User::factory()->create();

        $this->postJson('/api/v1/projects', [
            'name' => 'Spoofed',
            'ticket_prefix' => 'SPF',
            'status_id' => $status->id,
            'type' => 'kanban',
            'status_type' => 'default',
            'owner_id' => $victim->id, // attempt to attribute to someone else
        ])
            ->assertCreated()
            ->assertJsonPath('data.owner_id', $user->id); // forced back to caller

        $this->assertDatabaseHas('projects', ['name' => 'Spoofed', 'owner_id' => $user->id]);
        $this->assertDatabaseMissing('projects', ['name' => 'Spoofed', 'owner_id' => $victim->id]);
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

    // ------------------------------------------------------------- update

    /**
     * A complete body for a PUT, built from the project it replaces.
     */
    private function fullPayload(Project $project, array $overrides = []): array
    {
        return array_merge([
            'name' => $project->name,
            'ticket_prefix' => $project->ticket_prefix,
            'status_id' => $project->status_id,
            'type' => $project->type,
            'status_type' => $project->status_type,
        ], $overrides);
    }

    public function test_updating_requires_authentication(): void
    {
        $project = Project::factory()->create();

        $this->putJson("/api/v1/projects/{$project->id}", ['name' => 'X'])->assertUnauthorized();
    }

    public function test_it_updates_a_project_the_user_owns(): void
    {
        $user = $this->actingWith(['Update project']);
        $project = Project::factory()->create(['owner_id' => $user->id, 'name' => 'Old']);

        $this->putJson("/api/v1/projects/{$project->id}", $this->fullPayload($project, ['name' => 'New']))
            ->assertOk()
            ->assertJsonPath('data.id', $project->id)
            ->assertJsonPath('data.name', 'New');

        $this->assertDatabaseHas('projects', ['id' => $project->id, 'name' => 'New']);
    }

    public function test_updating_requires_the_update_permission(): void
    {
        $user = $this->actingWith(['View project']); // wrong permission
        $project = Project::factory()->create(['owner_id' => $user->id]);

        $this->putJson("/api/v1/projects/{$project->id}", $this->fullPayload($project, ['name' => 'Nope']))
            ->assertForbidden();

        $this->assertDatabaseMissing('projects', ['id' => $project->id, 'name' => 'Nope']);
    }

    public function test_a_plain_member_cannot_update_the_project(): void
    {
        // Holding "Update project" is not enough: the project itself is only
        // managed by its owner and its administrators.
        $user = $this->actingWith(['Update project']);
        $project = Project::factory()->create();
        $project->users()->attach($user->id, ['role' => 'employee']);

        $this->putJson("/api/v1/projects/{$project->id}", $this->fullPayload($project, ['name' => 'Nope']))
            ->assertForbidden();
    }

    public function test_an_outsider_is_refused_before_the_payload_is_validated(): void
    {
        // A half-filled body must still answer 403, not 422: the validation
        // errors would otherwise tell an outsider who is on the project and
        // which ticket prefixes are taken.
        $this->actingWith(['Update project']);
        $project = Project::factory()->create();

        $this->putJson("/api/v1/projects/{$project->id}", ['name' => 'Nope'])->assertForbidden();
    }

    public function test_a_managing_member_can_update_the_project(): void
    {
        $user = $this->actingWith(['Update project']);
        $project = Project::factory()->create();
        $project->users()->attach($user->id, ['role' => 'administrator']);

        $this->putJson("/api/v1/projects/{$project->id}", $this->fullPayload($project, ['name' => 'Managed']))
            ->assertOk()
            ->assertJsonPath('data.name', 'Managed');
    }

    public function test_a_patch_changes_only_the_fields_it_carries(): void
    {
        $user = $this->actingWith(['Update project']);
        $project = Project::factory()->create([
            'owner_id' => $user->id,
            'name' => 'Old',
            'ticket_prefix' => 'OLD',
            'type' => 'kanban',
        ]);

        $this->patchJson("/api/v1/projects/{$project->id}", ['name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed');

        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'name' => 'Renamed',
            'ticket_prefix' => 'OLD', // untouched
            'type' => 'kanban',
        ]);
    }

    public function test_a_full_update_keeps_the_owner_when_it_is_omitted(): void
    {
        $owner = User::factory()->create();
        $manager = $this->actingWith(['Update project']);
        $project = Project::factory()->create(['owner_id' => $owner->id]);
        $project->users()->attach($manager->id, ['role' => 'administrator']);

        $this->putJson("/api/v1/projects/{$project->id}", $this->fullPayload($project, ['name' => 'Kept']))
            ->assertOk()
            ->assertJsonPath('data.owner_id', $owner->id); // not the caller

        $this->assertDatabaseHas('projects', ['id' => $project->id, 'owner_id' => $owner->id]);
    }

    public function test_it_refuses_to_hand_the_project_to_a_stranger(): void
    {
        $user = $this->actingWith(['Update project']);
        $project = Project::factory()->create(['owner_id' => $user->id]);
        $stranger = User::factory()->create();

        $this->putJson("/api/v1/projects/{$project->id}", $this->fullPayload($project, [
            'owner_id' => $stranger->id,
        ]))->assertStatus(422)->assertJsonValidationErrors(['owner_id']);

        $this->assertDatabaseHas('projects', ['id' => $project->id, 'owner_id' => $user->id]);
    }

    public function test_it_hands_the_project_to_a_member(): void
    {
        $user = $this->actingWith(['Update project']);
        $project = Project::factory()->create(['owner_id' => $user->id]);
        $member = User::factory()->create();
        $project->users()->attach($member->id, ['role' => 'employee']);

        $this->putJson("/api/v1/projects/{$project->id}", $this->fullPayload($project, [
            'owner_id' => $member->id,
        ]))->assertOk()->assertJsonPath('data.owner_id', $member->id);
    }

    public function test_updating_keeps_the_ticket_prefix_unique(): void
    {
        $user = $this->actingWith(['Update project']);
        $project = Project::factory()->create(['owner_id' => $user->id, 'ticket_prefix' => 'AAA']);
        Project::factory()->create(['ticket_prefix' => 'BBB']);

        $this->patchJson("/api/v1/projects/{$project->id}", ['ticket_prefix' => 'BBB'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['ticket_prefix']);

        // Its own prefix is not a conflict with itself.
        $this->patchJson("/api/v1/projects/{$project->id}", ['ticket_prefix' => 'AAA'])
            ->assertOk();
    }

    // ------------------------------------------------------------ destroy

    public function test_it_deletes_a_project(): void
    {
        $user = $this->actingWith(['Delete project']);
        $project = Project::factory()->create(['owner_id' => $user->id]);

        $this->deleteJson("/api/v1/projects/{$project->id}")->assertNoContent();

        $this->assertSoftDeleted('projects', ['id' => $project->id]);
    }

    public function test_deleting_a_project_takes_its_contents_with_it(): void
    {
        $user = $this->actingWith(['Delete project']);
        $project = Project::factory()->create(['owner_id' => $user->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);

        $this->deleteJson("/api/v1/projects/{$project->id}")->assertNoContent();

        $this->assertSoftDeleted('tickets', ['id' => $ticket->id]);
        $this->assertSoftDeleted('sprints', ['id' => $sprint->id]);
    }

    public function test_deleting_requires_the_delete_permission(): void
    {
        $user = $this->actingWith(['Update project']); // wrong permission
        $project = Project::factory()->create(['owner_id' => $user->id]);

        $this->deleteJson("/api/v1/projects/{$project->id}")->assertForbidden();

        $this->assertDatabaseHas('projects', ['id' => $project->id, 'deleted_at' => null]);
    }

    public function test_a_plain_member_cannot_delete_the_project(): void
    {
        $user = $this->actingWith(['Delete project']);
        $project = Project::factory()->create();
        $project->users()->attach($user->id, ['role' => 'employee']);

        $this->deleteJson("/api/v1/projects/{$project->id}")->assertForbidden();
    }

    public function test_it_returns_404_when_deleting_a_missing_project(): void
    {
        $this->actingWith(['Delete project']);

        $this->deleteJson('/api/v1/projects/999999')->assertNotFound();
    }
}
