<?php

namespace App\Listeners\Concerns;

use App\Models\Role;
use App\Models\User;
use App\Settings\GeneralSettings;

trait AssignsDefaultRole
{
    /**
     * Assign the configured default role to a user who has none yet.
     * Without a role the user has no permissions and would be blocked by
     * User::canAccessFilament() (403).
     */
    private function assignDefaultRole(User $user): void
    {
        $defaultRoleSettings = app(GeneralSettings::class)->default_role;
        if ($defaultRoleSettings && $defaultRole = Role::where('id', $defaultRoleSettings)->first()) {
            $user->syncRoles([$defaultRole]);
        }
    }
}
