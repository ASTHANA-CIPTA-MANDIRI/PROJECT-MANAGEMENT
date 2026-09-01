<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The command must actually clear the dangerous setting and never touch
 * users who already hold the role — downgrading them needs a human.
 */
class RemediateDefaultRoleCommandTest extends TestCase
{
    use RefreshDatabase;

    private function legacyDefaultRole(): Role
    {
        foreach (['Update role', 'Update user'] as $name) {
            Permission::firstOrCreate(['name' => $name]);
        }

        $legacy = Role::create(['name' => 'Default role']);
        $legacy->syncPermissions(Permission::all()->pluck('name')->toArray());

        return $legacy;
    }

    public function test_no_default_role_configured_needs_no_remediation(): void
    {
        GeneralSettings::fake(['default_role' => null]);

        $this->artisan('security:remediate-default-role')
            ->expectsOutputToContain('No default role is configured')
            ->assertSuccessful();
    }

    public function test_default_role_pointing_at_a_deleted_role_needs_no_remediation(): void
    {
        GeneralSettings::fake(['default_role' => '999999']);

        $this->artisan('security:remediate-default-role')
            ->expectsOutputToContain('no longer exists')
            ->assertSuccessful();
    }

    public function test_a_non_escalating_default_role_is_left_alone(): void
    {
        Permission::firstOrCreate(['name' => 'List projects']);
        $employee = Role::create(['name' => 'Employee']);
        $employee->syncPermissions(['List projects']);

        GeneralSettings::fake(['default_role' => (string) $employee->id]);

        $this->artisan('security:remediate-default-role')
            ->expectsOutputToContain('does not look escalating')
            ->assertSuccessful();

        $this->assertSame((string) $employee->id, app(GeneralSettings::class)->default_role);
    }

    public function test_it_clears_an_escalating_default_role_and_lists_existing_holders(): void
    {
        $legacy = $this->legacyDefaultRole();
        $holder = User::factory()->create(['email' => 'legacy-holder@example.com']);
        $holder->syncRoles([$legacy]);

        GeneralSettings::fake(['default_role' => (string) $legacy->id]);

        $this->artisan('security:remediate-default-role')
            ->expectsOutputToContain('is escalating')
            ->expectsOutputToContain('legacy-holder@example.com')
            ->expectsOutputToContain('Cleared the default role setting')
            ->assertSuccessful();

        $this->assertNull(app(GeneralSettings::class)->default_role);
        $this->assertTrue($holder->fresh()->hasRole('Default role'));
    }

    public function test_dry_run_reports_without_changing_anything(): void
    {
        $legacy = $this->legacyDefaultRole();

        GeneralSettings::fake(['default_role' => (string) $legacy->id]);

        $this->artisan('security:remediate-default-role', ['--dry-run' => true])
            ->expectsOutputToContain('[dry-run]')
            ->assertSuccessful();

        $this->assertSame((string) $legacy->id, app(GeneralSettings::class)->default_role);
    }

    public function test_the_super_admin_role_as_default_is_treated_as_escalating(): void
    {
        Permission::firstOrCreate(['name' => 'List projects']);
        $superAdmin = Role::create(['name' => 'Super Admin']);

        GeneralSettings::fake(['default_role' => (string) $superAdmin->id]);

        $this->artisan('security:remediate-default-role')
            ->expectsOutputToContain('is escalating')
            ->assertSuccessful();

        $this->assertNull(app(GeneralSettings::class)->default_role);
    }
}
