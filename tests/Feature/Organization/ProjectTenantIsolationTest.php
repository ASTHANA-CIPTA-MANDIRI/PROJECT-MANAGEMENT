<?php

namespace Tests\Feature\Organization;

use App\Models\Organization;
use App\Models\Permission;
use App\Models\Project;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\User;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 3A — Project Tenant Isolation. Proves the DoD scenario matrix
 * directly against Project::scopeAccessibleBy()/isAccessibleBy()/
 * isManageableBy() (app/Models/Project.php), the single centralized gate
 * every Filament/API/Livewire/broadcast access point already funnels
 * through (ADR 0001; round-10 audit). Ticket/Sprint/Epic/TicketComment are
 * not re-tested here — they all delegate to these same Project methods, so
 * this suite closing the gate here closes it for them too.
 */
class ProjectTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function memberOf(Organization $organization, User $user): void
    {
        $organization->users()->attach($user);
    }

    private function projectIn(Organization $organization, array $attributes = []): Project
    {
        return Project::factory()->create(array_merge(
            ['organization_id' => $organization->id],
            $attributes
        ));
    }

    // ---------------------------------------------------- A / B — basic isolation

    public function test_org_a_member_can_access_project_a(): void
    {
        $orgA = Organization::factory()->create();
        $user = User::factory()->create();
        $this->memberOf($orgA, $user);
        $projectA = $this->projectIn($orgA);
        $projectA->users()->attach($user->id, ['role' => 'employee']);

        $this->assertTrue($projectA->isAccessibleBy($user));
        $this->assertTrue(Project::query()->accessibleBy($user)->whereKey($projectA->id)->exists());
    }

    public function test_org_a_member_cannot_access_project_b(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $user = User::factory()->create();
        $this->memberOf($orgA, $user);

        // Owner of Project B too, not just a plain member — ownership must
        // not bypass the organization boundary either.
        $projectB = $this->projectIn($orgB, ['owner_id' => $user->id]);

        $this->assertFalse($projectB->isAccessibleBy($user));
        $this->assertFalse(Project::query()->accessibleBy($user)->whereKey($projectB->id)->exists());
    }

    public function test_org_b_member_cannot_access_project_a(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $user = User::factory()->create();
        $this->memberOf($orgB, $user);

        $projectA = $this->projectIn($orgA);
        $projectA->users()->attach($user->id, ['role' => 'employee']);

        $this->assertFalse($projectA->isAccessibleBy($user));
    }

    // ---------------------------------------------------- C — multi-org context

    public function test_multi_org_member_in_context_a_is_denied_project_b(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $user = User::factory()->create();
        $this->memberOf($orgA, $user);
        $this->memberOf($orgB, $user);

        $projectA = $this->projectIn($orgA);
        $projectA->users()->attach($user->id, ['role' => 'employee']);
        $projectB = $this->projectIn($orgB);
        $projectB->users()->attach($user->id, ['role' => 'employee']);

        OrganizationContext::switch($user, $orgA->id);

        $this->assertTrue($projectA->isAccessibleBy($user));
        $this->assertFalse($projectB->isAccessibleBy($user));
    }

    public function test_multi_org_member_switching_context_reaches_project_b(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $user = User::factory()->create();
        $this->memberOf($orgA, $user);
        $this->memberOf($orgB, $user);

        $projectB = $this->projectIn($orgB);
        $projectB->users()->attach($user->id, ['role' => 'employee']);

        OrganizationContext::switch($user, $orgA->id);
        $this->assertFalse($projectB->isAccessibleBy($user));

        OrganizationContext::switch($user, $orgB->id);
        $this->assertTrue($projectB->isAccessibleBy($user));
    }

    // ---------------------------------------------------- D — project membership alone is not enough

    public function test_project_member_without_organization_membership_is_denied(): void
    {
        $orgB = Organization::factory()->create();
        $orgOther = Organization::factory()->create(); // the user's only org — not B
        $user = User::factory()->create();
        $this->memberOf($orgOther, $user);

        $projectB = $this->projectIn($orgB);
        $projectB->users()->attach($user->id, ['role' => 'employee']);

        $this->assertFalse($projectB->isAccessibleBy($user));

        // Same guard applies to management ability.
        $projectB->users()->syncWithoutDetaching([
            $user->id => ['role' => config('system.projects.affectations.roles.can_manage')],
        ]);
        $this->assertFalse($projectB->fresh()->isManageableBy($user));
    }

    public function test_cross_org_project_membership_grant_is_inert(): void
    {
        // A project_users row for a user outside the project's organization
        // exists in the pivot table but never satisfies the organization
        // half of the check — proving no separate fix to member-management
        // UI is needed for this to be safe.
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $userInA = User::factory()->create();
        $this->memberOf($orgA, $userInA);

        $projectB = $this->projectIn($orgB);
        $projectB->users()->attach($userInA->id, ['role' => 'employee']);

        $this->assertDatabaseHas('project_users', ['project_id' => $projectB->id, 'user_id' => $userInA->id]);
        $this->assertFalse($projectB->isAccessibleBy($userInA));
    }

    // ---------------------------------------------------- IDOR — direct id access

    public function test_direct_lookup_of_another_tenants_project_id_is_excluded(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $user = User::factory()->create();
        $this->memberOf($orgA, $user);

        $projectB = $this->projectIn($orgB, ['owner_id' => $user->id]);

        $found = Project::query()->accessibleBy($user)->find($projectB->id);

        $this->assertNull($found);
    }

    // ---------------------------------------------------- null organization_id

    public function test_null_organization_project_stays_accessible_to_its_existing_member(): void
    {
        $orgOther = Organization::factory()->create();
        $user = User::factory()->create();
        $this->memberOf($orgOther, $user);

        $legacyProject = Project::factory()->create(['organization_id' => null]);
        $legacyProject->users()->attach($user->id, ['role' => 'employee']);

        // Regardless of which organization is the user's current context,
        // a project with no tenant assigned is governed only by the
        // pre-existing owner/project_users rule.
        $this->assertTrue($legacyProject->isAccessibleBy($user));
    }

    public function test_null_organization_project_is_not_exposed_to_an_unrelated_user(): void
    {
        $legacyProject = Project::factory()->create(['organization_id' => null]);
        $unrelatedUser = User::factory()->create();

        $this->assertFalse($legacyProject->isAccessibleBy($unrelatedUser));
    }

    // ---------------------------------------------------- Platform Super Admin

    public function test_super_admin_without_project_membership_is_still_denied(): void
    {
        // No Gate::before bypass exists for Super Admin in this app
        // (confirmed pre-existing behavior — see SuperAdminSettingsPermissionMigrationTest).
        // Adding the organization check must not create one either.
        $superAdminRole = Role::create(['name' => 'Super Admin']);
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole($superAdminRole);

        $organization = Organization::factory()->create();
        $project = $this->projectIn($organization);

        $this->assertFalse($project->isAccessibleBy($superAdmin));
    }

    // ---------------------------------------------------- Phase 5.3B — Organization authority

    /**
     * The core Phase 5.3B scenario: an Organization Owner/Admin manages a
     * Project they own no project_users row on at all — the new
     * isManageableThroughOrganizationBy() branch, never a fabricated
     * project_users attach.
     */
    public function test_organization_owner_manages_a_project_with_no_project_users_row(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $this->memberOf($organization, $owner);
        $organization->users()->syncWithoutDetaching([$owner->id => ['role' => 'owner']]);

        $project = $this->projectIn($organization);

        $this->assertTrue($project->isAccessibleBy($owner));
        $this->assertTrue($project->isManageableBy($owner));
        $this->assertDatabaseMissing('project_users', ['project_id' => $project->id, 'user_id' => $owner->id]);
    }

    public function test_organization_admin_manages_a_project_with_no_project_users_row(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->create();
        $organization->users()->attach($admin->id, ['role' => 'admin']);

        $project = $this->projectIn($organization);

        $this->assertTrue($project->isAccessibleBy($admin));
        $this->assertTrue($project->isManageableBy($admin));
    }

    /**
     * Cross-organization denial (Security Attack Matrix scenarios F/G): an
     * Owner/Admin of Organization A gets no authority at all over a Project
     * belonging to Organization B, even though Organization::isManageableBy()
     * would return true for them on their OWN organization — the check must
     * be scoped to *this project's* organization, not "any organization the
     * user happens to manage."
     */
    public function test_admin_of_organization_a_cannot_manage_a_project_in_organization_b(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $admin = User::factory()->create();
        $orgA->users()->attach($admin->id, ['role' => 'admin']);

        $projectB = $this->projectIn($orgB);

        $this->assertFalse($projectB->isAccessibleBy($admin));
        $this->assertFalse($projectB->isManageableBy($admin));
    }

    public function test_owner_of_organization_a_cannot_manage_a_project_in_organization_b(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $owner = User::factory()->create();
        $orgA->users()->attach($owner->id, ['role' => 'owner']);

        $projectB = $this->projectIn($orgB);

        $this->assertFalse($projectB->isAccessibleBy($owner));
        $this->assertFalse($projectB->isManageableBy($owner));
    }

    /**
     * Multi-org Owner/Admin: authority only follows the CURRENTLY ACTIVE
     * context, the same discipline every other access path in this class
     * already follows (test_multi_org_member_in_context_a_is_denied_project_b
     * above) — being Admin of B does not help while A is active, and
     * switching context flips the answer immediately.
     */
    public function test_organization_authority_follows_the_active_context_not_every_membership(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $admin = User::factory()->create();
        $orgA->users()->attach($admin->id, ['role' => 'member']);
        $orgB->users()->attach($admin->id, ['role' => 'admin']);

        $projectB = $this->projectIn($orgB);

        OrganizationContext::switch($admin, $orgA->id);
        $this->assertFalse($projectB->isAccessibleBy($admin));

        OrganizationContext::switch($admin, $orgB->id);
        $this->assertTrue($projectB->isAccessibleBy($admin));
    }

    /**
     * Legacy null-organization projects (Part 18 of the Phase 5.3B audit)
     * must not gain new visibility through the Organization-authority
     * branch either — there is no Organization on the project to derive
     * authority from, regardless of what the user manages elsewhere.
     */
    public function test_organization_owner_gets_no_authority_over_a_legacy_null_organization_project(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $organization->users()->attach($owner->id, ['role' => 'owner']);

        $legacyProject = Project::factory()->create(['organization_id' => null]);

        $this->assertFalse($legacyProject->isAccessibleBy($owner));
        $this->assertFalse($legacyProject->isManageableBy($owner));
    }

    /**
     * The query-level twin of isAccessibleBy()'s new branch: an Owner/Admin
     * must see every project in their Organization through
     * Project::accessibleBy() too (Filament listing / API index), not only
     * when a specific record is already loaded and checked directly —
     * otherwise the two would silently disagree about what "accessible"
     * means.
     */
    public function test_scope_accessible_by_includes_projects_managed_only_through_organization_authority(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->create();
        $organization->users()->attach($admin->id, ['role' => 'admin']);

        $project = $this->projectIn($organization);

        $this->assertTrue(Project::query()->accessibleBy($admin)->whereKey($project->id)->exists());
    }

    public function test_scope_accessible_by_still_excludes_a_foreign_organizations_project_for_an_admin(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $admin = User::factory()->create();
        $orgA->users()->attach($admin->id, ['role' => 'admin']);

        $projectB = $this->projectIn($orgB);

        $this->assertFalse(Project::query()->accessibleBy($admin)->whereKey($projectB->id)->exists());
    }

    /**
     * Child resources (Ticket, Sprint, Epic, TicketComment) all delegate
     * directly to Project::isAccessibleBy()/isManageableBy() — this is the
     * explicit, deliberate proof that Phase 5.3B's Organization-authority
     * branch is inherited by them automatically, without touching a single
     * child Policy file (Ticket chosen as the representative case; Sprint/
     * Epic/TicketComment share the exact same delegation, see
     * app/Policies/{Sprint,Epic,TicketComment}Policy.php).
     */
    public function test_organization_admin_inherits_ticket_authority_through_the_project_gate(): void
    {
        $permission = Permission::firstOrCreate(['name' => 'View ticket']);
        $role = Role::create(['name' => 'r_'.uniqid()]);
        $role->syncPermissions([$permission]);
        $admin = User::factory()->create();
        $admin->syncRoles([$role]);

        $organization = Organization::factory()->create();
        $organization->users()->attach($admin->id, ['role' => 'admin']);

        $project = $this->projectIn($organization);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);

        $this->assertTrue($admin->fresh()->can('view', $ticket));
        $this->assertDatabaseMissing('project_users', ['project_id' => $project->id, 'user_id' => $admin->id]);
    }
}
