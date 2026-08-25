<?php

namespace App\Observers;

use App\Events\TicketStatusChanged;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Notifications\TicketCreated;
use App\Notifications\TicketStatusUpdated;

/**
 * Side effects for the Ticket lifecycle, kept out of the model itself:
 * code/order generation, status-change activity + notifications + broadcast,
 * sprint/epic syncing, and statistics-cache invalidation.
 */
class TicketObserver
{
    public function creating(Ticket $ticket): void
    {
        // A ticket cannot exist without its project: both the code and the
        // order come from it. Fail here, with the project id in the message,
        // instead of dereferencing null or letting a code-less row hit the
        // NOT NULL constraint further down.
        $project = Project::where('id', $ticket->project_id)->firstOrFail();

        // A MAX() in SQL: reading the relation as a property loaded every
        // ticket of the project into memory just to look at one column, and it
        // read the last row by insertion order rather than the highest order.
        // The Kanban board's order column is per status, not per project, so
        // the max has to be scoped the same way or the new card lands in the
        // wrong spot in its column.
        $highestOrder = $project->tickets()->where('status_id', $ticket->status_id)->max('order');

        // Numbers come from the project's counter, never from a live count:
        // deleting a ticket must not free its code for the next one.
        $ticket->code = $project->ticket_prefix.'-'.$project->allocateTicketNumber();
        $ticket->order = $highestOrder === null ? 0 : ((int) $highestOrder) + 1;
    }

    public function created(Ticket $ticket): void
    {
        if ($ticket->sprint_id && $ticket->sprint->epic_id) {
            Ticket::where('id', $ticket->id)->update(['epic_id' => $ticket->sprint->epic_id]);
        }
        foreach ($ticket->watchers as $user) {
            $user->notify(new TicketCreated($ticket));
        }
        $ticket->project?->forgetStatistics();
    }

    public function updating(Ticket $ticket): void
    {
        // Eloquent already keeps the as-loaded-from-DB values on the model
        // itself; no need for a second query to read them back.
        $oldStatus = $ticket->getOriginal('status_id');
        if ($oldStatus != $ticket->status_id) {
            $activity = TicketActivity::create([
                'ticket_id' => $ticket->id,
                'old_status_id' => $oldStatus,
                'new_status_id' => $ticket->status_id,
                // Null for system-driven changes (queue, console, seeders).
                'user_id' => auth()->id(),
            ]);
            foreach ($ticket->watchers as $user) {
                $user->notify(new TicketStatusUpdated($ticket, $activity));
            }
            // Live update for anyone watching the project board.
            TicketStatusChanged::dispatch($ticket, $oldStatus, (int) $ticket->status_id, auth()->id());
        }
    }

    public function updated(Ticket $ticket): void
    {
        // The main save() just finished, so this write no longer races with
        // it. getOriginal() still holds the pre-update value here: Eloquent
        // only syncs it after the 'saved' event, which fires later.
        $oldSprint = $ticket->getOriginal('sprint_id');
        if ($oldSprint && ! $ticket->sprint_id) {
            Ticket::where('id', $ticket->id)->update(['epic_id' => null]);
        } elseif ($ticket->sprint_id && $ticket->sprint->epic_id) {
            Ticket::where('id', $ticket->id)->update(['epic_id' => $ticket->sprint->epic_id]);
        }
    }

    public function deleted(Ticket $ticket): void
    {
        $ticket->project?->forgetStatistics();
    }
}
