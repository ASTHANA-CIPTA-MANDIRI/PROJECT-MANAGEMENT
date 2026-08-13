<?php

namespace App\Listeners\Concerns;

use App\Models\Role;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\Log;

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
            // Never auto-grant the Super Admin role. The settings form now
            // refuses to point the default role at it, but a value stored
            // before that guard existed — or a role later designated as Super
            // Admin — would otherwise make self-registration a full admin
            // account. Leaving the user role-less is the platform's existing
            // safe state: they stay pending until an admin approves them.
            if ($defaultRole->isSuperAdminRole()) {
                Log::warning('Refused to auto-assign the Super Admin role as the default role.', [
                    'role_id' => $defaultRole->getKey(),
                    'user_id' => $user->getKey(),
                ]);

                return;
            }

            $user->syncRoles([$defaultRole]);
        }
    }
}
