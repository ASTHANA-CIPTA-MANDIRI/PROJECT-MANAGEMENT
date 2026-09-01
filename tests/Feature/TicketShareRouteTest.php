<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The /tickets/share/{code} route is public, so a guest guessing codes must
 * not be able to tell a real one from a made-up one by the shape of the
 * response - otherwise the endpoint becomes a ticket-code enumeration oracle.
 */
class TicketShareRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_is_redirected_to_login_for_a_valid_code(): void
    {
        $ticket = Ticket::factory()->create();

        $this->get(route('filament.resources.tickets.share', $ticket->code))
            ->assertRedirect(route('login'));
    }

    public function test_a_guest_gets_the_exact_same_response_for_a_made_up_code(): void
    {
        $ticket = Ticket::factory()->create();

        $forValidCode = $this->get(route('filament.resources.tickets.share', $ticket->code));
        $forInvalidCode = $this->get(route('filament.resources.tickets.share', 'DOES-NOT-EXIST'));

        $forValidCode->assertRedirect(route('login'));
        $forInvalidCode->assertRedirect(route('login'));
        $this->assertSame($forValidCode->getStatusCode(), $forInvalidCode->getStatusCode());
    }

    public function test_a_signed_in_user_is_redirected_to_the_ticket_for_a_valid_code(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create();
        $this->actingAs($user);

        $this->get(route('filament.resources.tickets.share', $ticket->code))
            ->assertRedirect(route('filament.resources.tickets.view', $ticket));
    }

    public function test_a_signed_in_user_gets_a_404_for_a_made_up_code(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->get(route('filament.resources.tickets.share', 'DOES-NOT-EXIST'))
            ->assertNotFound();
    }
}
