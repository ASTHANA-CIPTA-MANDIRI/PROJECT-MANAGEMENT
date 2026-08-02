<?php

namespace App\Policies;

use App\Models\Label;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class LabelPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user)
    {
        return $user->can('List labels');
    }

    public function view(User $user, Label $label)
    {
        return $user->can('View label');
    }

    public function create(User $user)
    {
        return $user->can('Create label');
    }

    public function update(User $user, Label $label)
    {
        return $user->can('Update label');
    }

    public function delete(User $user, Label $label)
    {
        return $user->can('Delete label');
    }
}
