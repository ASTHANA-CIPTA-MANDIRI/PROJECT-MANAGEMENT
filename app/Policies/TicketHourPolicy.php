<?php

namespace App\Policies;

use App\Models\TicketHour;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class TicketHourPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function viewAny(User $user)
    {
        return $user->can('List timesheet data');
    }

    /**
     * Determine whether the user can view the model.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function view(User $user, TicketHour $ticketHour)
    {
        return $user->can('List timesheet data') && $ticketHour->user_id === $user->id;
    }

    /**
     * Determine whether the user can create models.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function create(User $user)
    {
        return $user->can('List timesheet data');
    }

    /**
     * Determine whether the user can update the model.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function update(User $user, TicketHour $ticketHour)
    {
        return $user->can('List timesheet data') && $ticketHour->user_id === $user->id;
    }

    /**
     * Determine whether the user can delete the model.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function delete(User $user, TicketHour $ticketHour)
    {
        return $user->can('List timesheet data') && $ticketHour->user_id === $user->id;
    }

    /**
     * Determine whether the user can bulk delete models.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function deleteAny(User $user)
    {
        return $user->can('List timesheet data');
    }
}
