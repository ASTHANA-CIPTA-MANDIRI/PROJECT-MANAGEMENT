<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\User;
use App\Support\OrganizationContext;
use Database\Seeders\EmployeeRoleSeeder;
use Database\Seeders\OrganizationDemoSeeder;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Step 2.5 — development/manual-test fixtures for Organization RBAC.
 * Mirrors the existing LookupSeederIdempotencyTest/SeederSecurityTest
 * conventions: idempotency (re-running converges to the documented matrix
 * rather than duplicating or preserving drift) and the production guard are
 * both empirically verified, not assumed.
 */
class OrganizationDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_refuses_to_run_in_production(): void
    {
        $originalEnv = app()->environment();
        app()['env'] = 'production';

        try {
            // --force: this test is about OrganizationDemoSeeder's own
            // internal guard, not Laravel's separate "are you sure you want
            // to run this in production" confirmation prompt on the
            // db:seed command itself (a second, independent safety net a
            // real production run would also have to get past).
            Artisan::call('db:seed', ['--class' => OrganizationDemoSeeder::class, '--force' => true]);
        } finally {
            app()['env'] = $originalEnv;
        }

        $this->assertDatabaseMissing('users', ['email' => 'owner@example.test']);
        $this->assertDatabaseMissing('organizations', ['name' => 'Organization Alpha']);
        $this->assertDatabaseMissing('projects', ['ticket_prefix' => 'AWS']);
        $this->assertSame(0, Ticket::count());
    }

    public function test_it_seeds_the_documented_membership_matrix(): void
    {
        $this->seed(OrganizationDemoSeeder::class);

        $owner = User::where('email', 'owner@example.test')->firstOrFail();
        $admin = User::where('email', 'admin@example.test')->firstOrFail();
        $member = User::where('email', 'member@example.test')->firstOrFail();
        $multi = User::where('email', 'multi@example.test')->firstOrFail();

        $alpha = Organization::where('name', 'Organization Alpha')->firstOrFail();
        $beta = Organization::where('name', 'Organization Beta')->firstOrFail();
        $gamma = Organization::where('name', 'Organization Gamma')->firstOrFail();
        $delta = Organization::where('name', 'Organization Delta')->firstOrFail();

        $this->assertSame('owner', $alpha->roleOf($owner));
        $this->assertSame('admin', $alpha->roleOf($admin));
        $this->assertSame('member', $alpha->roleOf($member));
        $this->assertSame('member', $alpha->roleOf($multi));

        $this->assertSame('owner', $beta->roleOf($multi));
        $this->assertSame('member', $beta->roleOf($admin));

        $this->assertSame('owner', $gamma->roleOf($multi));
        $this->assertSame('member', $gamma->roleOf($member));

        // The "single-member organization" case: only its owner is a member
        // (it still gets its own Project below - see test_it_seeds_five_demo_projects_scoped_to_their_organizations()).
        $this->assertSame('owner', $delta->roleOf($owner));
        $this->assertSame(1, $delta->users()->count());
    }

    /**
     * A real Organization switcher exists (App\Http\Livewire\
     * OrganizationSwitcher, Phase 3C) - a user belonging to more than one
     * Organization (owner@ owns Alpha and Delta; multi@ owns Beta and
     * Gamma) is never stuck: OrganizationContext::current() only picks the
     * first membership as the *default* before anyone has switched, not a
     * hard limit. This proves the raw membership is there for the
     * switcher's dropdown to list, independent of which one happens to be
     * current right now.
     */
    public function test_owner_and_multi_organization_users_are_real_members_of_every_organization_they_are_listed_for(): void
    {
        $this->seed(OrganizationDemoSeeder::class);

        $owner = User::where('email', 'owner@example.test')->firstOrFail();
        $multi = User::where('email', 'multi@example.test')->firstOrFail();

        $this->assertSame(2, $owner->organizations()->count());
        $this->assertSame(3, $multi->organizations()->count());
    }

    public function test_the_no_organization_user_has_zero_memberships(): void
    {
        $this->seed(OrganizationDemoSeeder::class);

        $noOrg = User::where('email', 'noorg@example.test')->firstOrFail();

        $this->assertSame(0, $noOrg->organizations()->count());
    }

    public function test_the_multi_organization_user_gets_different_roles_per_organization(): void
    {
        $this->seed(OrganizationDemoSeeder::class);

        $multi = User::where('email', 'multi@example.test')->firstOrFail();
        $alpha = Organization::where('name', 'Organization Alpha')->firstOrFail();
        $beta = Organization::where('name', 'Organization Beta')->firstOrFail();
        $gamma = Organization::where('name', 'Organization Gamma')->firstOrFail();

        $this->assertFalse($alpha->isManageableBy($multi));
        $this->assertTrue($beta->isManageableBy($multi));
        $this->assertTrue($gamma->isManageableBy($multi));
    }

    public function test_re_running_converges_back_to_the_documented_matrix_instead_of_duplicating(): void
    {
        $this->seed(OrganizationDemoSeeder::class);

        $alpha = Organization::where('name', 'Organization Alpha')->firstOrFail();
        $member = User::where('email', 'member@example.test')->firstOrFail();

        // Simulate a tester having changed the role by hand while poking at
        // the UI, plus a manual attempt at creating a duplicate organization.
        $alpha->users()->updateExistingPivot($member->id, ['role' => 'owner']);

        $this->seed(OrganizationDemoSeeder::class);

        $this->assertSame(1, Organization::where('name', 'Organization Alpha')->count());
        $this->assertSame(1, User::where('email', 'member@example.test')->count());
        $this->assertSame('member', $alpha->fresh()->roleOf($member->fresh()));
    }

    public function test_seeded_users_can_access_the_filament_panel_when_the_employee_role_exists(): void
    {
        $this->seed(PermissionsSeeder::class);
        $this->seed(EmployeeRoleSeeder::class);
        $this->seed(OrganizationDemoSeeder::class);

        $owner = User::where('email', 'owner@example.test')->firstOrFail();

        $this->assertTrue($owner->canAccessFilament());
    }

    // ------------------------------------------------------ demo projects

    public function test_it_seeds_five_demo_projects_scoped_to_their_organizations(): void
    {
        $this->seed(OrganizationDemoSeeder::class);

        $alpha = Organization::where('name', 'Organization Alpha')->firstOrFail();
        $beta = Organization::where('name', 'Organization Beta')->firstOrFail();
        $gamma = Organization::where('name', 'Organization Gamma')->firstOrFail();
        $delta = Organization::where('name', 'Organization Delta')->firstOrFail();

        $this->assertDatabaseHas('projects', ['ticket_prefix' => 'AWS', 'organization_id' => $alpha->id]);
        $this->assertDatabaseHas('projects', ['ticket_prefix' => 'AHR', 'organization_id' => $alpha->id]);
        $this->assertDatabaseHas('projects', ['ticket_prefix' => 'BMA', 'organization_id' => $beta->id]);
        $this->assertDatabaseHas('projects', ['ticket_prefix' => 'GMI', 'organization_id' => $gamma->id]);
        $this->assertDatabaseHas('projects', ['ticket_prefix' => 'DAI', 'organization_id' => $delta->id]);
    }

    /**
     * The whole point of this seeder: member@example.test is a real member
     * of Alpha, is explicitly added to "Company Website" (AWS), and is NOT
     * added to "Internal HR Tool" (AHR) - so they must see the former and
     * not the latter, purely through Project::accessibleBy(), the same
     * scope the real Filament ProjectResource list query uses.
     */
    public function test_a_plain_member_only_sees_the_project_they_were_explicitly_added_to(): void
    {
        $this->seed(OrganizationDemoSeeder::class);

        $member = User::where('email', 'member@example.test')->firstOrFail();
        $alpha = Organization::where('name', 'Organization Alpha')->firstOrFail();
        OrganizationContext::switch($member, $alpha->id);

        $visible = Project::where('ticket_prefix', 'AWS')->firstOrFail();
        $hidden = Project::where('ticket_prefix', 'AHR')->firstOrFail();

        $this->assertTrue(Project::accessibleBy($member)->whereKey($visible->id)->exists());
        $this->assertFalse(Project::accessibleBy($member)->whereKey($hidden->id)->exists());
    }

    /**
     * The Organization Admin's mirror image: sees every project in Alpha
     * (org authority), including the one member@example.test cannot see -
     * but per Fase 6b cannot delete either of them.
     */
    public function test_an_organization_admin_sees_every_project_but_cannot_delete_them(): void
    {
        $this->seed(PermissionsSeeder::class);
        $this->seed(EmployeeRoleSeeder::class);
        $this->seed(OrganizationDemoSeeder::class);

        $admin = User::where('email', 'admin@example.test')->firstOrFail();
        $alpha = Organization::where('name', 'Organization Alpha')->firstOrFail();
        OrganizationContext::switch($admin, $alpha->id);

        $visible = Project::where('ticket_prefix', 'AWS')->firstOrFail();
        $hidden = Project::where('ticket_prefix', 'AHR')->firstOrFail();

        $this->assertTrue(Project::accessibleBy($admin)->whereKey($visible->id)->exists());
        $this->assertTrue(Project::accessibleBy($admin)->whereKey($hidden->id)->exists());
        $this->assertFalse($admin->can('delete', $hidden));
    }

    public function test_it_seeds_several_tickets_per_demo_project(): void
    {
        $this->seed(OrganizationDemoSeeder::class);

        // 5 on Company Website, 3 on Internal HR Tool, 4 on Mobile App
        // Revamp, 4 on Aplikasi Manajemen Inventori, 4 on Dashboard Analitik
        // Internal.
        $this->assertSame(20, Ticket::count());
        $this->assertSame(5, Ticket::whereHas('project', fn ($q) => $q->where('ticket_prefix', 'AWS'))->count());
        $this->assertSame(3, Ticket::whereHas('project', fn ($q) => $q->where('ticket_prefix', 'AHR'))->count());
        $this->assertSame(4, Ticket::whereHas('project', fn ($q) => $q->where('ticket_prefix', 'BMA'))->count());
        $this->assertSame(4, Ticket::whereHas('project', fn ($q) => $q->where('ticket_prefix', 'GMI'))->count());
        $this->assertSame(4, Ticket::whereHas('project', fn ($q) => $q->where('ticket_prefix', 'DAI'))->count());
    }

    /**
     * Every demo project gets at least one Epic (Roadmap draws its timeline
     * from Epic::starts_at/ends_at), so the Road Map page always has
     * something to show without the user having to build it by hand first.
     */
    public function test_each_demo_project_gets_at_least_one_dated_epic(): void
    {
        $this->seed(OrganizationDemoSeeder::class);

        foreach (['AWS', 'AHR', 'BMA', 'GMI', 'DAI'] as $prefix) {
            $project = Project::where('ticket_prefix', $prefix)->firstOrFail();

            $this->assertGreaterThan(0, $project->epics()->count(), "{$prefix} should have at least one Epic");
        }
    }

    /**
     * Company Website, Mobile App Revamp, Aplikasi Manajemen Inventori and
     * Dashboard Analitik Internal are the four projects built to show a live
     * board (Kanban/Scrum board queries Project::currentSprint) - Internal HR
     * Tool deliberately stays lighter (see populateHrTool()'s docblock), so
     * it is excluded here on purpose, not an oversight.
     */
    public function test_the_four_fuller_demo_projects_have_an_active_sprint(): void
    {
        $this->seed(OrganizationDemoSeeder::class);

        foreach (['AWS', 'BMA', 'GMI', 'DAI'] as $prefix) {
            $project = Project::where('ticket_prefix', $prefix)->firstOrFail();

            $this->assertNotNull($project->currentSprint, "{$prefix} should have an active Sprint");
        }
    }

    public function test_re_running_does_not_duplicate_demo_projects_or_tickets(): void
    {
        $this->seed(OrganizationDemoSeeder::class);
        $this->seed(OrganizationDemoSeeder::class);

        $this->assertSame(5, Project::whereIn('ticket_prefix', ['AWS', 'AHR', 'BMA', 'GMI', 'DAI'])->count());
        $this->assertSame(20, Ticket::count());
    }

    /**
     * The "sync guarantee" this seeder is built around: the same seeder
     * class populates the local dev database (`php artisan db:seed
     * --class=OrganizationDemoSeeder`, for manual browser testing) and the
     * SQLite test database (`$this->seed(...)` here) - so what a developer
     * clicks through by hand and what the automated suite asserts can never
     * silently drift apart. This test is the single place that checks the
     * general-QA shape every demo Organization must have (at least one
     * correctly-scoped Project each) and that re-seeding twice never
     * duplicates rows, independent of the more specific per-project
     * assertions elsewhere in this file.
     */
    public function test_every_demo_organization_has_at_least_one_correctly_scoped_project_and_reseeding_is_idempotent(): void
    {
        $this->seed(OrganizationDemoSeeder::class);

        $organizations = Organization::whereIn('name', [
            'Organization Alpha',
            'Organization Beta',
            'Organization Gamma',
            'Organization Delta',
        ])->get();

        $this->assertCount(4, $organizations, 'all four demo organizations should exist after seeding');

        foreach ($organizations as $organization) {
            $projects = Project::where('organization_id', $organization->id)->get();

            $this->assertGreaterThanOrEqual(
                1,
                $projects->count(),
                "{$organization->name} should have at least one Project"
            );

            foreach ($projects as $project) {
                $this->assertSame(
                    $organization->id,
                    $project->organization_id,
                    "Project '{$project->name}' should be attached to {$organization->name}"
                );
            }
        }

        $userCountBefore = User::count();
        $organizationCountBefore = Organization::count();
        $projectCountBefore = Project::count();
        $ticketCountBefore = Ticket::count();

        // Re-running must never throw and must never duplicate rows - the
        // exact guarantee a developer relies on when re-seeding their local
        // dev database to reset manually-poked-at demo data.
        $this->seed(OrganizationDemoSeeder::class);

        $this->assertSame($userCountBefore, User::count());
        $this->assertSame($organizationCountBefore, Organization::count());
        $this->assertSame($projectCountBefore, Project::count());
        $this->assertSame($ticketCountBefore, Ticket::count());
    }
}
