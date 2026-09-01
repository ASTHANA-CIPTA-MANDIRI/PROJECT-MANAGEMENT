<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The command must actually flag the dangerous states — a report that always
 * says "OK" would be worse than none.
 */
class AuditPrivilegeEscalationCommandTest extends TestCase
{
    use RefreshDatabase;

    private function healthyInstance(): void
    {
        Permission::firstOrCreate(['name' => 'List projects']);
        $superAdminRole = Role::create(['name' => 'Super Admin']);

        $admin = User::factory()->create([
            'two_factor_secret' => encrypt('SECRET'),
            'two_factor_confirmed_at' => now(),
        ]);
        $admin->syncRoles([$superAdminRole]);

        config(['system.security.require_2fa_for_super_admin' => true]);
        GeneralSettings::fake([
            'default_role' => null,
            'super_admin_role' => null,
            'enable_registration' => false,
            'enable_social_login' => false,
        ]);
    }

    public function test_a_healthy_instance_reports_no_risks(): void
    {
        $this->healthyInstance();

        $this->artisan('security:audit-escalation')
            ->expectsOutputToContain('No escalation risks found.')
            ->assertSuccessful();
    }

    public function test_it_flags_an_all_permission_default_role(): void
    {
        $this->healthyInstance();

        foreach (['Update role', 'Update user'] as $name) {
            Permission::firstOrCreate(['name' => $name]);
        }
        $legacy = Role::create(['name' => 'Default role']);
        $legacy->syncPermissions(Permission::all()->pluck('name')->toArray());

        GeneralSettings::fake([
            'default_role' => (string) $legacy->id,
            'super_admin_role' => null,
            'enable_registration' => true,
            'enable_social_login' => false,
        ]);

        $this->artisan('security:audit-escalation')
            ->expectsOutputToContain('can manage roles/users/settings')
            ->assertFailed();
    }

    public function test_it_flags_the_super_admin_role_being_the_default_role(): void
    {
        $this->healthyInstance();
        $superAdminRole = Role::where('name', 'Super Admin')->first();

        GeneralSettings::fake([
            'default_role' => (string) $superAdminRole->id,
            'super_admin_role' => null,
            'enable_registration' => true,
            'enable_social_login' => false,
        ]);

        $this->artisan('security:audit-escalation')
            ->expectsOutputToContain('every registrant would be an admin')
            ->assertFailed();
    }

    public function test_it_flags_a_super_admin_without_two_factor(): void
    {
        $this->healthyInstance();

        $weak = User::factory()->create(['email' => 'weak@example.com']);
        $weak->syncRoles([Role::where('name', 'Super Admin')->first()]);

        $this->artisan('security:audit-escalation')
            ->expectsOutputToContain('have not confirmed 2FA')
            ->assertFailed();
    }

    public function test_it_flags_a_disabled_two_factor_policy(): void
    {
        $this->healthyInstance();
        config(['system.security.require_2fa_for_super_admin' => false]);

        $this->artisan('security:audit-escalation')
            ->expectsOutputToContain('REQUIRE_2FA_FOR_SUPER_ADMIN is off')
            ->assertFailed();
    }

    public function test_it_flags_an_instance_with_no_super_admin_at_all(): void
    {
        Permission::firstOrCreate(['name' => 'List projects']);
        config(['system.security.require_2fa_for_super_admin' => true]);
        GeneralSettings::fake([
            'default_role' => null,
            'super_admin_role' => null,
            'enable_registration' => false,
            'enable_social_login' => false,
        ]);

        $this->artisan('security:audit-escalation')
            ->expectsOutputToContain('nobody is a Super Admin')
            ->assertFailed();
    }
}
