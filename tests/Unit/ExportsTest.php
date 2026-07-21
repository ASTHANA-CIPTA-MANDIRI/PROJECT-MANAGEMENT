<?php

namespace Tests\Unit;

use App\Exports\ProjectHoursExport;
use App\Exports\TicketHoursExport;
use App\Models\Activity;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\TicketHour;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The CSV exports build their rows from logged hours. Collecting them here
 * catches broken relations before a user hits "Export hours" and gets a 500.
 */
class ExportsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    private function ticketWithHours(int $entries = 3, ?Project $project = null): Ticket
    {
        $ticket = Ticket::factory()->create(
            $project ? ['project_id' => $project->id] : []
        );

        TicketHour::factory()->count($entries)->create([
            'ticket_id' => $ticket->id,
            'user_id' => User::factory()->create()->id,
            'activity_id' => Activity::factory()->create()->id,
        ]);

        return $ticket;
    }

    // -------------------------------------------------------- ticket export

    public function test_the_ticket_hours_export_has_headings(): void
    {
        $export = new TicketHoursExport(Ticket::factory()->create());

        $this->assertNotEmpty($export->headings());
        $this->assertContains('Ticket', $export->headings());
    }

    public function test_the_ticket_hours_export_collects_a_row_per_entry(): void
    {
        $ticket = $this->ticketWithHours(3);

        $rows = (new TicketHoursExport($ticket))->collection();

        $this->assertCount(3, $rows);
    }

    public function test_the_ticket_hours_export_is_empty_without_logged_time(): void
    {
        $ticket = Ticket::factory()->create();

        $this->assertCount(0, (new TicketHoursExport($ticket))->collection());
    }

    public function test_the_ticket_hours_export_rows_match_the_headings(): void
    {
        $ticket = $this->ticketWithHours(1);
        $export = new TicketHoursExport($ticket);

        $row = $export->collection()->first();

        $this->assertCount(count($export->headings()), (array)$row);
    }

    public function test_the_ticket_hours_export_handles_entries_without_an_activity(): void
    {
        $ticket = Ticket::factory()->create();
        TicketHour::factory()->create([
            'ticket_id' => $ticket->id,
            'user_id' => User::factory()->create()->id,
            'activity_id' => null,
        ]);

        $this->assertCount(1, (new TicketHoursExport($ticket))->collection());
    }

    // ------------------------------------------------------- project export

    public function test_the_project_hours_export_has_headings(): void
    {
        $export = new ProjectHoursExport(Project::factory()->create());

        $this->assertNotEmpty($export->headings());
    }

    public function test_the_project_hours_export_collects_entries_across_tickets(): void
    {
        $project = Project::factory()->create();
        $this->ticketWithHours(2, $project);
        $this->ticketWithHours(3, $project);

        $rows = (new ProjectHoursExport($project))->collection();

        $this->assertCount(5, $rows);
    }

    public function test_the_project_hours_export_is_empty_without_logged_time(): void
    {
        $project = Project::factory()->create();
        Ticket::factory()->create(['project_id' => $project->id]);

        $this->assertCount(0, (new ProjectHoursExport($project))->collection());
    }

    public function test_the_project_hours_export_ignores_other_projects(): void
    {
        $mine = Project::factory()->create();
        $other = Project::factory()->create();
        $this->ticketWithHours(2, $mine);
        $this->ticketWithHours(4, $other);

        $this->assertCount(2, (new ProjectHoursExport($mine))->collection());
    }
}
