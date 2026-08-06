<?php

namespace Tests\Unit\Models;

use App\Models\Epic;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\Sprint;
use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProjectTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------- relationships

    public function test_it_belongs_to_an_owner(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->create(['owner_id' => $owner->id]);

        $this->assertTrue($project->owner->is($owner));
    }

    public function test_it_belongs_to_a_status(): void
    {
        $status = ProjectStatus::factory()->create();
        $project = Project::factory()->create(['status_id' => $status->id]);

        $this->assertTrue($project->status->is($status));
    }

    public function test_it_has_many_users_with_a_pivot_role(): void
    {
        $project = Project::factory()->create();
        $user = User::factory()->create();

        $project->users()->attach($user->id, ['role' => 'developer']);

        $this->assertSame('developer', $project->users->first()->pivot->role);
    }

    public function test_it_has_many_tickets(): void
    {
        $project = Project::factory()->create();
        Ticket::factory()->count(3)->create(['project_id' => $project->id]);

        $this->assertCount(3, $project->tickets);
    }

    public function test_it_has_many_statuses(): void
    {
        $project = Project::factory()->create();
        TicketStatus::factory()->count(2)->forProject($project)->create();

        $this->assertCount(2, $project->statuses);
    }

    public function test_it_has_many_epics(): void
    {
        $project = Project::factory()->create();
        Epic::factory()->count(2)->create(['project_id' => $project->id]);

        $this->assertCount(2, $project->epics);
    }

    public function test_it_has_many_sprints(): void
    {
        $project = Project::factory()->create();
        Sprint::factory()->count(2)->create(['project_id' => $project->id]);

        $this->assertCount(2, $project->sprints);
    }

    // ------------------------------------------------------------ contributors

    public function test_contributors_include_the_owner(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->create(['owner_id' => $owner->id]);

        $this->assertTrue($project->contributors->contains('id', $owner->id));
    }

    public function test_contributors_include_affected_users(): void
    {
        $project = Project::factory()->create();
        $member = User::factory()->create();
        $project->users()->attach($member->id, ['role' => 'member']);

        $this->assertTrue($project->contributors->contains('id', $member->id));
    }

    public function test_contributors_are_unique_when_owner_is_also_a_member(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->create(['owner_id' => $owner->id]);
        $project->users()->attach($owner->id, ['role' => 'member']);

        $this->assertSame(1, $project->contributors->where('id', $owner->id)->count());
    }

    // ------------------------------------------------------------------ cover

    public function test_cover_falls_back_to_a_generated_avatar(): void
    {
        $project = Project::factory()->create(['name' => 'Acme']);

        $this->assertStringContainsString('ui-avatars.com', $project->cover);
    }

    public function test_cover_returns_the_uploaded_media_url(): void
    {
        Storage::fake('media');
        $project = Project::factory()->create(['name' => 'Acme']);
        $media = $project->addMediaFromString('binary-image-data')
            ->usingFileName('cover.png')
            ->toMediaCollection();

        $this->assertSame($media->getFullUrl(), $project->fresh()->cover);
        $this->assertStringNotContainsString('ui-avatars.com', $project->fresh()->cover);
    }

    // ------------------------------------------------------------ epic dates

    public function test_epics_first_date_returns_the_earliest_epic_start(): void
    {
        $project = Project::factory()->create();
        Epic::factory()->create([
            'project_id' => $project->id,
            'starts_at' => now()->addDays(10),
        ]);
        Epic::factory()->create([
            'project_id' => $project->id,
            'starts_at' => now()->addDays(2),
        ]);

        $this->assertSame(
            now()->addDays(2)->toDateString(),
            $project->epicsFirstDate->toDateString()
        );
    }

    public function test_epics_last_date_returns_the_latest_epic_end(): void
    {
        $project = Project::factory()->create();
        Epic::factory()->create([
            'project_id' => $project->id,
            'ends_at' => now()->addDays(5),
        ]);
        Epic::factory()->create([
            'project_id' => $project->id,
            'ends_at' => now()->addDays(30),
        ]);

        $this->assertSame(
            now()->addDays(30)->toDateString(),
            $project->epicsLastDate->toDateString()
        );
    }

    public function test_epic_dates_fall_back_to_now_without_epics(): void
    {
        $project = Project::factory()->create();

        $this->assertSame(now()->toDateString(), $project->epicsFirstDate->toDateString());
        $this->assertSame(now()->toDateString(), $project->epicsLastDate->toDateString());
    }

    // --------------------------------------------------------------- sprints

    public function test_current_sprint_returns_a_started_and_unfinished_sprint(): void
    {
        $project = Project::factory()->create();
        $sprint = Sprint::factory()->started()->create(['project_id' => $project->id]);

        $this->assertNotNull($project->currentSprint);
        $this->assertSame($sprint->id, $project->currentSprint->id);
    }

    public function test_current_sprint_is_null_when_no_sprint_started(): void
    {
        $project = Project::factory()->create();
        Sprint::factory()->create(['project_id' => $project->id]);

        $this->assertNull($project->currentSprint);
    }

    public function test_current_sprint_ignores_ended_sprints(): void
    {
        $project = Project::factory()->create();
        Sprint::factory()->ended()->create(['project_id' => $project->id]);

        $this->assertNull($project->currentSprint);
    }

    public function test_next_sprint_is_null_without_a_current_sprint(): void
    {
        $project = Project::factory()->create();
        Sprint::factory()->create(['project_id' => $project->id]);

        $this->assertNull($project->nextSprint);
    }

    public function test_next_sprint_returns_the_upcoming_sprint(): void
    {
        $project = Project::factory()->create();
        $current = Sprint::factory()->started()->create([
            'project_id' => $project->id,
            'starts_at' => now()->subDays(3),
            'ends_at' => now()->addDays(3),
        ]);
        $next = Sprint::factory()->create([
            'project_id' => $project->id,
            'starts_at' => $current->ends_at->copy()->addDay(),
            'ends_at' => $current->ends_at->copy()->addDays(8),
        ]);

        $this->assertNotNull($project->nextSprint);
        $this->assertSame($next->id, $project->nextSprint->id);
    }

    // ------------------------------------------------------------ attributes

    public function test_it_supports_kanban_and_scrum_types(): void
    {
        $this->assertSame('kanban', Project::factory()->create()->type);
        $this->assertSame('scrum', Project::factory()->scrum()->create()->type);
    }

    public function test_it_supports_custom_status_configuration(): void
    {
        $this->assertSame('default', Project::factory()->create()->status_type);
        $this->assertSame('custom', Project::factory()->customStatuses()->create()->status_type);
    }

    public function test_it_soft_deletes(): void
    {
        $project = Project::factory()->create();

        $project->delete();

        $this->assertSoftDeleted('projects', ['id' => $project->id]);
    }

    // ------------------------------------------------------- accessibleBy scope

    public function test_accessible_by_includes_projects_the_user_owns(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->create(['owner_id' => $owner->id]);

        $this->assertTrue(Project::accessibleBy($owner)->whereKey($project->id)->exists());
    }

    public function test_accessible_by_includes_projects_the_user_is_a_member_of(): void
    {
        $member = User::factory()->create();
        $project = Project::factory()->create();
        $project->users()->attach($member->id, ['role' => 'developer']);

        $this->assertTrue(Project::accessibleBy($member)->whereKey($project->id)->exists());
    }

    public function test_accessible_by_excludes_projects_the_user_has_no_relation_to(): void
    {
        $stranger = User::factory()->create();
        $project = Project::factory()->create();

        $this->assertFalse(Project::accessibleBy($stranger)->whereKey($project->id)->exists());
    }

    public function test_accessible_by_composes_inside_a_where_has_closure(): void
    {
        $member = User::factory()->create();
        $ownProject = Project::factory()->create();
        $ownProject->users()->attach($member->id, ['role' => 'developer']);
        $foreignProject = Project::factory()->create();

        Ticket::factory()->create(['project_id' => $ownProject->id]);
        Ticket::factory()->create(['project_id' => $foreignProject->id]);

        $visibleProjectIds = Ticket::query()
            ->whereHas('project', fn ($query) => $query->accessibleBy($member))
            ->pluck('project_id');

        $this->assertTrue($visibleProjectIds->contains($ownProject->id));
        $this->assertFalse($visibleProjectIds->contains($foreignProject->id));
    }
}
