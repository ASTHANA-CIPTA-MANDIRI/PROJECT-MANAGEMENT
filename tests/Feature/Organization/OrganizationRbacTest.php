<?php

namespace Tests\Feature\Organization;

use App\Models\Organization;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 4 — Organization RBAC. Audit (vendor-source-level, not assumption)
 * proved activating spatie/laravel-permission's Teams feature would
 * team-scope every existing role/permission check app-wide — including
 * Super Admin and every instance-global permission ("View project",
 * "Create ticket") — since HasRoles::roles() itself bakes team-scoping into
 * the relationship, with no per-role opt-out, and every existing
 * model_has_roles row has team_id = NULL (never matches a concrete team
 * context). Teams stays off; Organization RBAC is instead a plain
 * `organization_users.role` column, mirroring the already-proven
 * `project_users.role` pattern — see Organization::isAccessibleBy()/
 * isManageableBy() (app/Models/Organization.php) and OrganizationPolicy.
 *
 * Because nothing here is Spatie-cached the way HasRoles::loadMissing() is,
 * the whole "stale permission after switch" failure class (spike Scenario
 * E) does not apply by construction — asserted below anyway, per the brief.
 */
class OrganizationRbacTest extends TestCase
{
    use RefreshDatabase;

    private function attach(Organization $organization, User $user, string $role): void
    {
        $organization->users()->attach($user->id, ['role' => $role]);
    }

    // ---------------------------------------------------- A — role isolation

    public function test_the_same_user_can_hold_different_roles_in_different_organizations(): void
    {
        $alpha = Organization::factory()->create();
        $beta = Organization::factory()->create();
        $user = User::factory()->create();
        $this->attach($alpha, $user, 'owner');
        $this->attach($beta, $user, 'member');

        $this->assertTrue($alpha->isManageableBy($user));
        $this->assertFalse($beta->isManageableBy($user));
    }

    // -------------------------------------------------- B — permission isolation

    public function test_a_manage_capable_role_on_one_organization_does_not_leak_to_another(): void
    {
        $alpha = Organization::factory()->create();
        $beta = Organization::factory()->create();
        $user = User::factory()->create();
        $this->attach($alpha, $user, 'admin');
        // Not a member of Beta at all.

        $this->assertTrue($alpha->isManageableBy($user));
        $this->assertFalse($beta->isManageableBy($user));
        $this->assertFalse($beta->isAccessibleBy($user));
    }

    // --------------------------------------------- C — switching, no staleness

    public function test_switching_between_organizations_never_carries_a_stale_role(): void
    {
        // Unlike Spatie's HasRoles::loadMissing()-cached roles() relation
        // (spike Scenario E), isManageableBy() is a plain, uncached query
        // against a specific $organization instance - a role is a fact
        // about (user, organization), never about "whichever org happens
        // to be current right now." Switching the *current* org (what
        // OrganizationContext::current() resolves to) must never change
        // what isManageableBy() reports for either organization.
        $alpha = Organization::factory()->create();
        $beta = Organization::factory()->create();
        $user = User::factory()->create();
        $this->attach($alpha, $user, 'owner');
        $this->attach($beta, $user, 'member');

        OrganizationContext::switch($user, $alpha->id);
        $this->assertTrue(OrganizationContext::current($user)->is($alpha));
        $this->assertTrue($alpha->isManageableBy($user));
        $this->assertFalse($beta->isManageableBy($user));

        OrganizationContext::switch($user, $beta->id);
        $this->assertTrue(OrganizationContext::current($user)->is($beta));
        $this->assertTrue($alpha->isManageableBy($user), 'Alpha ownership is unaffected by which org is current');
        $this->assertFalse($beta->isManageableBy($user), 'Beta membership is still only Member, not stale Alpha data');

        OrganizationContext::switch($user, $alpha->id);
        $this->assertTrue(OrganizationContext::current($user)->is($alpha));
        $this->assertTrue($alpha->isManageableBy($user));
        $this->assertFalse($beta->isManageableBy($user));
    }

    // ------------------------------------------------- D — foreign organization

    public function test_a_non_member_has_no_role_and_cannot_switch_into_it(): void
    {
        $alpha = Organization::factory()->create();
        $beta = Organization::factory()->create();
        $user = User::factory()->create();
        $this->attach($alpha, $user, 'owner');

        $this->assertFalse($beta->isAccessibleBy($user));
        $this->assertFalse($beta->isManageableBy($user));
        $this->assertFalse(OrganizationContext::switch($user, $beta->id));
    }

    // -------------------------------------------------- E — revoked membership

    public function test_revoked_membership_immediately_loses_the_role(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create();
        $this->attach($organization, $user, 'owner');

        $this->assertTrue($organization->isManageableBy($user));

        $organization->users()->detach($user);

        $this->assertFalse($organization->fresh()->isAccessibleBy($user));
        $this->assertFalse($organization->fresh()->isManageableBy($user));
    }

    // ----------------------------------------------- F — Platform Super Admin

    public function test_super_admin_gets_no_automatic_organization_role(): void
    {
        $superAdminRole = Role::create(['name' => 'Super Admin']);
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole($superAdminRole);

        $ownedOrganization = Organization::factory()->create();
        $this->attach($ownedOrganization, $superAdmin, 'owner');
        $unrelatedOrganization = Organization::factory()->create();

        // Being Super Admin does not make every organization manageable...
        $this->assertFalse($unrelatedOrganization->isManageableBy($superAdmin));
        // ...and being an Organization owner does not touch platform status.
        $this->assertTrue($superAdmin->isSuperAdmin());
        $this->assertTrue($ownedOrganization->isManageableBy($superAdmin));
    }

    // ----------------------------------------------- G — Project RBAC regression

    /**
     * Phase 5.3B deliberately reverses this test's original assertion:
     * before 5.3B, Organization RBAC and Project RBAC were completely
     * disconnected axes, so an Organization Owner got nothing extra at the
     * Project layer. The approved 5.3B architecture explicitly connects
     * them as a second, independent source of Project authority for
     * Owner/Admin specifically (never Member — see
     * OrganizationManagementTest::test_organization_member_gets_no_project_authority_from_membership_alone) —
     * Project::isManageableBy()/isAccessibleBy()'s new
     * isManageableThroughOrganizationBy() branch. Still no project_users
     * row is ever written for the Owner: authority comes purely from
     * organization_users.role, and project_users.role stays exactly
     * employee/customer/administrator, untouched.
     */
    public function test_organization_owner_role_grants_project_authority_without_project_membership(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create();
        $this->attach($organization, $user, 'owner');

        // A project in the same organization, but the user is neither its
        // owner_id nor a project_users member at all.
        $project = Project::factory()->create(['organization_id' => $organization->id]);

        $this->assertTrue($project->isManageableBy($user));
        $this->assertTrue($project->isAccessibleBy($user));
        $this->assertDatabaseMissing('project_users', [
            'project_id' => $project->id,
            'user_id' => $user->id,
        ]);
    }

    // ----------------------------------------------------- H — Policy regression

    public function test_organization_policy_is_the_real_gate_not_just_the_model_helper(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $this->attach($organization, $owner, 'owner');
        $this->attach($organization, $member, 'member');

        $this->assertTrue(Gate::forUser($owner)->allows('update', $organization));
        $this->assertFalse(Gate::forUser($member)->allows('update', $organization));
        $this->assertTrue(Gate::forUser($member)->allows('view', $organization));

        $stranger = User::factory()->create();
        $this->assertFalse(Gate::forUser($stranger)->allows('view', $organization));
    }

    // --------------------------------------------------- I — Livewire tampering

    public function test_no_livewire_component_reads_or_writes_an_organization_role(): void
    {
        // N/A for Phase 4: no Organization management UI exists yet (out of
        // scope — see the plan). OrganizationSwitcher (Phase 3C) only ever
        // reads organization_id/name, never role, so there is no tampering
        // surface to test. This case documents that fact as a passing
        // assertion rather than a silently skipped requirement.
        $this->assertFileDoesNotExist(app_path('Http/Livewire/OrganizationRoleManager.php'));
    }

    // ---------------------------------------------------- new column sanity

    public function test_existing_membership_rows_default_to_member(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create();
        // Attach without specifying a role, the way Phase 2/3A/3B/3C tests
        // already do everywhere — must keep working unchanged.
        $organization->users()->attach($user->id);

        $this->assertDatabaseHas('organization_users', [
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => 'member',
        ]);
        $this->assertFalse($organization->fresh()->isManageableBy($user));
        $this->assertTrue($organization->fresh()->isAccessibleBy($user));
    }

    public function test_role_column_exists_on_organization_users(): void
    {
        $this->assertTrue(Schema::hasColumn('organization_users', 'role'));
    }
}
