<?php

namespace App\Events;

use App\Models\Ticket;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast when a ticket moves from one status to another, so any client
 * watching the project's board updates live.
 */
class TicketStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels, ChecksBroadcastDriver;

    public function __construct(
        public Ticket $ticket,
        public ?int $oldStatusId,
        public int $newStatusId,
        public ?int $actorId = null,
    ) {
    }

    /**
     * Broadcast on the project's private channel.
     *
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('project.' . $this->ticket->project_id)];
    }

    public function broadcastAs(): string
    {
        return 'ticket.status.changed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'ticket_id' => $this->ticket->id,
            'code' => $this->ticket->code,
            'name' => $this->ticket->name,
            'project_id' => $this->ticket->project_id,
            'old_status_id' => $this->oldStatusId,
            'new_status_id' => $this->newStatusId,
            'actor_id' => $this->actorId,
        ];
    }
}
