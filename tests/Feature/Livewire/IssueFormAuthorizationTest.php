<?php

namespace Tests\Feature\Livewire;

use App\Http\Livewire\RoadMap\IssueForm;
use App\Models\Epic;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\TicketPriority;
use App\Models\TicketStatus;
use App\Models\TicketType;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\InteractsWithPermissions;
use Tests\TestCase;

/**
 * IssueForm::submit() used to call Ticket::create() straight off Livewire
 * state: no can('Create ticket') check, and project_id/status_id/epic_id
 * were trusted as posted even though the select options are only scoped to
 * the user's projects client-side. All four are now re-resolved against the
 * target project, mirroring the fix EpicForm already has for project_id.
 */
class IssueFormAuthorizationTest extends TestCase
{
    use InteractsWithPermissions, RefreshDatabase;

    public function test_submitting_without_the_create_permission_is_denied(): void
    {
        $user = $this->userWithoutPermissions();
        $project = Project::factory()->create(['owner_id' => $user->id]);
        $this->actingAs($user);

        Livewire::test(IssueForm::class, ['project' => $project])
            ->set('name', 'Ticket')
            ->set('content', 'Body')
            ->call('submit')
            ->assertForbidden();

        $this->assertSame(0, Ticket::count());
    }

    public function test_a_project_the_user_cannot_access_is_rejected(): void
    {
        $user = $this->userWithPermissions(['Create ticket']);
        $foreign = Project::factory()->create();
        $this->actingAs($user);

        $this->expectException(ModelNotFoundException::class);

        // No 'project' mount prop, so project_id is fully attacker-controlled
        // Livewire state, same as tampering with a disabled/hidden field.
        Livewire::test(IssueForm::class)
            ->set('project_id', $foreign->id)
            ->set('name', 'Ticket')
            ->set('content', 'Body')
            ->set('owner_id', $user->id)
            ->set('status_id', 1)
            ->set('type_id', 1)
            ->set('priority_id', 1)
            ->set('epic_id', 1)
            ->call('submit');

        $this->assertSame(0, Ticket::count());
    }

    public function test_a_status_from_a_different_project_is_rejected(): void
    {
        $user = $this->userWithPermissions(['Create ticket']);
        $project = Project::factory()->customStatuses()->create(['owner_id' => $user->id]);
        $epic = Epic::factory()->create(['project_id' => $project->id]);
        $foreignProject = Project::factory()->customStatuses()->create();
        $foreignStatus = TicketStatus::factory()->create(['project_id' => $foreignProject->id]);
        TicketType::factory()->default()->create();
        TicketPriority::factory()->default()->create();
        $this->actingAs($user);

        Livewire::test(IssueForm::class, ['project' => $project])
            ->set('name', 'Ticket')
            ->set('content', 'Body')
            ->set('owner_id', $user->id)
            ->set('epic_id', $epic->id)
            ->set('status_id', $foreignStatus->id)
            ->call('submit')
            ->assertHasErrors(['status_id']);

        $this->assertSame(0, Ticket::count());
    }

    public function test_an_epic_from_a_different_project_is_dropped_not_attached(): void
    {
        $user = $this->userWithPermissions(['Create ticket']);
        $project = Project::factory()->create(['owner_id' => $user->id]);
        $foreignEpic = Epic::factory()->create();
        TicketStatus::factory()->default()->create(['project_id' => null]);
        TicketType::factory()->default()->create();
        TicketPriority::factory()->default()->create();
        $this->actingAs($user);

        Livewire::test(IssueForm::class, ['project' => $project])
            ->set('name', 'Ticket')
            ->set('content', 'Body')
            ->set('owner_id', $user->id)
            ->set('epic_id', $foreignEpic->id)
            ->call('submit')
            ->assertHasNoErrors();

        $ticket = Ticket::sole();
        $this->assertNull($ticket->epic_id);
    }

    public function test_a_valid_submission_creates_the_ticket(): void
    {
        $user = $this->userWithPermissions(['Create ticket']);
        $project = Project::factory()->create(['owner_id' => $user->id]);
        $epic = Epic::factory()->create(['project_id' => $project->id]);
        TicketStatus::factory()->default()->create(['project_id' => null]);
        TicketType::factory()->default()->create();
        TicketPriority::factory()->default()->create();
        $this->actingAs($user);

        Livewire::test(IssueForm::class, ['project' => $project])
            ->set('name', 'New ticket')
            ->set('content', 'Body')
            ->set('owner_id', $user->id)
            ->set('epic_id', $epic->id)
            ->call('submit')
            ->assertHasNoErrors();

        $ticket = Ticket::sole();
        $this->assertSame($project->id, $ticket->project_id);
        $this->assertSame($epic->id, $ticket->epic_id);
    }
}
