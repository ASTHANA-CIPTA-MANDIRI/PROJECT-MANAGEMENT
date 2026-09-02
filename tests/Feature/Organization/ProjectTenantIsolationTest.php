<?php

namespace Tests\Feature\Organization;

use App\Models\Organization;
use App\Models\Project;
use App\Models\Role;
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
}
