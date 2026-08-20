<?php

namespace Tests\Unit\Models;

use App\Models\Epic;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\TicketHour;
use App\Models\TicketPriority;
use App\Models\TicketStatus;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------- code / order generation

    public function test_it_generates_a_code_from_the_project_prefix(): void
    {
        $project = Project::factory()->create(['ticket_prefix' => 'ABC']);

        $ticket = Ticket::factory()->create(['project_id' => $project->id]);

        $this->assertSame('ABC-1', $ticket->code);
    }

    public function test_it_increments_the_code_for_each_new_ticket(): void
    {
        $project = Project::factory()->create(['ticket_prefix' => 'XYZ']);

        $first = Ticket::factory()->create(['project_id' => $project->id]);
        $second = Ticket::factory()->create(['project_id' => $project->id]);

        $this->assertSame('XYZ-1', $first->code);
        $this->assertSame('XYZ-2', $second->code);
    }

    public function test_it_scopes_codes_per_project(): void
    {
        $a = Project::factory()->create(['ticket_prefix' => 'AAA']);
        $b = Project::factory()->create(['ticket_prefix' => 'BBB']);

        $ticketA = Ticket::factory()->create(['project_id' => $a->id]);
        $ticketB = Ticket::factory()->create(['project_id' => $b->id]);

        $this->assertSame('AAA-1', $ticketA->code);
        $this->assertSame('BBB-1', $ticketB->code);
    }

    public function test_it_does_not_reuse_the_code_of_a_soft_deleted_ticket(): void
    {
        $project = Project::factory()->create(['ticket_prefix' => 'DEL']);

        Ticket::factory()->create(['project_id' => $project->id]);
        $second = Ticket::factory()->create(['project_id' => $project->id]);
        $second->delete();

        $third = Ticket::factory()->create(['project_id' => $project->id]);

        $this->assertSame('DEL-3', $third->code);
    }

    public function test_it_does_not_reuse_the_code_of_a_force_deleted_ticket(): void
    {
        $project = Project::factory()->create(['ticket_prefix' => 'PRG']);

        $first = Ticket::factory()->create(['project_id' => $project->id]);
        $first->forceDelete();

        $second = Ticket::factory()->create(['project_id' => $project->id]);

        $this->assertSame('PRG-2', $second->code);
    }

    public function test_the_first_ticket_of_a_project_gets_order_zero(): void
    {
        $project = Project::factory()->create();

        $ticket = Ticket::factory()->create(['project_id' => $project->id]);

        $this->assertSame(0, $ticket->order);
    }

    public function test_it_increments_order_for_subsequent_tickets(): void
    {
        $project = Project::factory()->create();

        Ticket::factory()->create(['project_id' => $project->id]);
        $second = Ticket::factory()->create(['project_id' => $project->id]);

        $this->assertSame(1, $second->order);
    }

    /**
     * The board writes arbitrary order values when cards are dragged around,
     * so the last inserted ticket is not necessarily the last one on the
     * board. The next order has to continue from the highest value.
     */
    public function test_order_continues_from_the_highest_value_not_the_last_inserted(): void
    {
        $project = Project::factory()->create();
        $first = Ticket::factory()->create(['project_id' => $project->id]);
        Ticket::factory()->create(['project_id' => $project->id]);

        // Mirrors a drag-and-drop reorder: the oldest ticket moves to the end.
        $first->update(['order' => 42]);

        $next = Ticket::factory()->create(['project_id' => $project->id]);

        $this->assertSame(43, $next->order);
    }

    public function test_a_ticket_without_a_project_fails_with_a_clear_error(): void
    {
        // Not "Attempt to read property on null", and not a code-less row
        // hitting the NOT NULL constraint either.
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        app(\App\Observers\TicketObserver::class)->creating(new Ticket);
    }

    // ------------------------------------------------------- epic from sprint

    public function test_it_inherits_the_epic_of_its_sprint_on_creation(): void
    {
        $project = Project::factory()->create();
        // A sprint always auto-creates its own epic (see Sprint::boot).
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);
        $sprint->refresh();

        $ticket = Ticket::factory()->create([
            'project_id' => $project->id,
            'sprint_id' => $sprint->id,
        ]);

        $this->assertNotNull($sprint->epic_id);
        $this->assertSame($sprint->epic_id, $ticket->fresh()->epic_id);
    }

    public function test_it_leaves_epic_null_when_sprint_has_no_epic(): void
    {
        $project = Project::factory()->create();
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);
        // Detach the auto-created epic to reach the "sprint without epic" branch.
        $sprint->forceFill(['epic_id' => null])->save();

        $ticket = Ticket::factory()->create([
            'project_id' => $project->id,
            'sprint_id' => $sprint->id,
        ]);

        $this->assertNull($ticket->fresh()->epic_id);
    }

    // ----------------------------------------------------------- relationships

    public function test_it_belongs_to_an_owner(): void
    {
        $owner = User::factory()->create();
        $ticket = Ticket::factory()->create(['owner_id' => $owner->id]);

        $this->assertTrue($ticket->owner->is($owner));
    }

    public function test_it_belongs_to_a_responsible_user(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create(['responsible_id' => $user->id]);

        $this->assertTrue($ticket->responsible->is($user));
    }

    public function test_responsible_is_optional(): void
    {
        $ticket = Ticket::factory()->create(['responsible_id' => null]);

        $this->assertNull($ticket->responsible);
    }

    public function test_it_belongs_to_a_project(): void
    {
        $project = Project::factory()->create();
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);

        $this->assertTrue($ticket->project->is($project));
    }

    public function test_it_belongs_to_a_status_type_and_priority(): void
    {
        $status = TicketStatus::factory()->create();
        $type = TicketType::factory()->create();
        $priority = TicketPriority::factory()->create();

        $ticket = Ticket::factory()->create([
            'status_id' => $status->id,
            'type_id' => $type->id,
            'priority_id' => $priority->id,
        ]);

        $this->assertTrue($ticket->status->is($status));
        $this->assertTrue($ticket->type->is($type));
        $this->assertTrue($ticket->priority->is($priority));
    }

    public function test_it_belongs_to_a_sprint(): void
    {
        $project = Project::factory()->create();
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);
        $ticket = Ticket::factory()->create([
            'project_id' => $project->id,
            'sprint_id' => $sprint->id,
        ]);

        $this->assertTrue($ticket->sprint->is($sprint));
    }

    /**
     * `sprints()` used to be a byte-for-byte copy of `sprint()`. Two names for
     * one belongsTo means two separate relation caches for the same row, so a
     * reader could get a stale sprint depending on which name it happened to
     * use. Only the singular form exists now.
     */
    public function test_the_sprint_relation_is_not_duplicated_under_a_plural_name(): void
    {
        $this->assertFalse(
            method_exists(Ticket::class, 'sprints'),
            'Ticket must expose exactly one sprint relation'
        );
    }

    public function test_it_has_many_comments(): void
    {
        $ticket = Ticket::factory()->create();
        TicketComment::factory()->count(3)->create(['ticket_id' => $ticket->id]);

        $this->assertCount(3, $ticket->comments);
    }

    public function test_it_has_many_hours(): void
    {
        $ticket = Ticket::factory()->create();
        TicketHour::factory()->count(2)->create(['ticket_id' => $ticket->id]);

        $this->assertCount(2, $ticket->hours);
    }

    public function test_it_can_have_subscribers(): void
    {
        $ticket = Ticket::factory()->create();
        $user = User::factory()->create();

        $ticket->subscribers()->attach($user->id);

        $this->assertTrue($ticket->subscribers->contains($user));
    }

    // -------------------------------------------------------------- watchers

    public function test_watchers_include_the_owner(): void
    {
        $owner = User::factory()->create();
        $ticket = Ticket::factory()->create(['owner_id' => $owner->id]);

        $this->assertTrue($ticket->watchers->contains('id', $owner->id));
    }

    public function test_watchers_include_the_responsible_user(): void
    {
        $responsible = User::factory()->create();
        $ticket = Ticket::factory()->create(['responsible_id' => $responsible->id]);

        $this->assertTrue($ticket->watchers->contains('id', $responsible->id));
    }

    public function test_watchers_include_project_members(): void
    {
        $member = User::factory()->create();
        $project = Project::factory()->create();
        $project->users()->attach($member->id, ['role' => 'member']);

        $ticket = Ticket::factory()->create(['project_id' => $project->id]);

        $this->assertTrue($ticket->watchers->contains('id', $member->id));
    }

    public function test_watchers_are_unique(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create();
        $project->users()->attach($user->id, ['role' => 'member']);

        // Same user is member, owner and responsible at once.
        $ticket = Ticket::factory()->create([
            'project_id' => $project->id,
            'owner_id' => $user->id,
            'responsible_id' => $user->id,
        ]);

        $this->assertSame(1, $ticket->watchers->where('id', $user->id)->count());
    }

    /**
     * ->project->users is Eloquent's cached relation collection, not a copy:
     * watchers() used to push the owner/responsible straight onto it, so
     * every later read of $project->users in the same request saw them as
     * project members. TicketCommentObserver::created() reads watchers twice
     * in a row, so this was not a rare edge case.
     */
    public function test_reading_watchers_does_not_leak_into_the_projects_member_list(): void
    {
        $owner = User::factory()->create();
        $responsible = User::factory()->create();
        $project = Project::factory()->create(['owner_id' => $owner->id]);
        $ticket = Ticket::factory()->create([
            'project_id' => $project->id,
            'owner_id' => $owner->id,
            'responsible_id' => $responsible->id,
        ]);

        $ticket->watchers;
        $ticket->watchers;

        $this->assertFalse(
            $ticket->project->users->contains('id', $responsible->id),
            'the ticket responsible must not appear as a project member'
        );
    }

    // ---------------------------------------------------------- logged hours

    public function test_total_logged_in_hours_sums_the_hour_entries(): void
    {
        $ticket = Ticket::factory()->create();
        TicketHour::factory()->hours(2.5)->create(['ticket_id' => $ticket->id]);
        TicketHour::factory()->hours(1.5)->create(['ticket_id' => $ticket->id]);

        $this->assertEqualsWithDelta(4.0, $ticket->totalLoggedInHours, 0.001);
    }

    public function test_total_logged_seconds_converts_hours_to_seconds(): void
    {
        $ticket = Ticket::factory()->create();
        TicketHour::factory()->hours(2)->create(['ticket_id' => $ticket->id]);

        $this->assertEqualsWithDelta(7200, $ticket->totalLoggedSeconds, 0.001);
    }

    public function test_total_logged_is_zero_without_hour_entries(): void
    {
        $ticket = Ticket::factory()->create();

        $this->assertEqualsWithDelta(0, $ticket->totalLoggedInHours, 0.001);
    }

    public function test_total_logged_hours_is_human_readable(): void
    {
        $ticket = Ticket::factory()->create();
        TicketHour::factory()->hours(2)->create(['ticket_id' => $ticket->id]);

        $this->assertIsString($ticket->totalLoggedHours);
        $this->assertStringContainsString('2', $ticket->totalLoggedHours);
    }

    // ----------------------------------------------------------- estimation

    public function test_estimation_in_seconds_converts_from_hours(): void
    {
        $ticket = Ticket::factory()->estimated(3)->create();

        $this->assertEqualsWithDelta(10800, $ticket->estimationInSeconds, 0.001);
    }

    public function test_estimation_in_seconds_is_null_when_not_estimated(): void
    {
        $ticket = Ticket::factory()->create(['estimation' => null]);

        $this->assertNull($ticket->estimationInSeconds);
    }

    public function test_estimation_for_humans_is_readable(): void
    {
        $ticket = Ticket::factory()->estimated(2)->create();

        $this->assertIsString($ticket->estimationForHumans);
    }

    public function test_estimation_progress_is_a_percentage_of_logged_time(): void
    {
        $ticket = Ticket::factory()->estimated(4)->create();
        TicketHour::factory()->hours(2)->create(['ticket_id' => $ticket->id]);

        // 2h logged out of a 4h estimation = 50%
        $this->assertEqualsWithDelta(50.0, $ticket->estimationProgress, 0.001);
    }

    public function test_estimation_progress_can_exceed_one_hundred_percent(): void
    {
        $ticket = Ticket::factory()->estimated(1)->create();
        TicketHour::factory()->hours(2)->create(['ticket_id' => $ticket->id]);

        $this->assertEqualsWithDelta(200.0, $ticket->estimationProgress, 0.001);
    }

    public function test_estimation_progress_is_zero_without_logged_time(): void
    {
        $ticket = Ticket::factory()->estimated(5)->create();

        $this->assertEqualsWithDelta(0.0, $ticket->estimationProgress, 0.001);
    }

    /**
     * Without an estimation the divisor used to default to 1 *second*, turning
     * 200 logged hours into 72,000,000% — a bar the Roadmap then clamped to a
     * fully-complete 100%.
     */
    public function test_estimation_progress_is_null_without_an_estimation(): void
    {
        $ticket = Ticket::factory()->create(['estimation' => null]);
        TicketHour::factory()->hours(200)->create(['ticket_id' => $ticket->id]);

        $this->assertNull($ticket->estimationProgress);
        $this->assertNull($ticket->completudePercentage);
    }

    public function test_estimation_progress_is_null_when_the_estimation_is_zero(): void
    {
        $ticket = Ticket::factory()->create(['estimation' => 0]);
        TicketHour::factory()->hours(3)->create(['ticket_id' => $ticket->id]);

        $this->assertNull($ticket->estimationProgress);
    }

    public function test_completude_percentage_mirrors_estimation_progress(): void
    {
        $ticket = Ticket::factory()->estimated(4)->create();
        TicketHour::factory()->hours(1)->create(['ticket_id' => $ticket->id]);

        $this->assertEqualsWithDelta(
            $ticket->estimationProgress,
            $ticket->completudePercentage,
            0.001
        );
    }

    // ------------------------------------------------------------ soft delete

    public function test_it_soft_deletes(): void
    {
        $ticket = Ticket::factory()->create();

        $ticket->delete();

        $this->assertSoftDeleted('tickets', ['id' => $ticket->id]);
    }
}
