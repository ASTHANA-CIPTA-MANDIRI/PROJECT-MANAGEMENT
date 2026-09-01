<?php

namespace Tests\Feature;

use App\Models\Epic;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ProjectWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    // ------------------------------------------------------------- members

    public function test_a_member_can_be_added_with_a_role(): void
    {
        $project = Project::factory()->create();
        $user = User::factory()->create();

        $project->users()->attach($user->id, ['role' => 'employee']);

        $this->assertDatabaseHas('project_users', [
            'project_id' => $project->id,
            'user_id' => $user->id,
            'role' => 'employee',
        ]);
    }

    public function test_a_members_role_can_be_changed(): void
    {
        $project = Project::factory()->create();
        $user = User::factory()->create();
        $project->users()->attach($user->id, ['role' => 'employee']);

        $project->users()->updateExistingPivot($user->id, ['role' => 'administrator']);

        $this->assertSame('administrator', $project->fresh()->users->first()->pivot->role);
    }

    public function test_a_member_can_be_removed(): void
    {
        $project = Project::factory()->create();
        $user = User::factory()->create();
        $project->users()->attach($user->id, ['role' => 'employee']);

        $project->users()->detach($user->id);

        $this->assertCount(0, $project->fresh()->users);
    }

    public function test_removing_a_member_keeps_the_owner_as_contributor(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->create(['owner_id' => $owner->id]);
        $member = User::factory()->create();
        $project->users()->attach($member->id, ['role' => 'employee']);

        $project->users()->detach($member->id);

        $this->assertTrue($project->fresh()->contributors->contains('id', $owner->id));
    }

    // ------------------------------------------------------------ favorites

    public function test_a_user_can_favorite_and_unfavorite_a_project(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create();

        $user->favoriteProjects()->attach($project->id);
        $this->assertCount(1, $user->fresh()->favoriteProjects);

        $user->favoriteProjects()->detach($project->id);
        $this->assertCount(0, $user->fresh()->favoriteProjects);
    }

    public function test_favorites_are_per_user(): void
    {
        $project = Project::factory()->create();
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $alice->favoriteProjects()->attach($project->id);

        $this->assertCount(1, $alice->fresh()->favoriteProjects);
        $this->assertCount(0, $bob->fresh()->favoriteProjects);
    }

    // ------------------------------------------------------ status config

    public function test_a_default_project_uses_global_ticket_statuses(): void
    {
        $project = Project::factory()->create();
        TicketStatus::factory()->create(['project_id' => null]);

        $this->assertSame('default', $project->status_type);
        $this->assertCount(0, $project->statuses);
    }

    public function test_a_custom_project_owns_its_ticket_statuses(): void
    {
        $project = Project::factory()->customStatuses()->create();
        TicketStatus::factory()->count(3)->forProject($project)->create();

        $this->assertSame('custom', $project->status_type);
        $this->assertCount(3, $project->fresh()->statuses);
    }

    public function test_statuses_of_one_project_do_not_leak_into_another(): void
    {
        $a = Project::factory()->customStatuses()->create();
        $b = Project::factory()->customStatuses()->create();
        TicketStatus::factory()->count(2)->forProject($a)->create();

        $this->assertCount(2, $a->fresh()->statuses);
        $this->assertCount(0, $b->fresh()->statuses);
    }

    // --------------------------------------------------------- ticket codes

    public function test_each_project_numbers_its_tickets_independently(): void
    {
        $a = Project::factory()->create(['ticket_prefix' => 'AAA']);
        $b = Project::factory()->create(['ticket_prefix' => 'BBB']);

        Ticket::factory()->count(2)->create(['project_id' => $a->id]);
        $ticketB = Ticket::factory()->create(['project_id' => $b->id]);
        $ticketA3 = Ticket::factory()->create(['project_id' => $a->id]);

        $this->assertSame('BBB-1', $ticketB->code);
        $this->assertSame('AAA-3', $ticketA3->code);
    }

    // -------------------------------------------------------- sprint cycle

    public function test_a_project_reports_its_running_sprint(): void
    {
        $project = Project::factory()->scrum()->create();
        Sprint::factory()->create(['project_id' => $project->id]);
        $running = Sprint::factory()->started()->create(['project_id' => $project->id]);

        $this->assertSame($running->id, $project->fresh()->currentSprint->id);
    }

    public function test_closing_a_sprint_clears_the_current_sprint(): void
    {
        $project = Project::factory()->scrum()->create();
        $sprint = Sprint::factory()->started()->create(['project_id' => $project->id]);
        $this->assertNotNull($project->fresh()->currentSprint);

        $sprint->update(['ended_at' => now()]);

        $this->assertNull($project->fresh()->currentSprint);
    }

    public function test_every_sprint_appears_on_the_road_map_as_an_epic(): void
    {
        $project = Project::factory()->scrum()->create();

        Sprint::factory()->count(3)->create(['project_id' => $project->id]);

        // Sprint::boot creates one linked epic per sprint.
        $this->assertCount(3, $project->fresh()->epics);
    }

    // ------------------------------------------------------------ deletion

    public function test_a_deleted_project_is_recoverable(): void
    {
        $project = Project::factory()->create();

        $project->delete();

        $this->assertSoftDeleted($project);
        $this->assertNotNull(Project::withTrashed()->find($project->id));
    }

    public function test_a_ticket_still_resolves_its_project_after_the_project_is_deleted(): void
    {
        $project = Project::factory()->create();
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);

        $project->delete();

        // The relation uses withTrashed(), so history stays readable even
        // though the ticket itself is now soft-deleted too (see below).
        $this->assertNotNull(Ticket::withTrashed()->find($ticket->id)->project);
    }

    public function test_deleting_a_project_soft_deletes_its_tickets_sprints_and_epics(): void
    {
        $project = Project::factory()->scrum()->create();
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);
        $epic = Epic::factory()->create(['project_id' => $project->id]);

        $project->delete();

        $this->assertSoftDeleted($ticket);
        $this->assertSoftDeleted($sprint);
        $this->assertSoftDeleted($epic);

        // Gone from the default (non-trashed) query on the project too, not
        // just orphaned rows pointing at a trashed project.
        $this->assertNull(Ticket::find($ticket->id));
        $this->assertNull(Sprint::find($sprint->id));
        $this->assertNull(Epic::find($epic->id));
    }

    /**
     * ProjectObserver used to pull every ticket into memory with get()->each
     * before deleting them. chunkById streams them in bounded batches instead
     * - this proves the cascade still reaches every row regardless of count,
     * not just the handful a single chunk would cover.
     */
    public function test_deleting_a_project_with_many_tickets_deletes_them_all(): void
    {
        $project = Project::factory()->create();
        $tickets = Ticket::factory()->count(12)->create(['project_id' => $project->id]);

        $project->delete();

        foreach ($tickets as $ticket) {
            $this->assertSoftDeleted($ticket);
        }
        $this->assertSame(0, Ticket::where('project_id', $project->id)->count());
    }

    /**
     * The ticket/sprint/epic cascade runs inside one DB transaction. If
     * anything in it fails partway through, nothing before it may be left
     * soft-deleted either - and the project itself must not end up deleted
     * with its cascade only half-applied.
     */
    public function test_a_failure_partway_through_the_cascade_rolls_back_everything(): void
    {
        $project = Project::factory()->scrum()->create();
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);
        $tickets = Ticket::factory()->count(3)->create(['project_id' => $project->id]);
        $poisoned = $tickets->last();

        Ticket::deleting(function (Ticket $ticket) use ($poisoned) {
            if ($ticket->id === $poisoned->id) {
                throw new \RuntimeException('simulated failure mid-cascade');
            }
        });

        try {
            $project->delete();
            $this->fail('expected the cascade failure to propagate to the caller');
        } catch (\RuntimeException $e) {
            $this->assertSame('simulated failure mid-cascade', $e->getMessage());
        }

        foreach ($tickets as $ticket) {
            $this->assertNull($ticket->fresh()->deleted_at, 'no ticket should be left soft-deleted');
        }
        $this->assertNull($sprint->fresh()->deleted_at, 'the sprint must not be left soft-deleted');
        $this->assertNull($sprint->fresh()->epic->deleted_at, 'the mirrored epic must not be left soft-deleted');
        $this->assertNull($project->fresh()->deleted_at, 'the project itself must not end up deleted');
    }
}
