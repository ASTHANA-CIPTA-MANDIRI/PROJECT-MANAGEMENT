<?php

namespace Tests\Unit\Models;

use App\Models\Epic;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SprintAndEpicTest extends TestCase
{
    use RefreshDatabase;

    // --------------------------------------------------- sprint auto-epic

    public function test_creating_a_sprint_also_creates_a_linked_epic(): void
    {
        $sprint = Sprint::factory()->create();

        $this->assertNotNull($sprint->fresh()->epic_id);
        $this->assertNotNull($sprint->fresh()->epic);
    }

    public function test_the_auto_created_epic_mirrors_the_sprint_name_and_dates(): void
    {
        $sprint = Sprint::factory()->create(['name' => 'Sprint Alpha']);
        $epic = $sprint->fresh()->epic;

        $this->assertSame('Sprint Alpha', $epic->name);
        $this->assertSame($sprint->starts_at->toDateString(), $epic->starts_at->toDateString());
        $this->assertSame($sprint->ends_at->toDateString(), $epic->ends_at->toDateString());
    }

    public function test_the_auto_created_epic_belongs_to_the_same_project(): void
    {
        $project = Project::factory()->create();
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);

        $this->assertSame($project->id, $sprint->fresh()->epic->project_id);
    }

    /**
     * The road map draws the epic, not the sprint, so an epic that only ever
     * copied the sprint's opening state kept showing the name and the bar the
     * sprint had on the day it was created.
     */
    public function test_renaming_a_sprint_renames_its_epic(): void
    {
        $sprint = Sprint::factory()->create(['name' => 'Sprint Alpha']);

        $sprint->update(['name' => 'Sprint Beta']);

        $this->assertSame('Sprint Beta', $sprint->fresh()->epic->name);
    }

    public function test_rescheduling_a_sprint_moves_its_epic(): void
    {
        $sprint = Sprint::factory()->create([
            'starts_at' => '2026-02-01',
            'ends_at' => '2026-02-14',
        ]);

        $sprint->update(['starts_at' => '2026-03-02', 'ends_at' => '2026-03-15']);

        $epic = $sprint->fresh()->epic;
        $this->assertSame('2026-03-02', $epic->starts_at->toDateString());
        $this->assertSame('2026-03-15', $epic->ends_at->toDateString());
    }

    public function test_changing_anything_else_leaves_the_epic_alone(): void
    {
        $sprint = Sprint::factory()->create(['name' => 'Sprint Alpha']);
        $epic = $sprint->fresh()->epic;
        $epic->update(['name' => 'Renamed by hand']);

        // Only the mirrored fields copy over, so an epic renamed on the road
        // map survives a sprint edit that has nothing to do with it.
        $sprint->update(['description' => 'Notes', 'started_at' => now()]);

        $this->assertSame('Renamed by hand', $epic->fresh()->name);
    }

    public function test_a_sprint_without_an_epic_still_updates(): void
    {
        $sprint = Sprint::factory()->create();
        $sprint->epic->delete();
        Sprint::whereKey($sprint->id)->update(['epic_id' => null]);

        $sprint->fresh()->update(['name' => 'Orphan sprint']);

        $this->assertDatabaseHas('sprints', ['id' => $sprint->id, 'name' => 'Orphan sprint']);
    }

    public function test_two_sprints_cannot_share_the_same_epic(): void
    {
        $project = Project::factory()->create();
        $first = Sprint::factory()->create(['project_id' => $project->id]);
        $second = Sprint::factory()->create(['project_id' => $project->id]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        $second->epic_id = $first->fresh()->epic_id;
        $second->save();
    }

    // ------------------------------------------------------ sprint basics

    public function test_a_sprint_belongs_to_a_project(): void
    {
        $project = Project::factory()->create();
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);

        $this->assertTrue($sprint->project->is($project));
    }

    public function test_a_sprint_has_many_tickets(): void
    {
        $sprint = Sprint::factory()->create();
        Ticket::factory()->count(2)->create([
            'project_id' => $sprint->project_id,
            'sprint_id' => $sprint->id,
        ]);

        $this->assertCount(2, $sprint->tickets);
    }

    public function test_sprint_dates_are_cast_to_dates(): void
    {
        $sprint = Sprint::factory()->create();

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $sprint->starts_at);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $sprint->ends_at);
    }

    // ---------------------------------------------------- remaining days

    public function test_remaining_is_null_for_a_sprint_that_has_not_started(): void
    {
        $sprint = Sprint::factory()->create();

        $this->assertNull($sprint->remaining);
    }

    public function test_remaining_is_null_for_an_ended_sprint(): void
    {
        $sprint = Sprint::factory()->ended()->create();

        $this->assertNull($sprint->remaining);
    }

    public function test_remaining_counts_days_for_a_running_sprint(): void
    {
        $sprint = Sprint::factory()->started()->create([
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(3),
        ]);

        // Today plus the three days up to and including the end date.
        $this->assertSame(4, $sprint->remaining);
    }

    public function test_remaining_is_one_on_the_closing_day(): void
    {
        $sprint = Sprint::factory()->started()->create([
            'starts_at' => now()->subDays(5),
            'ends_at' => now(),
        ]);

        $this->assertSame(1, $sprint->remaining);
    }

    /**
     * diffInDays() is absolute by default, so an overdue sprint used to report
     * a *growing* number of days remaining — three days late read as "4 days
     * left" on the Scrum board.
     */
    public function test_remaining_goes_negative_once_the_sprint_is_overdue(): void
    {
        $sprint = Sprint::factory()->started()->create([
            'starts_at' => now()->subDays(10),
            'ends_at' => now()->subDays(3),
        ]);

        $this->assertSame(-2, $sprint->remaining);
    }

    public function test_remaining_is_zero_the_day_after_the_end_date(): void
    {
        $sprint = Sprint::factory()->started()->create([
            'starts_at' => now()->subDays(10),
            'ends_at' => now()->subDay(),
        ]);

        $this->assertSame(0, $sprint->remaining);
    }

    /**
     * Counting from the start of today keeps the number stable all day long,
     * instead of dropping by one somewhere in the afternoon.
     */
    public function test_remaining_does_not_depend_on_the_time_of_day(): void
    {
        $this->travelTo(now()->startOfDay()->addHours(23));

        $sprint = Sprint::factory()->started()->create([
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(2),
        ]);

        $this->assertSame(3, $sprint->remaining);

        $this->travelBack();
    }

    public function test_a_sprint_soft_deletes(): void
    {
        $sprint = Sprint::factory()->create();

        $sprint->delete();

        $this->assertSoftDeleted('sprints', ['id' => $sprint->id]);
    }

    // -------------------------------------------------------------- epics

    public function test_an_epic_belongs_to_a_project(): void
    {
        $project = Project::factory()->create();
        $epic = Epic::factory()->create(['project_id' => $project->id]);

        $this->assertTrue($epic->project->is($project));
    }

    public function test_an_epic_can_have_a_parent(): void
    {
        $project = Project::factory()->create();
        $parent = Epic::factory()->create(['project_id' => $project->id]);
        $child = Epic::factory()->create([
            'project_id' => $project->id,
            'parent_id' => $parent->id,
        ]);

        $this->assertSame($parent->id, $child->parent_id);
    }

    public function test_an_epic_has_many_tickets(): void
    {
        $project = Project::factory()->create();
        $epic = Epic::factory()->create(['project_id' => $project->id]);
        Ticket::factory()->count(2)->create([
            'project_id' => $project->id,
            'epic_id' => $epic->id,
        ]);

        $this->assertCount(2, $epic->tickets);
    }

    public function test_epic_dates_are_cast_to_dates(): void
    {
        $epic = Epic::factory()->create();

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $epic->starts_at);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $epic->ends_at);
    }
}
