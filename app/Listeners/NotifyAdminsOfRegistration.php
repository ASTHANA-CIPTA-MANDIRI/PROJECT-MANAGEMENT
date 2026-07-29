<?php

namespace App\Listeners;

use App\Models\Permission;
use App\Models\User;
use App\Notifications\NewUserPendingApproval;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Notification;

/**
 * New self-registrants get no role and stay pending; notify the admins who can
 * approve them (anyone able to update users) so approval isn't forgotten.
 */
class NotifyAdminsOfRegistration
{
    public function handle(Registered $event): void
    {
        // The permission may not exist yet (e.g. before the permissions seeder
        // runs); querying by an unknown permission would throw.
        if (! Permission::where('name', 'Update user')->exists()) {
            return;
        }

        $registrant = $event->user;

        $admins = User::permission('Update user')
            ->whereKeyNot($registrant->getKey())
            ->get();

        Notification::send($admins, new NewUserPendingApproval($registrant));
    }
}
