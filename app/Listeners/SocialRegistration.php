<?php

namespace App\Listeners;

use App\Listeners\Concerns\AssignsDefaultRole;
use DutchCodingCompany\FilamentSocialite\Events\Registered;

class SocialRegistration
{
    use AssignsDefaultRole;

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
        $this->assignDefaultRole($user);
    }
}
