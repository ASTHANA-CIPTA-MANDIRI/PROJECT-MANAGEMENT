<?php

namespace App\Policies;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class TicketPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function viewAny(User $user)
    {
        return $user->can('List tickets');
    }

    /**
     * Determine whether the user can view the model.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function view(User $user, Ticket $ticket)
    {
        return $user->can('View ticket') && $this->isInvolved($user, $ticket);
    }

    /**
     * Determine whether the user can create models.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function create(User $user)
    {
        return $user->can('Create ticket');
    }

    /**
     * Determine whether the user can update the model.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function update(User $user, Ticket $ticket)
    {
        return $user->can('Update ticket') && $this->isInvolved($user, $ticket);
    }

    /**
     * Determine whether the user can delete the model.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function delete(User $user, Ticket $ticket)
    {
        return $user->can('Delete ticket') && $this->isInvolved($user, $ticket);
    }

    /**
     * Determine whether the user can bulk delete models.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function deleteAny(User $user)
    {
        return $user->can('Delete ticket');
    }

    /**
     * Restoring is the undo of delete, so it is gated the same way.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function restore(User $user, Ticket $ticket)
    {
        return $this->delete($user, $ticket);
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
     * The object-level half of every ticket ability: the user is the ticket's
     * owner, the person responsible for it, or has access to the project it
     * lives in. view/update/delete only ever differ in the permission they
     * pair this with, so it lives in one place.
     */
    private function isInvolved(User $user, Ticket $ticket): bool
    {
        return $ticket->owner_id === $user->id
            || $ticket->responsible_id === $user->id
            || $ticket->project->isAccessibleBy($user);
    }
}
