<?php

namespace Tests;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Spatie\Permission\PermissionRegistrar;

/**
 * Helpers for building users with a precise set of Spatie permissions.
 */
trait InteractsWithPermissions
{
    /**
     * Create a user granted exactly the given permission names.
     *
     * @param  array<int, string>  $permissions
     */
    protected function userWithPermissions(array $permissions = []): User
    {
        $role = Role::create(['name' => 'role_'.uniqid()]);

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }
        $role->syncPermissions($permissions);

        $user = User::factory()->create();
        $user->syncRoles([$role]);

        $this->flushPermissionCache();

        return $user->fresh();
    }

    /**
     * A user holding a role but no permissions at all.
     */
    protected function userWithoutPermissions(): User
    {
        return $this->userWithPermissions([]);
    }

    /**
     * Spatie caches permissions; tests must start from a clean slate.
     */
    protected function flushPermissionCache(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
