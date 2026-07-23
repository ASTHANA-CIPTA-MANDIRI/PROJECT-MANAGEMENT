<?php

namespace App\Listeners;

use App\Models\Role;
use App\Settings\GeneralSettings;
use DutchCodingCompany\FilamentSocialite\Events\Registered;

class SocialRegistration
{
    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     *
     * @return void
     */
    public function handle(Registered $event)
    {
        $user = $event->socialiteUser->user;
        $user->email_verified_at = now();
        $user->save();

        // Assign the configured default role so the newly registered social
        // user has permissions and can access the panel. Without a role, the
        // user would be blocked by User::canAccessFilament().
        $defaultRoleSettings = app(GeneralSettings::class)->default_role;
        if ($defaultRoleSettings && $defaultRole = Role::where('id', $defaultRoleSettings)->first()) {
            $user->syncRoles([$defaultRole]);
        }
    }
}
