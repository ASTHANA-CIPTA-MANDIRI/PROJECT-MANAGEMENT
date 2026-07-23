<?php

namespace App\Listeners;

use App\Models\Role;
use App\Settings\GeneralSettings;
use Illuminate\Auth\Events\Registered;

class AssignDefaultRole
{
    /**
     * Handle the event.
     *
     * Assigns the configured default role to a user who just registered
     * through the self-registration form. Without a role the user has no
     * permissions and would be blocked by User::canAccessFilament() (403).
     *
     * @return void
     */
    public function handle(Registered $event)
    {
        $user = $event->user;

        // Do not override roles a user may already have been given.
        if (method_exists($user, 'roles') && $user->roles()->exists()) {
            return;
        }

        $defaultRoleSettings = app(GeneralSettings::class)->default_role;
        if ($defaultRoleSettings && $defaultRole = Role::where('id', $defaultRoleSettings)->first()) {
            $user->syncRoles([$defaultRole]);
        }
    }
}
