<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Repointing the Super Admin role moved out of "Manage general settings" and
 * behind its own "Manage super admin settings" permission, so a settings
 * manager can no longer promote a role they already hold.
 *
 * PermissionsSeeder creates that permission for fresh installs, but an already
 * running instance would be left with nobody able to change the setting at all
 * (there is no Gate::before bypass for Super Admins in this app). This grants
 * it to the effective Super Admin role — and to no one else, which is the
 * point of the split.
 */
return new class extends Migration
{
    private const PERMISSION = 'Manage super admin settings';

    public function up(): void
    {
        $permission = Permission::firstOrCreate(['name' => self::PERMISSION]);

        $this->superAdminRole()?->givePermissionTo($permission);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('name', self::PERMISSION)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * The role configured in Settings, or (when unset) one literally named
     * "Super Admin" — same resolution as User::superAdminRoleId(), but read
     * straight from the table so a half-migrated settings schema cannot break
     * the migration.
     */
    private function superAdminRole(): ?Role
    {
        $configuredId = null;

        try {
            $payload = DB::table('settings')
                ->where('group', 'general')
                ->where('name', 'super_admin_role')
                ->value('payload');

            $configuredId = $payload !== null ? json_decode($payload, true) : null;
        } catch (\Throwable $e) {
            // Settings table not available yet: fall back to the named role.
        }

        return filled($configuredId)
            ? Role::find($configuredId)
            : Role::where('name', 'Super Admin')->first();
    }
};
