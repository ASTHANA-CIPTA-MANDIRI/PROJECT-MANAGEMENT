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
        return $user->can('View ticket')
            && (
                $ticket->owner_id === $user->id
                ||
                $ticket->responsible_id === $user->id
                ||
                $ticket->project->users()->where('users.id', $user->id)->count()
                ||
                $ticket->project->owner_id === $user->id
            );
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
        return $user->can('Update ticket')
            && (
                $ticket->owner_id === $user->id
                ||
                $ticket->responsible_id === $user->id
                ||
                $ticket->project->users()->where('users.id', $user->id)->count()
                ||
                $ticket->project->owner_id === $user->id
            );
    }

    /**
     * Determine whether the user can delete the model.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function delete(User $user, Ticket $ticket)
    {
        return $user->can('Delete ticket');
    }
}
