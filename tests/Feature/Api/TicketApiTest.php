<?php

namespace Tests\Feature\Api;

use App\Models\Epic;
use App\Models\Permission;
use App\Models\Project;
use App\Models\Role;
use App\Models\Sprint;
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
        $role = Role::create(['name' => 'r_'.uniqid()]);
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

    // --------------------------------------------- store: cross-project ids

    /**
     * The relation ids in the payload used to be validated with a plain
     * "exists in the table" rule, so a caller with access to one project could
     * hang their ticket off another project's sprint, epic or status — putting
     * it in that project's burndown and velocity. They must now belong to the
     * project the ticket is created in.
     */
    private function projectWithLookups(User $user): array
    {
        $project = Project::factory()->create(['owner_id' => $user->id]);

        return [$project, [
            'name' => 'X',
            'content' => 'Y',
            'status_id' => TicketStatus::factory()->create()->id,
            'type_id' => TicketType::factory()->create()->id,
            'priority_id' => TicketPriority::factory()->create()->id,
        ]];
    }

    public function test_it_rejects_a_sprint_from_another_project(): void
    {
        $user = $this->actingWith(['Create ticket']);
        [$project, $payload] = $this->projectWithLookups($user);
        $foreignSprint = Sprint::factory()->create();

        $this->postJson("/api/v1/projects/{$project->id}/tickets",
            $payload + ['sprint_id' => $foreignSprint->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sprint_id']);

        $this->assertSame(0, Ticket::count());
    }

    public function test_it_accepts_a_sprint_of_the_same_project(): void
    {
        $user = $this->actingWith(['Create ticket']);
        [$project, $payload] = $this->projectWithLookups($user);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);

        $this->postJson("/api/v1/projects/{$project->id}/tickets",
            $payload + ['sprint_id' => $sprint->id])
            ->assertCreated()
            ->assertJsonPath('data.sprint_id', $sprint->id);
    }

    public function test_it_rejects_an_epic_from_another_project(): void
    {
        $user = $this->actingWith(['Create ticket']);
        [$project, $payload] = $this->projectWithLookups($user);
        $foreignEpic = Epic::factory()->create();

        $this->postJson("/api/v1/projects/{$project->id}/tickets",
            $payload + ['epic_id' => $foreignEpic->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['epic_id']);
    }

    public function test_it_rejects_a_status_belonging_to_another_project(): void
    {
        $user = $this->actingWith(['Create ticket']);
        [$project, $payload] = $this->projectWithLookups($user);
        $foreignProject = Project::factory()->customStatuses()->create();
        $foreignStatus = TicketStatus::factory()->forProject($foreignProject)->create();

        $this->postJson("/api/v1/projects/{$project->id}/tickets",
            ['status_id' => $foreignStatus->id] + $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status_id']);
    }

    public function test_a_custom_status_project_rejects_the_global_statuses(): void
    {
        $user = $this->actingWith(['Create ticket']);
        $project = Project::factory()->customStatuses()->create(['owner_id' => $user->id]);
        $own = TicketStatus::factory()->forProject($project)->create();
        $global = TicketStatus::factory()->create();
        $payload = [
            'name' => 'X', 'content' => 'Y',
            'type_id' => TicketType::factory()->create()->id,
            'priority_id' => TicketPriority::factory()->create()->id,
        ];

        $this->postJson("/api/v1/projects/{$project->id}/tickets",
            $payload + ['status_id' => $global->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status_id']);

        $this->postJson("/api/v1/projects/{$project->id}/tickets",
            $payload + ['status_id' => $own->id])
            ->assertCreated();
    }

    public function test_it_rejects_a_responsible_who_is_not_on_the_project(): void
    {
        $user = $this->actingWith(['Create ticket']);
        [$project, $payload] = $this->projectWithLookups($user);
        $outsider = User::factory()->create();

        $this->postJson("/api/v1/projects/{$project->id}/tickets",
            $payload + ['responsible_id' => $outsider->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['responsible_id']);
    }

    public function test_it_accepts_a_responsible_who_is_a_project_member(): void
    {
        $user = $this->actingWith(['Create ticket']);
        [$project, $payload] = $this->projectWithLookups($user);
        $member = User::factory()->create();
        $project->users()->attach($member->id, ['role' => 'member']);

        $this->postJson("/api/v1/projects/{$project->id}/tickets",
            $payload + ['responsible_id' => $member->id])
            ->assertCreated()
            ->assertJsonPath('data.responsible_id', $member->id);
    }

    public function test_it_rejects_an_owner_who_is_not_on_the_project(): void
    {
        $user = $this->actingWith(['Create ticket']);
        [$project, $payload] = $this->projectWithLookups($user);
        $outsider = User::factory()->create();

        $this->postJson("/api/v1/projects/{$project->id}/tickets",
            $payload + ['owner_id' => $outsider->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['owner_id']);
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

    // ------------------------------------------------------------- update

    /**
     * A ticket in a project the given user owns, plus a complete body for a
     * PUT that replaces it.
     *
     * @return array{0: Ticket, 1: array<string, mixed>}
     */
    private function ticketWithPayload(User $user): array
    {
        $project = Project::factory()->create(['owner_id' => $user->id]);
        $ticket = Ticket::factory()->create([
            'project_id' => $project->id,
            'owner_id' => $user->id,
            'name' => 'Old name',
        ]);

        return [$ticket, [
            'name' => $ticket->name,
            'content' => $ticket->content,
            'status_id' => $ticket->status_id,
            'type_id' => $ticket->type_id,
            'priority_id' => $ticket->priority_id,
        ]];
    }

    public function test_updating_requires_authentication(): void
    {
        $ticket = Ticket::factory()->create();

        $this->putJson("/api/v1/tickets/{$ticket->id}", ['name' => 'X'])->assertUnauthorized();
    }

    public function test_it_updates_a_ticket(): void
    {
        $user = $this->actingWith(['Update ticket']);
        [$ticket, $payload] = $this->ticketWithPayload($user);

        $this->putJson("/api/v1/tickets/{$ticket->id}", ['name' => 'New name'] + $payload)
            ->assertOk()
            ->assertJsonPath('data.id', $ticket->id)
            ->assertJsonPath('data.name', 'New name');

        $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'name' => 'New name']);
    }

    public function test_updating_requires_the_update_permission(): void
    {
        $user = $this->actingWith(['View ticket']); // wrong permission
        [$ticket, $payload] = $this->ticketWithPayload($user);

        $this->putJson("/api/v1/tickets/{$ticket->id}", ['name' => 'Nope'] + $payload)
            ->assertForbidden();

        $this->assertDatabaseMissing('tickets', ['id' => $ticket->id, 'name' => 'Nope']);
    }

    public function test_it_forbids_updating_a_ticket_of_an_inaccessible_project(): void
    {
        $this->actingWith(['Update ticket']);
        $ticket = Ticket::factory()->create(); // someone else's project

        $this->putJson("/api/v1/tickets/{$ticket->id}", ['name' => 'Nope'])->assertForbidden();
    }

    public function test_a_patch_changes_only_the_fields_it_carries(): void
    {
        $user = $this->actingWith(['Update ticket']);
        [$ticket] = $this->ticketWithPayload($user);
        $newStatus = TicketStatus::factory()->create();

        $this->patchJson("/api/v1/tickets/{$ticket->id}", ['status_id' => $newStatus->id])
            ->assertOk()
            ->assertJsonPath('data.status_id', $newStatus->id)
            ->assertJsonPath('data.name', 'Old name'); // untouched

        $this->assertDatabaseHas('tickets', [
            'id' => $ticket->id,
            'status_id' => $newStatus->id,
            'name' => 'Old name',
        ]);

        // Moving a ticket between statuses is journalled, whichever way it was
        // moved — the board, the panel or the API.
        $this->assertDatabaseHas('ticket_activities', [
            'ticket_id' => $ticket->id,
            'old_status_id' => $ticket->status_id,
            'new_status_id' => $newStatus->id,
        ]);
    }

    public function test_updating_cannot_move_a_ticket_to_another_project(): void
    {
        $user = $this->actingWith(['Update ticket']);
        [$ticket, $payload] = $this->ticketWithPayload($user);
        $other = Project::factory()->create(['owner_id' => $user->id]);

        $this->patchJson("/api/v1/tickets/{$ticket->id}", ['project_id' => $other->id] + $payload)
            ->assertOk()
            ->assertJsonPath('data.project_id', $ticket->project_id);

        $this->assertDatabaseHas('tickets', [
            'id' => $ticket->id,
            'project_id' => $ticket->project_id,
        ]);
    }

    public function test_updating_keeps_the_owner_when_it_is_omitted(): void
    {
        $user = $this->actingWith(['Update ticket']);
        $project = Project::factory()->create(['owner_id' => $user->id]);
        $member = User::factory()->create();
        $project->users()->attach($member->id, ['role' => 'employee']);
        $ticket = Ticket::factory()->create([
            'project_id' => $project->id,
            'owner_id' => $member->id,
        ]);

        // A body without owner_id must not quietly reassign the ticket to
        // whoever happens to be editing it.
        $this->putJson("/api/v1/tickets/{$ticket->id}", [
            'name' => 'Edited',
            'content' => $ticket->content,
            'status_id' => $ticket->status_id,
            'type_id' => $ticket->type_id,
            'priority_id' => $ticket->priority_id,
        ])->assertOk()->assertJsonPath('data.owner_id', $member->id);

        $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'owner_id' => $member->id]);
    }

    public function test_updating_rejects_a_sprint_from_another_project(): void
    {
        $user = $this->actingWith(['Update ticket']);
        [$ticket, $payload] = $this->ticketWithPayload($user);
        $foreignSprint = Sprint::factory()->create();

        $this->putJson("/api/v1/tickets/{$ticket->id}", ['sprint_id' => $foreignSprint->id] + $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sprint_id']);
    }

    public function test_updating_accepts_a_sprint_of_the_same_project_and_syncs_the_epic(): void
    {
        $user = $this->actingWith(['Update ticket']);
        [$ticket, $payload] = $this->ticketWithPayload($user);
        $sprint = Sprint::factory()->create(['project_id' => $ticket->project_id]);

        $this->putJson("/api/v1/tickets/{$ticket->id}", ['sprint_id' => $sprint->id] + $payload)
            ->assertOk()
            ->assertJsonPath('data.sprint_id', $sprint->id)
            ->assertJsonPath('data.epic_id', $sprint->fresh()->epic_id);
    }

    public function test_updating_rejects_a_responsible_who_is_not_on_the_project(): void
    {
        $user = $this->actingWith(['Update ticket']);
        [$ticket, $payload] = $this->ticketWithPayload($user);
        $outsider = User::factory()->create();

        $this->putJson("/api/v1/tickets/{$ticket->id}", ['responsible_id' => $outsider->id] + $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['responsible_id']);
    }

    // ------------------------------------------------------------ destroy

    public function test_it_deletes_a_ticket(): void
    {
        $user = $this->actingWith(['Delete ticket']);
        $ticket = Ticket::factory()->create(['owner_id' => $user->id]);

        $this->deleteJson("/api/v1/tickets/{$ticket->id}")->assertNoContent();

        $this->assertSoftDeleted('tickets', ['id' => $ticket->id]);
    }

    public function test_deleting_requires_the_delete_permission(): void
    {
        $user = $this->actingWith(['Update ticket']); // wrong permission
        $ticket = Ticket::factory()->create(['owner_id' => $user->id]);

        $this->deleteJson("/api/v1/tickets/{$ticket->id}")->assertForbidden();

        $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'deleted_at' => null]);
    }

    public function test_it_forbids_deleting_an_inaccessible_ticket(): void
    {
        $this->actingWith(['Delete ticket']);
        $ticket = Ticket::factory()->create();

        $this->deleteJson("/api/v1/tickets/{$ticket->id}")->assertForbidden();
    }

    public function test_a_deleted_ticket_is_gone_from_the_api(): void
    {
        $user = $this->actingWith(['Delete ticket', 'View ticket']);
        $ticket = Ticket::factory()->create(['owner_id' => $user->id]);

        $this->deleteJson("/api/v1/tickets/{$ticket->id}")->assertNoContent();

        $this->getJson("/api/v1/tickets/{$ticket->id}")->assertNotFound();
    }
}
