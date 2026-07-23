<?php

namespace Tests\Feature\Api;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CommentApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    private function actingWith(array $permissions = []): User
    {
        foreach ($permissions as $p) {
            Permission::firstOrCreate(['name' => $p]);
        }
        $role = Role::create(['name' => 'r_'.uniqid()]);
        $role->syncPermissions($permissions);

        $user = User::factory()->create();
        $user->syncRoles([$role]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user = $user->fresh();

        Sanctum::actingAs($user);

        return $user;
    }

    public function test_listing_comments_requires_authentication(): void
    {
        $ticket = Ticket::factory()->create();

        $this->getJson("/api/v1/tickets/{$ticket->id}/comments")->assertUnauthorized();
    }

    public function test_it_lists_comments_of_an_accessible_ticket(): void
    {
        $user = $this->actingWith(['View ticket']);
        $ticket = Ticket::factory()->create(['owner_id' => $user->id]);
        TicketComment::factory()->count(3)->create(['ticket_id' => $ticket->id]);

        $this->getJson("/api/v1/tickets/{$ticket->id}/comments")
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure(['data' => [['id', 'content', 'user_id', 'ticket_id']], 'meta']);
    }

    public function test_it_forbids_listing_comments_of_an_inaccessible_ticket(): void
    {
        $this->actingWith(['View ticket']);
        $ticket = Ticket::factory()->create();

        $this->getJson("/api/v1/tickets/{$ticket->id}/comments")->assertForbidden();
    }

    public function test_it_posts_a_comment(): void
    {
        $user = $this->actingWith(['View ticket']);
        $ticket = Ticket::factory()->create(['owner_id' => $user->id]);

        $response = $this->postJson("/api/v1/tickets/{$ticket->id}/comments", [
            'content' => 'Looks good to me!',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.content', 'Looks good to me!')
            ->assertJsonPath('data.ticket_id', $ticket->id)  // from the route
            ->assertJsonPath('data.user_id', $user->id);     // stamped author

        $this->assertDatabaseHas('ticket_comments', [
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'content' => 'Looks good to me!',
        ]);
    }

    public function test_posting_a_comment_notifies_watchers(): void
    {
        $user = $this->actingWith(['View ticket']);
        $ticket = Ticket::factory()->create(['owner_id' => $user->id]);

        $this->postJson("/api/v1/tickets/{$ticket->id}/comments", ['content' => 'Ping'])
            ->assertCreated();

        Notification::assertSentTo($user, \App\Notifications\TicketCommented::class);
    }

    public function test_it_forbids_commenting_on_an_inaccessible_ticket(): void
    {
        $this->actingWith(['View ticket']);
        $ticket = Ticket::factory()->create();

        $this->postJson("/api/v1/tickets/{$ticket->id}/comments", ['content' => 'X'])
            ->assertForbidden();
    }

    public function test_it_validates_the_comment_payload(): void
    {
        $user = $this->actingWith(['View ticket']);
        $ticket = Ticket::factory()->create(['owner_id' => $user->id]);

        $this->postJson("/api/v1/tickets/{$ticket->id}/comments", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['content']);
    }

    public function test_the_client_cannot_spoof_the_author(): void
    {
        $user = $this->actingWith(['View ticket']);
        $ticket = Ticket::factory()->create(['owner_id' => $user->id]);
        $someoneElse = User::factory()->create();

        // Attempt to post as another user; the author must still be the caller.
        $this->postJson("/api/v1/tickets/{$ticket->id}/comments", [
            'content' => 'Spoof',
            'user_id' => $someoneElse->id,
        ])->assertCreated()->assertJsonPath('data.user_id', $user->id);
    }
}
