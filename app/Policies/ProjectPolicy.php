<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use App\Support\OrganizationContext;
use Illuminate\Auth\Access\HandlesAuthorization;

class ProjectPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function viewAny(User $user)
    {
        return $user->can('List projects');
    }

    /**
     * Determine whether the user can view the model.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function view(User $user, Project $project)
    {
        return $user->can('View project') && $project->isAccessibleBy($user);
    }

    /**
     * Determine whether the user can create models.
     *
     * Phase 5.3B: the flat `Create project` permission alone used to be the
     * entire gate, with zero regard for Organization membership — meaning a
     * permission-holder with no Organization at all (or only a Member role
     * in one) could create a project, while an actual Organization Owner/
     * Admin without that flat permission could not. Organization role does
     * not replace the platform permission system (both are still required,
     * same as every other ability in this Policy pairs a permission with an
     * object-level check) — it adds the missing tenant-authority half: the
     * user's *current* Organization (OrganizationContext — never a
     * client-supplied id) must exist and be one they own or administer.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function create(User $user)
    {
        $organization = OrganizationContext::current($user);

        return $user->can('Create project')
            && $organization !== null
            && $organization->isManageableBy($user);
    }

    /**
     * Determine whether the user can update the model.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function update(User $user, Project $project)
    {
        return $user->can('Update project') && $project->isManageableBy($user);
    }

    /**
     * Determine whether the user can delete the model.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function delete(User $user, Project $project)
    {
        return $user->can('Delete project') && $project->isManageableBy($user);
    }

    /**
     * Determine whether the user can bulk delete models.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function deleteAny(User $user)
    {
        return $user->can('Delete project');
    }

    /**
     * Restoring is the undo of delete, so it is gated the same way.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function restore(User $user, Project $project)
    {
        return $this->delete($user, $project);
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
