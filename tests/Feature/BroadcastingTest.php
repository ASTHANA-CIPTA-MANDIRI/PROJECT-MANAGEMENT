<?php

namespace Tests\Feature;

use App\Events\TicketCommentPosted;
use App\Events\TicketStatusChanged;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\TicketStatus;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class BroadcastingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    // ------------------------------------------------- dispatch from models

    public function test_changing_a_ticket_status_dispatches_a_broadcast(): void
    {
        Event::fake([TicketStatusChanged::class]);
        $this->actingAs(User::factory()->create());

        $ticket = Ticket::factory()->create();
        $newStatus = TicketStatus::factory()->create();

        $ticket->update(['status_id' => $newStatus->id]);

        Event::assertDispatched(TicketStatusChanged::class, function (TicketStatusChanged $e) use ($ticket, $newStatus) {
            return $e->ticket->is($ticket) && $e->newStatusId === $newStatus->id;
        });
    }

    public function test_updating_a_ticket_without_a_status_change_broadcasts_nothing(): void
    {
        Event::fake([TicketStatusChanged::class]);
        $this->actingAs(User::factory()->create());

        $ticket = Ticket::factory()->create();
        $ticket->update(['name' => 'Renamed']);

        Event::assertNotDispatched(TicketStatusChanged::class);
    }

    public function test_posting_a_comment_dispatches_a_broadcast(): void
    {
        Event::fake([TicketCommentPosted::class]);

        $comment = TicketComment::factory()->create();

        Event::assertDispatched(TicketCommentPosted::class, function (TicketCommentPosted $e) use ($comment) {
            return $e->comment->is($comment);
        });
    }

    // ------------------------------------------------------- event contract

    public function test_status_event_broadcasts_on_the_project_channel(): void
    {
        $ticket = Ticket::factory()->create();
        $event = new TicketStatusChanged($ticket, 1, 2, null);

        $channels = $event->broadcastOn();

        $this->assertEquals([new PrivateChannel('project.'.$ticket->project_id)], $channels);
        $this->assertSame('ticket.status.changed', $event->broadcastAs());
        $this->assertSame($ticket->id, $event->broadcastWith()['ticket_id']);
        $this->assertSame(2, $event->broadcastWith()['new_status_id']);
    }

    public function test_comment_event_broadcasts_on_ticket_and_project_channels(): void
    {
        $comment = TicketComment::factory()->create();
        $event = new TicketCommentPosted($comment);

        $this->assertEquals([
            new PrivateChannel('ticket.'.$comment->ticket_id),
            new PrivateChannel('project.'.$comment->ticket->project_id),
        ], $event->broadcastOn());
        $this->assertSame('ticket.comment.posted', $event->broadcastAs());
        $this->assertSame($comment->content, $event->broadcastWith()['content']);
    }

    // -------------------------------------------------- broadcastWhen guard

    public function test_it_does_not_broadcast_without_a_broadcaster(): void
    {
        config(['broadcasting.default' => 'null']);
        $event = new TicketStatusChanged(Ticket::factory()->create(), 1, 2, null);

        $this->assertFalse($event->broadcastWhen());
    }

    public function test_it_does_not_broadcast_with_pusher_but_no_key(): void
    {
        config(['broadcasting.default' => 'pusher', 'broadcasting.connections.pusher.key' => '']);
        $event = new TicketStatusChanged(Ticket::factory()->create(), 1, 2, null);

        $this->assertFalse($event->broadcastWhen());
    }

    public function test_it_broadcasts_when_pusher_is_configured(): void
    {
        config(['broadcasting.default' => 'pusher', 'broadcasting.connections.pusher.key' => 'abc123']);
        $event = new TicketStatusChanged(Ticket::factory()->create(), 1, 2, null);

        $this->assertTrue($event->broadcastWhen());
    }

    // ------------------------------------------------- channel authorization

    public function test_a_project_member_is_authorized_on_the_project_channel(): void
    {
        $member = User::factory()->create();
        $project = Project::factory()->create();
        $project->users()->attach($member->id, ['role' => 'employee']);

        $callback = $this->channelCallback('project.{project}');
        $this->assertTrue((bool) $callback($member, $project));
    }

    public function test_a_stranger_is_rejected_on_the_project_channel(): void
    {
        $stranger = User::factory()->create();
        $project = Project::factory()->create();

        $callback = $this->channelCallback('project.{project}');
        $this->assertFalse((bool) $callback($stranger, $project));
    }

    public function test_ticket_channel_authorizes_the_owner_and_rejects_strangers(): void
    {
        $owner = User::factory()->create();
        $ticket = Ticket::factory()->create(['owner_id' => $owner->id]);
        $stranger = User::factory()->create();

        $callback = $this->channelCallback('ticket.{ticket}');
        $this->assertTrue((bool) $callback($owner, $ticket));
        $this->assertFalse((bool) $callback($stranger, $ticket));
    }

    /**
     * F-02: the project/ticket channel callbacks now delegate to
     * Project::isAccessibleBy() instead of re-implementing the owner/member
     * check inline. These lock in the paths the old inline logic covered but
     * nothing exercised directly: the ticket's responsible user, and a plain
     * project member (neither the ticket's owner/responsible nor the
     * project's owner) reaching the ticket channel through project access.
     */
    public function test_ticket_channel_authorizes_the_responsible_user(): void
    {
        $responsible = User::factory()->create();
        $ticket = Ticket::factory()->create(['responsible_id' => $responsible->id]);

        $callback = $this->channelCallback('ticket.{ticket}');
        $this->assertTrue((bool) $callback($responsible, $ticket));
    }

    public function test_ticket_channel_authorizes_a_project_member_who_is_neither_owner_nor_responsible(): void
    {
        $member = User::factory()->create();
        $ticket = Ticket::factory()->create();
        $ticket->project->users()->attach($member->id, ['role' => 'employee']);

        $callback = $this->channelCallback('ticket.{ticket}');
        $this->assertTrue((bool) $callback($member, $ticket));
    }

    /**
     * Resolve the authorization closure registered for a broadcast channel.
     */
    private function channelCallback(string $channel): \Closure
    {
        $broadcaster = app(\Illuminate\Contracts\Broadcasting\Factory::class)->connection();
        $channels = (new \ReflectionObject($broadcaster))->getProperty('channels');
        $channels->setAccessible(true);

        return $channels->getValue($broadcaster)[$channel];
    }
}
