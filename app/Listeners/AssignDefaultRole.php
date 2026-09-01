<?php

namespace App\Listeners;

use App\Listeners\Concerns\AssignsDefaultRole;
use Illuminate\Auth\Events\Registered;

class AssignDefaultRole
{
    use AssignsDefaultRole;

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

        $this->assignDefaultRole($user);
    }
}
