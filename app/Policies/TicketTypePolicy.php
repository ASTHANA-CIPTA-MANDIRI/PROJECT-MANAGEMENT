<?php

namespace App\Policies;

use App\Models\TicketType;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class TicketTypePolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function viewAny(User $user)
    {
        return $user->can('List ticket types');
    }

    /**
     * Determine whether the user can view the model.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function view(User $user, TicketType $ticketType)
    {
        return $user->can('View ticket type');
    }

    /**
     * Determine whether the user can create models.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function create(User $user)
    {
        return $user->can('Create ticket type');
    }

    /**
     * Determine whether the user can update the model.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function update(User $user, TicketType $ticketType)
    {
        return $user->can('Update ticket type');
    }

    /**
     * Determine whether the user can delete the model.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function delete(User $user, TicketType $ticketType)
    {
        return $user->can('Delete ticket type');
    }

    /**
     * Determine whether the user can bulk delete models.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function deleteAny(User $user)
    {
        return $user->can('Delete ticket type');
    }

    /**
     * Restoring is the undo of delete, so it is gated the same way.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function restore(User $user, TicketType $ticketType)
    {
        return $this->delete($user, $ticketType);
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
