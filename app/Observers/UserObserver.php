<?php

namespace App\Observers;

use App\Models\User;
use App\Notifications\UserCreatedNotification;
use Ramsey\Uuid\Uuid;

/**
 * Side effects for the User lifecycle, kept out of the model: admin-created
 * ("db") accounts get a random password + validation token and are notified,
 * and the platform can never lose its last Super Admin.
 */
class UserObserver
{
    public function creating(User $user): void
    {
        if ($user->type == 'db') {
            $user->password = bcrypt(uniqid());
            $user->creation_token = Uuid::uuid4()->toString();
        }
    }

    public function created(User $user): void
    {
        if ($user->type == 'db') {
            $user->notify(new UserCreatedNotification($user));
        }
    }

    public function deleting(User $user): bool
    {
        // Cancel the delete if this is the last Super Admin.
        return ! $user->isLastSuperAdmin();
    }
}
