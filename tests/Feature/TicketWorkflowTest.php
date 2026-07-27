<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\TicketComment;
use App\Models\TicketHour;
use App\Models\TicketStatus;
use App\Models\User;
use App\Notifications\TicketCommented;
use App\Notifications\TicketCreated;
use App\Notifications\TicketStatusUpdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TicketWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    // ------------------------------------------------------ creation notices

    public function test_creating_a_ticket_notifies_the_owner(): void
    {
        $owner = User::factory()->create();

        $ticket = Ticket::factory()->create(['owner_id' => $owner->id]);

        Notification::assertSentTo($owner, TicketCreated::class);
    }

    public function test_creating_a_ticket_notifies_project_members(): void
    {
        $member = User::factory()->create();
        $project = Project::factory()->create();
        $project->users()->attach($member->id, ['role' => 'employee']);

        Ticket::factory()->create(['project_id' => $project->id]);

        Notification::assertSentTo($member, TicketCreated::class);
    }

    public function test_creating_a_ticket_notifies_the_responsible_user(): void
    {
        $responsible = User::factory()->create();

        Ticket::factory()->create(['responsible_id' => $responsible->id]);

        Notification::assertSentTo($responsible, TicketCreated::class);
    }

    public function test_an_unrelated_user_is_not_notified(): void
    {
        $stranger = User::factory()->create();

        Ticket::factory()->create();

        Notification::assertNotSentTo($stranger, TicketCreated::class);
    }

    // -------------------------------------------------------- status changes

    public function test_changing_the_status_records_an_activity(): void
    {
        $actor = User::factory()->create();
        $this->actingAs($actor);

        $ticket = Ticket::factory()->create();
        $oldStatusId = $ticket->status_id;
        $newStatus = TicketStatus::factory()->create();

        $ticket->update(['status_id' => $newStatus->id]);

        $this->assertDatabaseHas('ticket_activities', [
            'ticket_id' => $ticket->id,
            'old_status_id' => $oldStatusId,
            'new_status_id' => $newStatus->id,
            'user_id' => $actor->id,
        ]);
    }

    public function test_changing_the_status_without_an_authenticated_user_records_a_system_activity(): void
    {
        // No actingAs(): mimics a queue job, artisan command or seeder.
        $ticket = Ticket::factory()->create();
        $oldStatusId = $ticket->status_id;
        $newStatus = TicketStatus::factory()->create();

        $ticket->update(['status_id' => $newStatus->id]);

        $this->assertDatabaseHas('ticket_activities', [
            'ticket_id' => $ticket->id,
            'old_status_id' => $oldStatusId,
            'new_status_id' => $newStatus->id,
            'user_id' => null,
        ]);
        $this->assertNull($ticket->fresh()->activities->first()->user);
    }

    public function test_changing_the_status_notifies_the_owner(): void
    {
        $actor = User::factory()->create();
        $this->actingAs($actor);

        $owner = User::factory()->create();
        $ticket = Ticket::factory()->create(['owner_id' => $owner->id]);

        $ticket->update(['status_id' => TicketStatus::factory()->create()->id]);

        Notification::assertSentTo($owner, TicketStatusUpdated::class);
    }

    public function test_updating_without_changing_status_records_no_activity(): void
    {
        $this->actingAs(User::factory()->create());

        $ticket = Ticket::factory()->create();

        $ticket->update(['name' => 'Renamed ticket']);

        $this->assertDatabaseCount('ticket_activities', 0);
    }

    public function test_updating_without_changing_status_sends_no_status_notification(): void
    {
        $this->actingAs(User::factory()->create());

        $owner = User::factory()->create();
        $ticket = Ticket::factory()->create(['owner_id' => $owner->id]);

        $ticket->update(['name' => 'Renamed ticket']);

        Notification::assertNotSentTo($owner, TicketStatusUpdated::class);
    }

    public function test_the_activity_is_linked_to_the_ticket(): void
    {
        $this->actingAs(User::factory()->create());

        $ticket = Ticket::factory()->create();
        $ticket->update(['status_id' => TicketStatus::factory()->create()->id]);

        $this->assertCount(1, $ticket->fresh()->activities);
        $this->assertInstanceOf(TicketActivity::class, $ticket->fresh()->activities->first());
    }

    // -------------------------------------------------------------- comments

    public function test_commenting_notifies_the_ticket_owner(): void
    {
        $owner = User::factory()->create();
        $ticket = Ticket::factory()->create(['owner_id' => $owner->id]);

        TicketComment::factory()->create(['ticket_id' => $ticket->id]);

        Notification::assertSentTo($owner, TicketCommented::class);
    }

    public function test_comments_are_attached_to_the_ticket(): void
    {
        $ticket = Ticket::factory()->create();

        TicketComment::factory()->count(2)->create(['ticket_id' => $ticket->id]);

        $this->assertCount(2, $ticket->fresh()->comments);
    }

    // ----------------------------------------------------------- time logging

    public function test_logging_time_accumulates_on_the_ticket(): void
    {
        $ticket = Ticket::factory()->create();

        TicketHour::factory()->hours(3)->create(['ticket_id' => $ticket->id]);
        TicketHour::factory()->hours(2)->create(['ticket_id' => $ticket->id]);

        $this->assertEqualsWithDelta(5.0, $ticket->fresh()->totalLoggedInHours, 0.001);
    }

    public function test_logged_time_is_attributed_to_the_user(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create();

        TicketHour::factory()->hours(4)->create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
        ]);

        $this->assertEqualsWithDelta(4.0, $user->fresh()->totalLoggedInHours, 0.001);
    }

    public function test_progress_tracks_logged_time_against_the_estimation(): void
    {
        $ticket = Ticket::factory()->estimated(10)->create();

        TicketHour::factory()->hours(2.5)->create(['ticket_id' => $ticket->id]);

        $this->assertEqualsWithDelta(25.0, $ticket->fresh()->estimationProgress, 0.001);
    }

    // ------------------------------------------------- moving between sprints

    public function test_removing_a_ticket_from_a_sprint_clears_its_epic(): void
    {
        $this->actingAs(User::factory()->create());

        $project = Project::factory()->create();
        $sprint = \App\Models\Sprint::factory()->create(['project_id' => $project->id]);
        $ticket = Ticket::factory()->create([
            'project_id' => $project->id,
            'sprint_id' => $sprint->id,
        ]);
        $this->assertNotNull($ticket->fresh()->epic_id, 'sanity: epic inherited');

        $ticket->update(['sprint_id' => null]);

        $this->assertNull($ticket->fresh()->epic_id);
    }

    public function test_moving_a_ticket_to_a_sprint_applies_that_sprints_epic(): void
    {
        $this->actingAs(User::factory()->create());

        $project = Project::factory()->create();
        $sprint = \App\Models\Sprint::factory()->create(['project_id' => $project->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);

        $ticket->update(['sprint_id' => $sprint->id]);

        $this->assertSame($sprint->fresh()->epic_id, $ticket->fresh()->epic_id);
    }

    // -------------------------------------------------------------- deletion

    public function test_a_deleted_ticket_is_hidden_but_recoverable(): void
    {
        $ticket = Ticket::factory()->create();

        $ticket->delete();

        $this->assertSoftDeleted($ticket);
        $this->assertNotNull(Ticket::withTrashed()->find($ticket->id));
    }
}
