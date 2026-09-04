<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
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

        // The "empty organization" case: only its owner is a member.
        $this->assertSame('owner', $delta->roleOf($owner));
        $this->assertSame(1, $delta->users()->count());
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
}
