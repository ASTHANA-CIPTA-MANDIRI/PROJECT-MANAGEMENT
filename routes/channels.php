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
Broadcast::channel('project.{project}', function ($user, Project $project) {
    return $project->owner_id === $user->id
        || $project->users()->where('users.id', $user->id)->exists();
});

// A user may listen on a ticket's channel if they can access the ticket:
// its owner/responsible, or a member/owner of its project.
Broadcast::channel('ticket.{ticket}', function ($user, Ticket $ticket) {
    return $ticket->owner_id === $user->id
        || $ticket->responsible_id === $user->id
        || $ticket->project->owner_id === $user->id
        || $ticket->project->users()->where('users.id', $user->id)->exists();
});
