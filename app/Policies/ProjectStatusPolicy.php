<?php

namespace App\Policies;

use App\Models\ProjectStatus;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ProjectStatusPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function viewAny(User $user)
    {
        return $user->can('List project statuses');
    }

    /**
     * Determine whether the user can view the model.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function view(User $user, ProjectStatus $projectStatus)
    {
        return $user->can('View project status');
    }

    /**
     * Determine whether the user can create models.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function create(User $user)
    {
        return $user->can('Create project status');
    }

    /**
     * Determine whether the user can update the model.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function update(User $user, ProjectStatus $projectStatus)
    {
        return $user->can('Update project status');
    }

    /**
     * Determine whether the user can delete the model.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function delete(User $user, ProjectStatus $projectStatus)
    {
        return $user->can('Delete project status');
    }

    /**
     * Determine whether the user can bulk delete models.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function deleteAny(User $user)
    {
        return $user->can('Delete project status');
    }

    /**
     * Restoring is the undo of delete, so it is gated the same way.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function restore(User $user, ProjectStatus $projectStatus)
    {
        return $this->delete($user, $projectStatus);
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
}
