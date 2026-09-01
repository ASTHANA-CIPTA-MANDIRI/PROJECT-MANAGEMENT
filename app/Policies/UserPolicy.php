<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class UserPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function viewAny(User $user)
    {
        return $user->can('List users');
    }

    /**
     * Determine whether the user can view the model.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function view(User $user, User $model)
    {
        return $user->can('View user');
    }

    /**
     * Determine whether the user can create models.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function create(User $user)
    {
        return $user->can('Create user');
    }

    /**
     * Determine whether the user can update the model.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function update(User $user, User $model)
    {
        // A Super Admin account is the platform's master key: only another
        // Super Admin may touch it (any field, not just roles), mirroring
        // RolePolicy::update()'s guard on the Super Admin role. Otherwise
        // the generic "Update user" permission would be a back door to it.
        if ($model->isSuperAdmin() && ! $user->isSuperAdmin()) {
            return false;
        }

        return $user->can('Update user');
    }

    /**
     * Determine whether the user can delete the model.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function delete(User $user, User $model)
    {
        if ($model->isSuperAdmin() && ! $user->isSuperAdmin()) {
            return false;
        }

        return $user->can('Delete user');
    }

    /**
     * Determine whether the user can bulk delete models.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function deleteAny(User $user)
    {
        return $user->can('Delete user');
    }

    /**
     * Restoring is the undo of delete, so it is gated the same way.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function restore(User $user, User $model)
    {
        return $this->delete($user, $model);
    }

    /**
     * Bulk restoring is the undo of bulk delete, so it is gated the same way.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function restoreAny(User $user)
    {
        return $this->deleteAny($user);
    }

    /**
     * Determine whether the user can attach a user to a project.
     *
     * Filament asks the *related* model's policy, so project membership changes
     * land here rather than on ProjectPolicy. The gate is therefore the project
     * permission, not a user-management one: this states the rule that the
     * Project edit page already enforces (ProjectPolicy::update) instead of
     * leaving the ability ungoverned.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function attach(User $user)
    {
        return $user->can('Update project');
    }

    /**
     * Determine whether the user can detach a user from a project.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function detach(User $user, User $model)
    {
        return $user->can('Update project');
    }

    /**
     * Determine whether the user can bulk detach users from a project.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function detachAny(User $user)
    {
        return $user->can('Update project');
    }
}
