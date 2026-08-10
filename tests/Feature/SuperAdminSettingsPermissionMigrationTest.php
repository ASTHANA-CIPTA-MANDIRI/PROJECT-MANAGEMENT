<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * "Manage super admin settings" is created by PermissionsSeeder for fresh
 * installs; an already running instance gets it from this migration. Without
 * it nobody could repoint the Super Admin role at all — there is no
 * Gate::before bypass for Super Admins in this app.
 */
class SuperAdminSettingsPermissionMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const PERMISSION = 'Manage super admin settings';

    private function runMigration(): void
    {
        $migration = require database_path('migrations/2026_08_10_000000_grant_manage_super_admin_settings_to_the_super_admin_role.php');
        $migration->up();
    }

    public function test_it_grants_the_permission_to_the_role_named_super_admin(): void
    {
        $superAdmin = Role::create(['name' => 'Super Admin']);
        $other = Role::create(['name' => 'Admin']);

        $this->runMigration();

        $this->assertTrue($superAdmin->fresh()->hasPermissionTo(self::PERMISSION));
        $this->assertFalse(
            $other->fresh()->hasPermissionTo(self::PERMISSION),
            'only the Super Admin role may repoint the Super Admin role'
        );
    }

    public function test_it_follows_the_role_configured_in_settings(): void
    {
        $configured = Role::create(['name' => 'Owner']);
        $named = Role::create(['name' => 'Super Admin']); // NOT the configured one

        DB::table('settings')
            ->where('group', 'general')
            ->where('name', 'super_admin_role')
            ->update(['payload' => json_encode((string) $configured->id)]);

        $this->runMigration();

        $this->assertTrue($configured->fresh()->hasPermissionTo(self::PERMISSION));
        $this->assertFalse($named->fresh()->hasPermissionTo(self::PERMISSION));
    }

    public function test_it_creates_the_permission_even_without_a_super_admin_role(): void
    {
        $this->runMigration();

        $this->assertDatabaseHas('permissions', ['name' => self::PERMISSION]);
    }

    public function test_it_is_idempotent(): void
    {
        $superAdmin = Role::create(['name' => 'Super Admin']);

        $this->runMigration();
        $this->runMigration();

        $this->assertSame(1, Permission::where('name', self::PERMISSION)->count());
        $this->assertTrue($superAdmin->fresh()->hasPermissionTo(self::PERMISSION));
    }
}
