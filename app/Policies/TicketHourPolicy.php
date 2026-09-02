<?php

namespace App\Policies;

use App\Models\TicketHour;
use App\Models\User;
use App\Support\OrganizationContext;
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
        return $user->can('List timesheet data')
            && $ticketHour->user_id === $user->id
            && $this->isWithinOrganizationContext($user, $ticketHour);
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
        return $user->can('List timesheet data')
            && $ticketHour->user_id === $user->id
            && $this->isWithinOrganizationContext($user, $ticketHour);
    }

    /**
     * Determine whether the user can delete the model.
     *
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function delete(User $user, TicketHour $ticketHour)
    {
        return $user->can('List timesheet data')
            && $ticketHour->user_id === $user->id
            && $this->isWithinOrganizationContext($user, $ticketHour);
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

    /**
     * TicketHour is always scoped to its own owner (own rows only, never a
     * cross-user leak) - but a user who belongs to more than one
     * Organization could still have logged hours against a ticket that
     * belongs to an Organization other than their current context. This
     * mirrors Project::isWithinOrganizationContext(): a row whose ticket's
     * project has no organization at all is left ungated, exactly like a
     * null-organization Project stays reachable by its existing
     * owner/member.
     */
    private function isWithinOrganizationContext(User $user, TicketHour $ticketHour): bool
    {
        $organizationId = $ticketHour->ticket?->project?->organization_id;

        return $organizationId === null
            || $organizationId === OrganizationContext::current($user)?->id;
    }
}
