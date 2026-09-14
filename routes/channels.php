<?php

use App\Models\Project;
use App\Models\Ticket;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// A user may listen on a project's channel if they own or belong to it.
// Delegates to Project::isAccessibleBy() (the single source of truth for
// project access, see ProjectPolicy/TicketPolicy) instead of re-implementing
// the owner/member check inline, so the two never drift apart.
Broadcast::channel('project.{project}', function ($user, Project $project) {
    return $project->isAccessibleBy($user);
});

// A user may listen on a ticket's channel if they can access the ticket:
// its owner/responsible, or a member/owner of its project. Delegates to
// Ticket::isAccessibleBy() (audit finding, pre-Fase 7: the owner_id/
// responsible_id checks used to run inline here and short-circuit before
// ever checking the project's Organization/trial context — a ticket's
// owner could keep listening on this channel after their Organization's
// trial ended, the same class of gap scopeVisibleTo() already closed for
// the query version of this check).
Broadcast::channel('ticket.{ticket}', function ($user, Ticket $ticket) {
    return $ticket->isAccessibleBy($user);
});
