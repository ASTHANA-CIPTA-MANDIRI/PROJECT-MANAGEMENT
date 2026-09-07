<?php

namespace Tests\Feature\Policies;

use App\Models\Organization;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\InteractsWithPermissions;
use Tests\TestCase;

class ProjectPolicyTest extends TestCase
{
    use InteractsWithPermissions, RefreshDatabase;

    private function manageRole(): string
    {
        return config('system.projects.affectations.roles.can_manage');
    }

    // ------------------------------------------------------------- viewAny

    public function test_listing_requires_the_list_permission(): void
    {
        $user = $this->userWithPermissions(['List projects']);

        $this->assertTrue($user->can('viewAny', Project::class));
    }

    public function test_listing_is_denied_without_the_permission(): void
    {
        $user = $this->userWithoutPermissions();

        $this->assertFalse($user->can('viewAny', Project::class));
    }

    // ---------------------------------------------------------------- view

    public function test_owner_with_permission_can_view_the_project(): void
    {
        $user = $this->userWithPermissions(['View project']);
        $project = Project::factory()->create(['owner_id' => $user->id]);

        $this->assertTrue($user->can('view', $project));
    }

    public function test_project_member_with_permission_can_view_the_project(): void
    {
        $user = $this->userWithPermissions(['View project']);
        $project = Project::factory()->create();
        $project->users()->attach($user->id, ['role' => 'employee']);

        $this->assertTrue($user->can('view', $project));
    }

    public function test_an_unrelated_user_cannot_view_the_project(): void
    {
        $user = $this->userWithPermissions(['View project']);
        $project = Project::factory()->create();

        $this->assertFalse($user->can('view', $project));
    }

    public function test_the_owner_cannot_view_without_the_permission(): void
    {
        $user = $this->userWithoutPermissions();
        $project = Project::factory()->create(['owner_id' => $user->id]);

        $this->assertFalse($user->can('view', $project));
    }

    // -------------------------------------------------------------- create
    //
    // Phase 5.3B: `create` used to be a flat permission check only, with no
    // regard for Organization membership at all. It now requires BOTH the
    // permission AND that the user's *current* Organization
    // (App\Support\OrganizationContext — never a client-supplied id) exists
    // and is one they own or administer. Neither half replaces the other.

    private function organizationManageableBy(User $user, string $role = 'owner'): Organization
    {
        $organization = Organization::factory()->create();
        $organization->users()->attach($user->id, ['role' => $role]);

        return $organization;
    }

    public function test_creating_requires_the_create_permission_and_organization_authority(): void
    {
        $user = $this->userWithPermissions(['Create project']);
        $this->organizationManageableBy($user, 'owner');

        $this->assertTrue($user->can('create', Project::class));
    }

    public function test_creating_is_allowed_for_an_organization_admin_too(): void
    {
        $user = $this->userWithPermissions(['Create project']);
        $this->organizationManageableBy($user, 'admin');

        $this->assertTrue($user->can('create', Project::class));
    }

    /**
     * The permission alone is no longer sufficient — an Organization Owner
     * who lacks the flat permission is still denied. Renamed from its
     * original "without the permission" assertion is kept, but the user now
     * also holds real Organization Owner authority, proving the permission
     * check still runs independently rather than being subsumed by it.
     */
    public function test_creating_is_denied_without_the_permission(): void
    {
        $user = $this->userWithoutPermissions();
        $this->organizationManageableBy($user, 'owner');

        $this->assertFalse($user->can('create', Project::class));
    }

    public function test_creating_is_denied_with_the_permission_but_no_organization(): void
    {
        $user = $this->userWithPermissions(['Create project']);

        $this->assertFalse($user->can('create', Project::class));
    }

    public function test_creating_is_denied_for_a_plain_organization_member_even_with_the_permission(): void
    {
        $user = $this->userWithPermissions(['Create project']);
        $this->organizationManageableBy($user, 'member');

        $this->assertFalse($user->can('create', Project::class));
    }

    /**
     * Super Admin holds every permission (including "Create project") but
     * gets no Gate::before bypass anywhere in this app — without an active,
     * manageable Organization, Super Admin is denied exactly like anyone
     * else. See also Tests\Feature\Organization\ProjectTenantIsolationTest
     * for the equivalent check against isAccessibleBy().
     */
    public function test_creating_is_denied_for_super_admin_without_an_organization(): void
    {
        $superAdminRole = Role::create(['name' => 'Super Admin']);
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole($superAdminRole);

        $this->assertFalse($superAdmin->can('create', Project::class));
    }

    // -------------------------------------------------------------- update

    public function test_owner_with_permission_can_update_the_project(): void
    {
        $user = $this->userWithPermissions(['Update project']);
        $project = Project::factory()->create(['owner_id' => $user->id]);

        $this->assertTrue($user->can('update', $project));
    }

    public function test_a_member_with_the_manage_role_can_update_the_project(): void
    {
        $user = $this->userWithPermissions(['Update project']);
        $project = Project::factory()->create();
        $project->users()->attach($user->id, ['role' => $this->manageRole()]);

        $this->assertTrue($user->can('update', $project));
    }

    public function test_a_plain_member_cannot_update_the_project(): void
    {
        $user = $this->userWithPermissions(['Update project']);
        $project = Project::factory()->create();
        $project->users()->attach($user->id, ['role' => 'employee']);

        $this->assertFalse($user->can('update', $project));
    }

    public function test_an_unrelated_user_cannot_update_the_project(): void
    {
        $user = $this->userWithPermissions(['Update project']);
        $project = Project::factory()->create();

        $this->assertFalse($user->can('update', $project));
    }

    // -------------------------------------------------------------- delete

    public function test_owner_with_permission_can_delete_the_project(): void
    {
        $user = $this->userWithPermissions(['Delete project']);
        $project = Project::factory()->create(['owner_id' => $user->id]);

        $this->assertTrue($user->can('delete', $project));
    }

    public function test_a_member_with_the_manage_role_can_delete_the_project(): void
    {
        $user = $this->userWithPermissions(['Delete project']);
        $project = Project::factory()->create();
        $project->users()->attach($user->id, ['role' => $this->manageRole()]);

        $this->assertTrue($user->can('delete', $project));
    }

    public function test_a_plain_member_cannot_delete_the_project(): void
    {
        $user = $this->userWithPermissions(['Delete project']);
        $project = Project::factory()->create();
        $project->users()->attach($user->id, ['role' => 'employee']);

        $this->assertFalse($user->can('delete', $project));
    }

    public function test_an_unrelated_user_cannot_delete_the_project(): void
    {
        $user = $this->userWithPermissions(['Delete project']);
        $project = Project::factory()->create();

        $this->assertFalse($user->can('delete', $project));
    }

    public function test_deleting_is_denied_without_the_permission(): void
    {
        $user = $this->userWithoutPermissions();
        $project = Project::factory()->create(['owner_id' => $user->id]);

        $this->assertFalse($user->can('delete', $project));
    }

    // ----------------------------------------------------------- deleteAny

    public function test_bulk_deleting_requires_the_delete_permission(): void
    {
        $this->assertTrue($this->userWithPermissions(['Delete project'])->can('deleteAny', Project::class));
    }

    public function test_bulk_deleting_is_denied_without_the_permission(): void
    {
        $this->assertFalse($this->userWithoutPermissions()->can('deleteAny', Project::class));
    }
}
