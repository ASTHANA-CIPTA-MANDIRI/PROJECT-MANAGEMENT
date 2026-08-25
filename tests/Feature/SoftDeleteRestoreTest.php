<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Sprint;
use App\Models\Ticket;
use App\Models\TicketComment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * M-7: restoring a project must cascade to exactly the tickets/sprints/epics
 * it cascaded a delete to (not to anything independently trashed before), and
 * restoring a sprint must bring its mirrored epic back too.
 */
class SoftDeleteRestoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    public function test_restoring_a_project_restores_its_trashed_tickets_sprints_and_epics(): void
    {
        $project = Project::factory()->scrum()->create();
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);
        $epic = $sprint->epic;

        $project->delete();
        $project->fresh()->restore();

        $this->assertNull($ticket->fresh()->deleted_at);
        $this->assertNull($sprint->fresh()->deleted_at);
        $this->assertNull($epic->fresh()->deleted_at);
        $this->assertNull($project->fresh()->deleted_at);
    }

    /**
     * A ticket a user deliberately trashed on its own, before the project was
     * ever deleted, must not come back to life just because the project later
     * gets restored - only what the project's own cascade took down should
     * come back with it.
     */
    public function test_restoring_a_project_does_not_resurrect_a_ticket_deleted_independently_beforehand(): void
    {
        $project = Project::factory()->create();
        $independentlyDeleted = Ticket::factory()->create(['project_id' => $project->id]);
        $stillActive = Ticket::factory()->create(['project_id' => $project->id]);

        $independentlyDeleted->delete();
        // Backdate well outside the cascade's restore window (30s) - update()
        // on an already-trashed instance would be filtered out by the
        // SoftDeletingScope, so this goes through withTrashed() explicitly.
        Ticket::withTrashed()->whereKey($independentlyDeleted->id)->update(['deleted_at' => now()->subHour()]);

        $project->delete();
        $project->fresh()->restore();

        $this->assertNotNull(
            $independentlyDeleted->fresh()->deleted_at,
            'a ticket deleted before the project must stay deleted after the project is restored'
        );
        $this->assertNull($stillActive->fresh()->deleted_at);
    }

    public function test_restoring_a_sprint_restores_its_mirrored_epic(): void
    {
        $project = Project::factory()->scrum()->create();
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);
        $epic = $sprint->epic;

        $sprint->delete();
        $epic->delete();
        $sprint->fresh()->restore();

        $this->assertNull($epic->fresh()->deleted_at);
    }

    public function test_restoring_a_sprint_does_not_error_when_its_epic_is_not_trashed(): void
    {
        $project = Project::factory()->scrum()->create();
        $sprint = Sprint::factory()->create(['project_id' => $project->id]);

        $sprint->delete();
        $sprint->fresh()->restore();

        $this->assertNull($sprint->fresh()->deleted_at);
        $this->assertNull($sprint->epic->fresh()->deleted_at);
    }

    public function test_a_recently_soft_deleted_comment_is_not_yet_prunable(): void
    {
        $comment = TicketComment::factory()->create();
        $comment->delete();
        // update() on an already-trashed instance is filtered out by the
        // SoftDeletingScope, so backdating goes through withTrashed().
        TicketComment::withTrashed()->whereKey($comment->id)->update(['deleted_at' => now()->subDays(10)]);

        $this->assertFalse($comment->prunable()->whereKey($comment->id)->exists());
    }

    public function test_a_comment_older_than_the_retention_period_is_prunable(): void
    {
        $old = TicketComment::factory()->create();
        $old->delete();
        TicketComment::withTrashed()->whereKey($old->id)->update(['deleted_at' => now()->subDays(91)]);

        $recent = TicketComment::factory()->create();
        $recent->delete();
        TicketComment::withTrashed()->whereKey($recent->id)->update(['deleted_at' => now()->subDays(10)]);

        $prunableIds = $old->prunable()->pluck('id');

        $this->assertTrue($prunableIds->contains($old->id));
        $this->assertFalse($prunableIds->contains($recent->id));
    }
}
