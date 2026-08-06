<?php

namespace Tests\Feature\Analytics;

use App\Models\Project;
use App\Models\Sprint;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\TicketHour;
use App\Models\TicketStatus;
use App\Models\User;
use App\Services\Analytics\BurndownReport;
use App\Services\Analytics\CompletionResolver;
use App\Services\Analytics\ResourceUtilizationReport;
use App\Services\Analytics\TimelineForecast;
use App\Services\Analytics\VelocityReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private TicketStatus $todo;

    private TicketStatus $done;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        // Global workflow: the highest-order status is "done".
        $this->todo = TicketStatus::factory()->create(['order' => 1, 'is_default' => false]);
        $this->done = TicketStatus::factory()->create(['order' => 5, 'is_default' => false]);
    }

    private function project(): Project
    {
        return Project::factory()->create(); // default status_type -> global statuses
    }

    // ------------------------------------------------- completion resolver

    public function test_completion_resolver_picks_the_highest_order_status(): void
    {
        $this->assertSame($this->done->id, CompletionResolver::completedStatusId($this->project()));
    }

    // ------------------------------------------------------------ velocity

    public function test_velocity_counts_completed_estimation_per_sprint(): void
    {
        $project = $this->project();
        $sprint = Sprint::factory()->ended()->create(['project_id' => $project->id]);

        // Two done (5 + 3 pts), one still to-do (8 pts, not counted as done).
        Ticket::factory()->estimated(5)->create(['project_id' => $project->id, 'sprint_id' => $sprint->id, 'status_id' => $this->done->id]);
        Ticket::factory()->estimated(3)->create(['project_id' => $project->id, 'sprint_id' => $sprint->id, 'status_id' => $this->done->id]);
        Ticket::factory()->estimated(8)->create(['project_id' => $project->id, 'sprint_id' => $sprint->id, 'status_id' => $this->todo->id]);

        $row = (new VelocityReport($project))->perSprint()->firstWhere('sprint_id', $sprint->id);

        $this->assertEqualsWithDelta(16.0, $row['committed_points'], 0.01);
        $this->assertEqualsWithDelta(8.0, $row['completed_points'], 0.01);
        $this->assertSame(2, $row['completed_count']);
        $this->assertTrue($row['is_closed']);
    }

    public function test_average_velocity_uses_closed_sprints_only(): void
    {
        $project = $this->project();

        $closed = Sprint::factory()->ended()->create(['project_id' => $project->id]);
        Ticket::factory()->estimated(10)->create(['project_id' => $project->id, 'sprint_id' => $closed->id, 'status_id' => $this->done->id]);

        // An open sprint's work must not drag the average.
        $open = Sprint::factory()->create(['project_id' => $project->id]);
        Ticket::factory()->estimated(99)->create(['project_id' => $project->id, 'sprint_id' => $open->id, 'status_id' => $this->done->id]);

        $this->assertEqualsWithDelta(10.0, (new VelocityReport($project))->averageVelocity(), 0.01);
    }

    public function test_average_velocity_is_zero_without_closed_sprints(): void
    {
        $project = $this->project();
        Sprint::factory()->create(['project_id' => $project->id]);

        $this->assertSame(0.0, (new VelocityReport($project))->averageVelocity());
    }

    public function test_velocity_per_sprint_uses_one_query_for_all_sprints_tickets(): void
    {
        $project = $this->project();
        $sprints = Sprint::factory()->count(3)->ended()->create(['project_id' => $project->id]);
        foreach ($sprints as $sprint) {
            Ticket::factory()->estimated(5)->create(['project_id' => $project->id, 'sprint_id' => $sprint->id, 'status_id' => $this->done->id]);
        }

        $ticketQueries = 0;
        DB::listen(function ($query) use (&$ticketQueries) {
            if (str_contains($query->sql, 'select * from "tickets"')) {
                $ticketQueries++;
            }
        });

        $rows = (new VelocityReport($project))->perSprint();

        $this->assertCount(3, $rows);
        // One query for every sprint's tickets, not one per sprint (N+1).
        $this->assertSame(1, $ticketQueries);
    }

    // ------------------------------------------------------------ burndown

    public function test_burndown_starts_at_total_and_burns_to_zero(): void
    {
        $this->actingAs(User::factory()->create());
        $project = $this->project();

        $start = Carbon::parse('2026-03-02'); // Monday
        $sprint = Sprint::factory()->create([
            'project_id' => $project->id,
            'starts_at' => $start,
            'ends_at' => $start->copy()->addDays(4),
        ]);

        // Total 10 pts; a 4-pt and a 6-pt ticket completed on different days.
        $t1 = Ticket::factory()->estimated(4)->create(['project_id' => $project->id, 'sprint_id' => $sprint->id, 'status_id' => $this->todo->id]);
        $t2 = Ticket::factory()->estimated(6)->create(['project_id' => $project->id, 'sprint_id' => $sprint->id, 'status_id' => $this->todo->id]);

        $this->completeOn($t1, $start->copy()->addDays(1)); // day index 1
        $this->completeOn($t2, $start->copy()->addDays(3)); // day index 3

        $data = (new BurndownReport($sprint->fresh()))->data();

        $this->assertEqualsWithDelta(10.0, $data['total'], 0.01);
        $this->assertCount(5, $data['labels']);              // 5 days inclusive
        $this->assertEqualsWithDelta(10.0, $data['remaining'][0], 0.01); // day 0: nothing burned
        $this->assertEqualsWithDelta(6.0, $data['remaining'][1], 0.01);  // after 4-pt done
        $this->assertEqualsWithDelta(0.0, $data['remaining'][3], 0.01);  // after 6-pt done
        // Ideal line runs from total to 0.
        $this->assertEqualsWithDelta(10.0, $data['ideal'][0], 0.01);
        $this->assertEqualsWithDelta(0.0, $data['ideal'][4], 0.01);
    }

    public function test_burndown_uses_one_query_for_all_tickets_activity_history(): void
    {
        $this->actingAs(User::factory()->create());
        $project = $this->project();

        $start = Carbon::parse('2026-03-02');
        $sprint = Sprint::factory()->create([
            'project_id' => $project->id,
            'starts_at' => $start,
            'ends_at' => $start->copy()->addDays(4),
        ]);

        $t1 = Ticket::factory()->estimated(4)->create(['project_id' => $project->id, 'sprint_id' => $sprint->id, 'status_id' => $this->todo->id]);
        $t2 = Ticket::factory()->estimated(6)->create(['project_id' => $project->id, 'sprint_id' => $sprint->id, 'status_id' => $this->todo->id]);
        $this->completeOn($t1, $start->copy()->addDays(1));
        $this->completeOn($t2, $start->copy()->addDays(3));

        $activityQueries = 0;
        DB::listen(function ($query) use (&$activityQueries) {
            if (str_contains($query->sql, 'select * from "ticket_activities"')) {
                $activityQueries++;
            }
        });

        (new BurndownReport($sprint->fresh()))->data();

        // One query for every ticket's activity history, not one per ticket (N+1).
        $this->assertSame(1, $activityQueries);
    }

    private function completeOn(Ticket $ticket, Carbon $date): void
    {
        $activity = TicketActivity::create([
            'ticket_id' => $ticket->id,
            'old_status_id' => $this->todo->id,
            'new_status_id' => $this->done->id,
            'user_id' => auth()->id(),
        ]);
        $activity->forceFill(['created_at' => $date])->save();
    }

    // ------------------------------------------------------- utilization

    public function test_utilization_sums_hours_and_computes_percentage(): void
    {
        $alice = User::factory()->create(['name' => 'Alice']);
        $ticket = Ticket::factory()->create();

        // Week of Mon 2026-03-02 .. Fri 2026-03-06 = 5 business days = 40h cap.
        $start = Carbon::parse('2026-03-02')->startOfDay();
        $end = Carbon::parse('2026-03-06')->endOfDay();

        $this->logHours($ticket, $alice, 20, Carbon::parse('2026-03-03'));

        $report = new ResourceUtilizationReport($start, $end);
        $row = $report->perUser()->firstWhere('user_id', $alice->id);

        $this->assertEqualsWithDelta(40.0, $report->capacityHours(), 0.01);
        $this->assertEqualsWithDelta(20.0, $row['hours_logged'], 0.01);
        $this->assertEqualsWithDelta(50.0, $row['utilization_pct'], 0.01); // 20 / 40
        $this->assertSame('Alice', $row['user_name']);
    }

    public function test_utilization_can_be_scoped_to_a_project(): void
    {
        $user = User::factory()->create();
        $projectA = Project::factory()->create();
        $projectB = Project::factory()->create();
        $ticketA = Ticket::factory()->create(['project_id' => $projectA->id]);
        $ticketB = Ticket::factory()->create(['project_id' => $projectB->id]);

        $day = Carbon::parse('2026-03-03');
        $this->logHours($ticketA, $user, 5, $day);
        $this->logHours($ticketB, $user, 9, $day);

        $report = new ResourceUtilizationReport(
            Carbon::parse('2026-03-02'),
            Carbon::parse('2026-03-06')->endOfDay(),
            $projectA
        );

        $this->assertEqualsWithDelta(5.0, $report->perUser()->firstWhere('user_id', $user->id)['hours_logged'], 0.01);
    }

    private function logHours(Ticket $ticket, User $user, float $value, Carbon $on): void
    {
        $hour = TicketHour::factory()->hours($value)->create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
        ]);
        $hour->forceFill(['created_at' => $on])->save();
    }

    // --------------------------------------------------------- forecast

    public function test_forecast_projects_a_completion_date(): void
    {
        $project = $this->project();

        // History: one closed 14-day sprint completing 10 pts -> velocity 10.
        $closed = Sprint::factory()->create([
            'project_id' => $project->id,
            'starts_at' => Carbon::parse('2026-02-01'),
            'ends_at' => Carbon::parse('2026-02-14'),
            'started_at' => Carbon::parse('2026-02-01'),
            'ended_at' => Carbon::parse('2026-02-14'),
        ]);
        Ticket::factory()->estimated(10)->create(['project_id' => $project->id, 'sprint_id' => $closed->id, 'status_id' => $this->done->id]);

        // 25 pts of outstanding work -> ceil(25/10) = 3 sprints.
        Ticket::factory()->estimated(25)->create(['project_id' => $project->id, 'status_id' => $this->todo->id]);

        $forecast = (new TimelineForecast($project))->forecast();

        $this->assertTrue($forecast['confident']);
        $this->assertEqualsWithDelta(25.0, $forecast['remaining_points'], 0.01);
        $this->assertEqualsWithDelta(10.0, $forecast['avg_velocity'], 0.01);
        $this->assertSame(3, $forecast['sprints_remaining']);
        $this->assertNotNull($forecast['forecast_date']);
    }

    public function test_forecast_is_not_confident_without_velocity(): void
    {
        $project = $this->project();
        Ticket::factory()->estimated(20)->create(['project_id' => $project->id, 'status_id' => $this->todo->id]);

        $forecast = (new TimelineForecast($project))->forecast();

        $this->assertFalse($forecast['confident']);
        $this->assertNull($forecast['forecast_date']);
        $this->assertEqualsWithDelta(20.0, $forecast['remaining_points'], 0.01);
    }
}
