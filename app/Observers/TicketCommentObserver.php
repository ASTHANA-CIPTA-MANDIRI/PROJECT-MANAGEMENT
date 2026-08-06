<?php

namespace App\Observers;

use App\Events\TicketCommentPosted;
use App\Models\TicketComment;
use App\Notifications\TicketCommented;
use App\Notifications\UserMentioned;
use App\Support\Mentions;

/**
 * Notifies watchers and broadcasts a live update when a comment is posted.
 */
class TicketCommentObserver
{
    /**
     * Denormalize the owning ticket's project_id so SearchService can scope
     * comment search by project without pulling every ticket id into memory
     * first (see TicketComment::toSearchableArray).
     */
    public function creating(TicketComment $comment): void
    {
        if (! $comment->project_id) {
            $comment->project_id = $comment->ticket->project_id;
        }
    }

    public function created(TicketComment $comment): void
    {
        // Members named with "@" get the more specific mention notice; the
        // comment author is never notified about mentioning themselves.
        $mentioned = Mentions::mentioned($comment->content, $comment->ticket->watchers)
            ->where('id', '!=', $comment->user_id);

        foreach ($comment->ticket->watchers as $user) {
            if ($mentioned->contains('id', $user->id)) {
                $user->notify(new UserMentioned($comment));
            } else {
                $user->notify(new TicketCommented($comment));
            }
        }
        // Live update for anyone viewing the ticket or project board.
        TicketCommentPosted::dispatch($comment);
    }
}
