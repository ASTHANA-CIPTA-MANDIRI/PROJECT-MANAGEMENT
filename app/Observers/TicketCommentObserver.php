<?php

namespace App\Observers;

use App\Events\TicketCommentPosted;
use App\Models\TicketComment;
use App\Notifications\TicketCommented;

/**
 * Notifies watchers and broadcasts a live update when a comment is posted.
 */
class TicketCommentObserver
{
    public function created(TicketComment $comment): void
    {
        foreach ($comment->ticket->watchers as $user) {
            $user->notify(new TicketCommented($comment));
        }
        // Live update for anyone viewing the ticket or project board.
        TicketCommentPosted::dispatch($comment);
    }
}
