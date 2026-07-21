<?php

namespace Tests\Unit;

use App\Models\Activity;
use App\Models\Epic;
use App\Models\Project;
use App\Models\ProjectStatus;
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

/**
 * Guards the test foundation: every factory must be able to build a valid,
 * persistable model. If one of these breaks, the rest of the suite is unsound.
 */
class FactorySmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_factory_creates_valid_model(): void
    {
        $this->assertNotNull(User::factory()->create()->id);
    }

    public function test_project_status_factory_creates_valid_model(): void
    {
        $this->assertNotNull(ProjectStatus::factory()->create()->id);
    }

    public function test_project_factory_creates_valid_model(): void
    {
        $project = Project::factory()->create();

        $this->assertNotNull($project->id);
        $this->assertNotNull($project->owner);
        $this->assertNotNull($project->status);
        $this->assertLessThanOrEqual(3, strlen($project->ticket_prefix));
    }

    public function test_ticket_status_factory_creates_valid_model(): void
    {
        $this->assertNotNull(TicketStatus::factory()->create()->id);
    }

    public function test_ticket_type_factory_creates_valid_model(): void
    {
        $this->assertNotNull(TicketType::factory()->create()->id);
    }

    public function test_ticket_priority_factory_creates_valid_model(): void
    {
        $this->assertNotNull(TicketPriority::factory()->create()->id);
    }

    public function test_ticket_factory_creates_valid_model(): void
    {
        $ticket = Ticket::factory()->create();

        $this->assertNotNull($ticket->id);
        $this->assertNotNull($ticket->project);
        $this->assertNotNull($ticket->owner);
    }

    public function test_sprint_factory_creates_valid_model(): void
    {
        $sprint = Sprint::factory()->create();

        $this->assertNotNull($sprint->id);
        $this->assertTrue($sprint->starts_at->lessThanOrEqualTo($sprint->ends_at));
    }

    public function test_epic_factory_creates_valid_model(): void
    {
        $this->assertNotNull(Epic::factory()->create()->id);
    }

    public function test_activity_factory_creates_valid_model(): void
    {
        $this->assertNotNull(Activity::factory()->create()->id);
    }

    public function test_ticket_hour_factory_creates_valid_model(): void
    {
        $this->assertNotNull(TicketHour::factory()->create()->id);
    }

    public function test_ticket_comment_factory_creates_valid_model(): void
    {
        $this->assertNotNull(TicketComment::factory()->create()->id);
    }
}
