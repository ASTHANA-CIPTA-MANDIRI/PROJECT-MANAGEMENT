<?php

namespace Tests\Feature\Api;

use App\Models\Permission;
use App\Models\Project;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\TicketPriority;
use App\Models\TicketStatus;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TicketApiTest extends TestCase
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
        $role = Role::create(['name' => 'r_' . uniqid()]);
        $role->syncPermissions($permissions);

        $user = User::factory()->create();
        $user->syncRoles([$role]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user = $user->fresh();

        Sanctum::actingAs($user);

        return $user;
    }

    // ------------------------------------------------------------- index

    public function test_listing_tickets_requires_authentication(): void
    {
        $project = Project::factory()->create();

        $this->getJson("/api/v1/projects/{$project->id}/tickets")->assertUnauthorized();
    }

    public function test_it_lists_tickets_of_a_project_the_user_owns(): void
    {
        $user = $this->actingWith(['View ticket']);
        $project = Project::factory()->create(['owner_id' => $user->id]);
        Ticket::factory()->count(3)->create(['project_id' => $project->id]);

        $this->getJson("/api/v1/projects/{$project->id}/tickets")
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure(['data' => [['id', 'code', 'name', 'project_id']], 'meta']);
    }

    public function test_it_forbids_listing_tickets_of_an_inaccessible_project(): void
    {
        $this->actingWith(['View ticket']);
        $project = Project::factory()->create();

        $this->getJson("/api/v1/projects/{$project->id}/tickets")->assertForbidden();
    }

    public function test_it_filters_tickets_by_status(): void
    {
        $user = $this->actingWith(['View ticket']);
        $project = Project::factory()->create(['owner_id' => $user->id]);
        $statusA = TicketStatus::factory()->create();
        $statusB = TicketStatus::factory()->create();
        Ticket::factory()->create(['project_id' => $project->id, 'status_id' => $statusA->id]);
        Ticket::factory()->create(['project_id' => $project->id, 'status_id' => $statusB->id]);

        $this->getJson("/api/v1/projects/{$project->id}/tickets?filter[status_id]={$statusA->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status_id', $statusA->id);
    }

    public function test_it_only_returns_tickets_of_the_given_project(): void
    {
        $user = $this->actingWith(['View ticket']);
        $mine = Project::factory()->create(['owner_id' => $user->id]);
        $other = Project::factory()->create(['owner_id' => $user->id]);
        Ticket::factory()->create(['project_id' => $mine->id]);
        Ticket::factory()->count(2)->create(['project_id' => $other->id]);

        $this->getJson("/api/v1/projects/{$mine->id}/tickets")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    // ------------------------------------------------------------- store

    public function test_it_creates_a_ticket_in_the_project(): void
    {
        $user = $this->actingWith(['Create ticket']);
        $project = Project::factory()->create(['owner_id' => $user->id]);
        $status = TicketStatus::factory()->create();
        $type = TicketType::factory()->create();
        $priority = TicketPriority::factory()->create();

        $payload = [
            'name' => 'Fix the bug',
            'content' => 'Steps to reproduce...',
            'status_id' => $status->id,
            'type_id' => $type->id,
            'priority_id' => $priority->id,
        ];

        $response = $this->postJson("/api/v1/projects/{$project->id}/tickets", $payload);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Fix the bug')
            ->assertJsonPath('data.project_id', $project->id)   // from the route
            ->assertJsonPath('data.owner_id', $user->id);       // defaulted to caller

        $this->assertDatabaseHas('tickets', [
            'name' => 'Fix the bug',
            'project_id' => $project->id,
        ]);
    }

    public function test_creating_a_ticket_generates_a_code(): void
    {
        $user = $this->actingWith(['Create ticket']);
        $project = Project::factory()->create(['owner_id' => $user->id, 'ticket_prefix' => 'ABC']);
        $status = TicketStatus::factory()->create();
        $type = TicketType::factory()->create();
        $priority = TicketPriority::factory()->create();

        $this->postJson("/api/v1/projects/{$project->id}/tickets", [
            'name' => 'First', 'content' => '...', 'status_id' => $status->id,
            'type_id' => $type->id, 'priority_id' => $priority->id,
        ])->assertCreated()->assertJsonPath('data.code', 'ABC-1');
    }

    public function test_creating_requires_the_create_permission(): void
    {
        $user = $this->actingWith(['View ticket']);
        $project = Project::factory()->create(['owner_id' => $user->id]);

        $this->postJson("/api/v1/projects/{$project->id}/tickets", ['name' => 'X', 'content' => 'Y'])
            ->assertForbidden();
    }

    public function test_it_validates_the_ticket_payload(): void
    {
        $user = $this->actingWith(['Create ticket']);
        $project = Project::factory()->create(['owner_id' => $user->id]);

        $this->postJson("/api/v1/projects/{$project->id}/tickets", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'content', 'status_id', 'type_id', 'priority_id']);
    }

    public function test_it_rejects_a_negative_estimation(): void
    {
        $user = $this->actingWith(['Create ticket']);
        $project = Project::factory()->create(['owner_id' => $user->id]);
        $status = TicketStatus::factory()->create();
        $type = TicketType::factory()->create();
        $priority = TicketPriority::factory()->create();

        $this->postJson("/api/v1/projects/{$project->id}/tickets", [
            'name' => 'X', 'content' => 'Y', 'status_id' => $status->id,
            'type_id' => $type->id, 'priority_id' => $priority->id, 'estimation' => -3,
        ])->assertStatus(422)->assertJsonValidationErrors(['estimation']);
    }

    // -------------------------------------------------------------- show

    public function test_it_shows_a_ticket_the_user_can_access(): void
    {
        $user = $this->actingWith(['View ticket']);
        $ticket = Ticket::factory()->create(['owner_id' => $user->id]);

        $this->getJson("/api/v1/tickets/{$ticket->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $ticket->id);
    }

    public function test_it_forbids_showing_an_inaccessible_ticket(): void
    {
        $this->actingWith(['View ticket']);
        $ticket = Ticket::factory()->create();

        $this->getJson("/api/v1/tickets/{$ticket->id}")->assertForbidden();
    }
}
