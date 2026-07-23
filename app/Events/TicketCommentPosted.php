<?php

namespace App\Events;

use App\Models\TicketComment;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast when a comment is posted to a ticket, so anyone viewing the ticket
 * (or the project board) sees it appear live.
 */
class TicketCommentPosted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels, ChecksBroadcastDriver;

    public function __construct(public TicketComment $comment)
    {
    }

    /**
     * Broadcast on both the ticket and its project private channels.
     *
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('ticket.' . $this->comment->ticket_id),
            new PrivateChannel('project.' . $this->comment->ticket->project_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'ticket.comment.posted';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'comment_id' => $this->comment->id,
            'ticket_id' => $this->comment->ticket_id,
            'content' => $this->comment->content,
            'user_id' => $this->comment->user_id,
        ];
    }
}
