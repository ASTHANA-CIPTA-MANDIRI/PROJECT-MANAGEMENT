<?php

namespace Tests\Unit\Models;

use App\Models\Ticket;
use App\Models\TicketSubscriber;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketSubscriberTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_belongs_to_the_subscribing_user(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create();

        $subscriber = TicketSubscriber::create([
            'user_id' => $user->id,
            'ticket_id' => $ticket->id,
        ]);

        $this->assertTrue($subscriber->user->is($user));
    }

    public function test_it_belongs_to_the_subscribed_ticket(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create();

        $subscriber = TicketSubscriber::create([
            'user_id' => $user->id,
            'ticket_id' => $ticket->id,
        ]);

        $this->assertTrue($subscriber->ticket->is($ticket));
    }
}
