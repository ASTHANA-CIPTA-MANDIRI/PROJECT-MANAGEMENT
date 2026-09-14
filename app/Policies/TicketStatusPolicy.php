<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\TicketStatus;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class TicketStatusPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function viewAny(User $user)
    {
        return $user->can('List ticket statuses');
    }

    /**
     * Determine whether the user can view the model.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function view(User $user, TicketStatus $ticketStatus)
    {
        return $user->can('View ticket status') && $this->belongsToAnAccessibleProject($user, $ticketStatus);
    }

    /**
     * Determine whether the user can create models.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function create(User $user)
    {
        return $user->can('Create ticket status');
    }

    /**
     * Determine whether the user can update the model.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function update(User $user, TicketStatus $ticketStatus)
    {
        return $user->can('Update ticket status') && $this->belongsToAnAccessibleProject($user, $ticketStatus);
    }

    /**
     * Determine whether the user can delete the model.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function delete(User $user, TicketStatus $ticketStatus)
    {
        return $user->can('Delete ticket status') && $this->belongsToAnAccessibleProject($user, $ticketStatus);
    }

    /**
     * A TicketStatus with no project_id is the shared, global default set
     * (TicketStatusSeeder, status_type='default' projects) - visible to
     * anyone holding the flat permission, the same "legacy/shared" rule
     * every other lookup model in this app uses for a null tenant scope.
     * A TicketStatus WITH a project_id is that one project's own custom set
     * (StatusesRelationManager, status_type='custom') and must never be
     * reachable by guessing/enumerating its id through TicketStatusResource's
     * own view/edit/delete pages unless the caller can actually reach that
     * project - mirrors EpicPolicy::belongsToAnAccessibleProject() exactly,
     * the same "no permissions of its own, delegate to the parent Project"
     * shape.
     */
    private function belongsToAnAccessibleProject(User $user, TicketStatus $ticketStatus): bool
    {
        if ($ticketStatus->project_id === null) {
            return true;
        }

        return Project::accessibleBy($user)
            ->whereKey($ticketStatus->project_id)
            ->exists();
    }

    /**
     * Determine whether the user can bulk delete models.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function deleteAny(User $user)
    {
        return $user->can('Delete ticket status');
    }

    /**
     * Restoring is the undo of delete, so it is gated the same way.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function restore(User $user, TicketStatus $ticketStatus)
    {
        return $this->delete($user, $ticketStatus);
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
     * Determine whether the user can reorder the models.
     *
     * TicketStatusResource's table is reorderable, and the order it writes drives
     * the board column order for everyone. That is an edit, so it needs the same
     * permission as any other edit - not merely the permission to see the list.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function reorder(User $user)
    {
        return $user->can('Update ticket status');
    }
}
