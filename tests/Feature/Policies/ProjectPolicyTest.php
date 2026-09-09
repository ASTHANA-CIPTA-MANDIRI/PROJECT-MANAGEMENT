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

    /**
     * Same Phase 5.4.3 gap as update()/delete() below, for view(): an
     * Organization Owner/Admin with no `View project` permission and no
     * `project_users` row can still view a project belonging to the
     * Organization they manage.
     */
    public function test_an_organization_owner_can_view_a_project_with_no_permission_and_no_project_users_row(): void
    {
        $user = $this->userWithoutPermissions();
        $organization = $this->organizationManageableBy($user, 'owner');
        $project = Project::factory()->create(['organization_id' => $organization->id]);

        $this->assertTrue($user->can('view', $project));
    }

    public function test_a_plain_organization_member_cannot_view_a_project_through_organization_authority(): void
    {
        $user = $this->userWithoutPermissions();
        $organization = $this->organizationManageableBy($user, 'member');
        $project = Project::factory()->create(['organization_id' => $organization->id]);

        $this->assertFalse($user->can('view', $project));
    }

    public function test_an_owner_of_another_organization_cannot_view_the_project(): void
    {
        $owner = $this->userWithoutPermissions();
        $this->organizationManageableBy($owner, 'owner');
        $foreignOrganization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $foreignOrganization->id]);

        $this->assertFalse($owner->can('view', $project));
    }

    // -------------------------------------------------------------- create
    //
    // Phase 5.3B first added an Organization-authority requirement here, but
    // paired it with the flat `Create project` permission using AND. Phase
    // 5.4.3 found that combination meant no real Organization Owner/Admin
    // could ever pass it (nothing in this app's Organization onboarding
    // ever grants that Spatie permission — see ProjectPolicy::create()'s own
    // docblock) and dropped the permission from this ability entirely:
    // Organization authority via OrganizationContext::current() — never a
    // client-supplied id — is now the sole, sufficient determinant.

    private function organizationManageableBy(User $user, string $role = 'owner'): Organization
    {
        $organization = Organization::factory()->create();
        $organization->users()->attach($user->id, ['role' => $role]);

        return $organization;
    }

    /**
     * Holding the (now-optional) flat permission alongside real Owner
     * authority is still allowed, obviously — the permission simply no
     * longer matters either way once Organization authority is present.
     */
    public function test_creating_is_allowed_for_an_organization_owner_who_also_holds_the_permission(): void
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
     * Phase 5.4.3 deliberately reverses this test's original assertion: an
     * Organization Owner with no `Create project` permission at all — not
     * even any Spatie role — used to be denied under Phase 5.3B's AND
     * requirement. That was the exact real-world failure this app's own
     * Organization onboarding produces (see ProjectPolicy::create()'s
     * docblock), since nothing grants that permission automatically. Owner
     * authority is now unconditionally sufficient on its own.
     */
    public function test_creating_is_allowed_for_an_organization_owner_with_no_platform_permission_at_all(): void
    {
        $user = $this->userWithoutPermissions();
        $this->organizationManageableBy($user, 'owner');

        $this->assertTrue($user->can('create', Project::class));
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

    /**
     * Phase 5.4.3 gap: Phase 5.3B made Project::isManageableBy() return true
     * for an Organization Owner/Admin with no `project_users` row, but this
     * Policy ability still required the flat `Update project` permission
     * ANDed with it — so the ability itself stayed denied for exactly the
     * users this was supposed to fix. isManageableThroughOrganizationBy()
     * is now OR'd in as an independent, sufficient path.
     */
    public function test_an_organization_owner_can_update_a_project_with_no_permission_and_no_project_users_row(): void
    {
        $user = $this->userWithoutPermissions();
        $organization = $this->organizationManageableBy($user, 'owner');
        $project = Project::factory()->create(['organization_id' => $organization->id]);

        $this->assertTrue($user->can('update', $project));
    }

    /**
     * Fase 6b: Organization Admin authority over a project's *settings* is
     * narrowed to Owner only — was assertTrue before this phase (see
     * Project::isOwnerManageableThroughOrganizationBy()'s docblock for the
     * reasoning). Admin still gets full view/create authority, unaffected.
     */
    public function test_an_organization_admin_cannot_update_a_project_through_organization_authority_alone(): void
    {
        $user = $this->userWithoutPermissions();
        $organization = $this->organizationManageableBy($user, 'admin');
        $project = Project::factory()->create(['organization_id' => $organization->id]);

        $this->assertFalse($user->can('update', $project));
    }

    public function test_a_plain_organization_member_cannot_update_a_project_through_organization_authority(): void
    {
        $user = $this->userWithoutPermissions();
        $organization = $this->organizationManageableBy($user, 'member');
        $project = Project::factory()->create(['organization_id' => $organization->id]);

        $this->assertFalse($user->can('update', $project));
    }

    /**
     * Cross-organization denial at the Gate level (mirrors
     * Tests\Feature\Organization\ProjectTenantIsolationTest's model-level
     * equivalent) — an Owner of Organization A gets no update authority over
     * a Project belonging to Organization B, even with real Owner authority
     * over A.
     */
    public function test_an_owner_of_another_organization_cannot_update_the_project(): void
    {
        $owner = $this->userWithoutPermissions();
        $this->organizationManageableBy($owner, 'owner');
        $foreignOrganization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $foreignOrganization->id]);

        $this->assertFalse($owner->can('update', $project));
    }

    public function test_super_admin_without_an_organization_cannot_update_a_project_through_organization_authority(): void
    {
        $superAdminRole = Role::create(['name' => 'Super Admin']);
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole($superAdminRole);

        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);

        $this->assertFalse($superAdmin->can('update', $project));
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

    /**
     * Same Phase 5.4.3 gap as update() above, mirrored for delete().
     */
    public function test_an_organization_owner_can_delete_a_project_with_no_permission_and_no_project_users_row(): void
    {
        $user = $this->userWithoutPermissions();
        $organization = $this->organizationManageableBy($user, 'owner');
        $project = Project::factory()->create(['organization_id' => $organization->id]);

        $this->assertTrue($user->can('delete', $project));
    }

    /**
     * Fase 6b: same narrowing as update() above, applied to delete — was
     * assertTrue before this phase.
     */
    public function test_an_organization_admin_cannot_delete_a_project_through_organization_authority_alone(): void
    {
        $user = $this->userWithoutPermissions();
        $organization = $this->organizationManageableBy($user, 'admin');
        $project = Project::factory()->create(['organization_id' => $organization->id]);

        $this->assertFalse($user->can('delete', $project));
    }

    public function test_a_plain_organization_member_cannot_delete_a_project_through_organization_authority(): void
    {
        $user = $this->userWithoutPermissions();
        $organization = $this->organizationManageableBy($user, 'member');
        $project = Project::factory()->create(['organization_id' => $organization->id]);

        $this->assertFalse($user->can('delete', $project));
    }

    public function test_an_owner_of_another_organization_cannot_delete_the_project(): void
    {
        $owner = $this->userWithoutPermissions();
        $this->organizationManageableBy($owner, 'owner');
        $foreignOrganization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $foreignOrganization->id]);

        $this->assertFalse($owner->can('delete', $project));
    }

    public function test_super_admin_without_an_organization_cannot_delete_a_project_through_organization_authority(): void
    {
        $superAdminRole = Role::create(['name' => 'Super Admin']);
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole($superAdminRole);

        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);

        $this->assertFalse($superAdmin->can('delete', $project));
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
