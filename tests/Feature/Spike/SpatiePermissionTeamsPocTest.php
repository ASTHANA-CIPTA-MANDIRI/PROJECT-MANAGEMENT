<?php

namespace Tests\Feature\Spike;

use App\Models\Permission;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * PHASE 1 SPIKE - branch spike/spatie-permission-teams ONLY.
 *
 * Proves (or disproves) whether spatie/laravel-permission 5.11.1's Teams
 * feature can sit under this app's existing RBAC without breaking it, using
 * the app's REAL models (User, Role, Permission, Project, Ticket) and REAL
 * policies (ProjectPolicy, TicketPolicy) - not a toy reimplementation.
 *
 * "Organization" here is a plain integer id (1, 2, ...), not a real
 * Organization model - Spatie Teams only cares about the id value on the
 * `team_id` column, and this spike is explicitly forbidden from building a
 * production Organization implementation. The team_id columns themselves
 * come from the sibling spike-only migration
 * 2026_09_01_990000_spike_add_team_id_for_permission_teams_poc.php.
 *
 * MUST NOT be merged into jarne.
 */
class SpatiePermissionTeamsPocTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableTeams();
    }

    /**
     * config('permission.teams') alone does nothing after boot -
     * PermissionRegistrar reads it once into static properties in its
     * constructor (initializeCache()). Flipping the config at runtime only
     * takes effect once those statics are re-initialized, same as this
     * package's own test suite does it.
     */
    private function enableTeams(): void
    {
        config([
            'permission.teams' => true,
            'permission.column_names.team_foreign_key' => 'team_id',
        ]);

        app(PermissionRegistrar::class)->initializeCache();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function setTeam(?int $teamId): void
    {
        setPermissionsTeamId($teamId);
    }

    // =====================================================================
    // Scenario A - same permission name, two orgs, no leakage
    // =====================================================================

    public function test_scenario_a_user_a_does_not_get_permission_from_org_b(): void
    {
        Permission::create(['name' => 'spike.manage-billing']);

        $this->setTeam(1);
        $adminOrgA = Role::create(['name' => 'Admin']);
        $adminOrgA->givePermissionTo('spike.manage-billing');

        $this->setTeam(2);
        // Org B's "Admin" is a genuinely different row (unique constraint is
        // now [team_id, name, guard_name], not [name, guard_name]) and is
        // deliberately given NO permissions, to prove isolation rather than
        // assuming it.
        Role::create(['name' => 'Admin']);

        $userA = User::factory()->create();
        $this->setTeam(1);
        $userA->assignRole($adminOrgA);

        // Acting "inside" Org A: the permission granted there is visible.
        $this->setTeam(1);
        $this->assertTrue($userA->fresh()->can('spike.manage-billing'));

        // Acting "inside" Org B: User A has no role there at all, so the
        // Org-A-only permission must not leak across.
        $this->setTeam(2);
        $this->assertFalse($userA->fresh()->can('spike.manage-billing'));
    }

    // =====================================================================
    // Scenario B - org-level role does not imply project-level access
    // =====================================================================

    public function test_scenario_b_org_admin_role_does_not_bypass_project_membership(): void
    {
        Permission::firstOrCreate(['name' => 'View project']);

        $this->setTeam(1);
        $orgAdmin = Role::create(['name' => 'Org Admin']);
        $orgAdmin->givePermissionTo('View project');

        $userA = User::factory()->create();
        $this->setTeam(1);
        $userA->assignRole($orgAdmin);

        // Project B: User A is not the owner and not a project_users member.
        // Existing project access is untouched by Spatie Teams - it is a
        // separate mechanism (Project::scopeAccessibleBy/isAccessibleBy).
        $status = ProjectStatus::factory()->create();
        $projectB = Project::factory()->create(['status_id' => $status->id]);

        $this->setTeam(1);
        $userA = $userA->fresh();

        $this->assertTrue($userA->can('View project'), 'the Org-level permission itself must still be granted');
        $this->assertFalse($projectB->isAccessibleBy($userA), 'but project membership is untouched by team context');
        $this->assertTrue(
            \Illuminate\Support\Facades\Gate::forUser($userA)->denies('view', $projectB),
            'ProjectPolicy::view requires isAccessibleBy() in addition to the permission, so it must still deny'
        );
    }

    // =====================================================================
    // Scenario C - same user, different role per org, no cross-leak
    // =====================================================================

    public function test_scenario_c_same_user_different_role_per_org_does_not_leak(): void
    {
        Permission::create(['name' => 'spike.delete-anything']);

        $this->setTeam(1);
        $admin = Role::create(['name' => 'Admin']);
        $admin->givePermissionTo('spike.delete-anything');

        $this->setTeam(2);
        $member = Role::create(['name' => 'Member']); // no permissions

        $user = User::factory()->create();

        $this->setTeam(1);
        $user->assignRole($admin);

        $this->setTeam(2);
        $user->assignRole($member);

        $this->setTeam(1);
        $this->assertTrue($user->fresh()->can('spike.delete-anything'), 'Admin in Org A');

        $this->setTeam(2);
        $this->assertFalse($user->fresh()->can('spike.delete-anything'), 'only Member in Org B');
    }

    // =====================================================================
    // Scenario D - platform Super Admin vs. team context
    // =====================================================================

    /**
     * This app's Super Admin concept (User::isSuperAdmin(), configured via
     * GeneralSettings::super_admin_role) predates any team concept. A
     * real-world Teams rollout would retrofit team_id as a NULLABLE column
     * onto an already-populated model_has_roles table - existing pivot rows
     * (assigned back when getPermissionsTeamId() was never called, i.e.
     * null) would keep team_id = NULL, not get backfilled to some team.
     * This reproduces exactly that: assign Super Admin with NO team context
     * set at all (simulating "today", before Teams existed), then check
     * whether it is still recognized once Teams is live and some team
     * context is active.
     */
    public function test_scenario_d_super_admin_survives_when_assigned_with_no_team_context(): void
    {
        $superAdminRole = Role::create(['name' => 'Super Admin']); // team_id left null (no setTeam() call)

        $superAdmin = User::factory()->create();
        $superAdmin->assignRole($superAdminRole); // getPermissionsTeamId() is null here too

        $this->assertNull(
            \DB::table('model_has_roles')->where('model_id', $superAdmin->id)->value('team_id'),
            'sanity check: the pivot row really was stamped team_id = NULL, matching a pre-Teams assignment'
        );

        // A request comes in scoped to some organization (team 1) - this is
        // the realistic case: SOME middleware sets a team context on every
        // request once Teams is live, there is no such thing as "no team"
        // once the feature is on for the whole app.
        $this->setTeam(1);
        $superAdmin = $superAdmin->fresh();

        $this->assertTrue(
            $superAdmin->hasRole('Super Admin'),
            'FINDING: does a NULL-team pivot row remain visible once a real team context is active?'
        );
        $this->assertTrue($superAdmin->isSuperAdmin());

        // And with NO team context at all (e.g. a platform-level page that
        // never calls setPermissionsTeamId()).
        $this->setTeam(null);
        $superAdmin = $superAdmin->fresh();
        $this->assertTrue($superAdmin->isSuperAdmin(), 'must also hold with no team context set');
    }

    // =====================================================================
    // Scenario E - can() reflects the CURRENT team context, not a stale one
    // =====================================================================

    public function test_scenario_e_can_reflects_team_context_changes_within_the_same_process(): void
    {
        Permission::create(['name' => 'spike.org-a-only']);
        Permission::create(['name' => 'spike.org-b-only']);

        $this->setTeam(1);
        $roleA = Role::create(['name' => 'Role A']);
        $roleA->givePermissionTo('spike.org-a-only');

        $this->setTeam(2);
        $roleB = Role::create(['name' => 'Role B']);
        $roleB->givePermissionTo('spike.org-b-only');

        $user = User::factory()->create();
        $this->setTeam(1);
        $user->assignRole($roleA);
        $this->setTeam(2);
        $user->assignRole($roleB);

        // Same in-memory $user instance, no ->fresh() - proves the check
        // itself is team-aware per call, not just per freshly-hydrated model.
        $this->setTeam(1);
        $this->assertTrue($user->can('spike.org-a-only'));
        $this->assertFalse($user->can('spike.org-b-only'));

        $this->setTeam(2);
        $this->assertFalse($user->can('spike.org-a-only'), 'must flip after switching team context, same instance');
        $this->assertTrue($user->can('spike.org-b-only'));
    }

    // =====================================================================
    // Scenario F - a real app Policy (TicketPolicy) under team context
    // =====================================================================

    public function test_scenario_f_ticket_policy_still_works_correctly_under_team_context(): void
    {
        Permission::firstOrCreate(['name' => 'Update ticket']);

        $this->setTeam(1);
        $role = Role::create(['name' => 'Ticket Updater']);
        $role->givePermissionTo('Update ticket');

        $owner = User::factory()->create();
        $this->setTeam(1);
        $owner->assignRole($role);

        $ticket = Ticket::factory()->create(['owner_id' => $owner->id]);

        $stranger = User::factory()->create();
        $this->setTeam(1);
        $stranger->assignRole($role); // has the Org permission too, but is not involved in the ticket

        $this->setTeam(1);
        $owner = $owner->fresh();
        $stranger = $stranger->fresh();

        $this->assertTrue(
            \Illuminate\Support\Facades\Gate::forUser($owner)->allows('update', $ticket),
            'TicketPolicy::update = permission AND isInvolved(); owner has both'
        );
        $this->assertTrue(
            \Illuminate\Support\Facades\Gate::forUser($stranger)->denies('update', $ticket),
            'stranger has the Org-level permission but is not owner/responsible/on the project - isInvolved() must still gate this'
        );
    }
}
