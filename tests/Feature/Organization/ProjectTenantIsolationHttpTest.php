<?php

namespace Tests\Feature\Organization;

use App\Models\Organization;
use App\Models\Permission;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Phase 3A end-to-end proof that the organization boundary added to
 * Project::isAccessibleBy() actually reaches the real access points, not
 * just the model in isolation — the API controller (ProjectPolicy) and the
 * Filament panel (ProjectResource::getEloquentQuery()).
 */
class ProjectTenantIsolationHttpTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_api_forbids_viewing_a_project_from_another_organization(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();

        $user = $this->actingWith(['View project']);
        $orgA->users()->attach($user);

        $projectB = Project::factory()->create(['organization_id' => $orgB->id, 'owner_id' => $user->id]);

        $this->getJson("/api/v1/projects/{$projectB->id}")->assertForbidden();
    }

    public function test_filament_edit_page_404s_for_a_project_from_another_organization(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();

        $permission = Permission::firstOrCreate(['name' => 'Update project']);
        $role = Role::create(['name' => 'r_'.uniqid()]);
        $role->syncPermissions([$permission]);
        $user = User::factory()->create();
        $user->syncRoles([$role]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user = $user->fresh();
        $orgA->users()->attach($user);

        $projectB = Project::factory()->create(['organization_id' => $orgB->id, 'owner_id' => $user->id]);

        $this->actingAs($user)
            ->get("/projects/{$projectB->id}/edit")
            ->assertNotFound();
    }
}
