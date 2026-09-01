<?php

namespace App\Policies;

use App\Models\TicketComment;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Comments have no permission module of their own: the panel lets the author
 * edit or delete their own comment, and lets a project administrator moderate
 * anyone's (see ViewTicket::authorizedComment). The API answers to the same
 * rule so both write paths agree on who may touch a comment.
 */
class TicketCommentPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, TicketComment $comment): bool
    {
        return $this->isAuthorOrManager($user, $comment);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, TicketComment $comment): bool
    {
        return $this->isAuthorOrManager($user, $comment);
    }

    /**
     * The author, or someone who manages the project the comment was posted
     * in. A comment on a trashed ticket has no project left to ask, which
     * leaves its author as the only one who can still clean it up.
     */
    private function isAuthorOrManager(User $user, TicketComment $comment): bool
    {
        return $comment->user_id === $user->id
            || (bool) $comment->ticket?->project?->isManageableBy($user);
    }
}
