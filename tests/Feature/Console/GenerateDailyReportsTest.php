<?php

namespace Tests\Feature\Console;

use App\Models\Project;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\TicketHour;
use App\Models\User;
use App\Notifications\DailySummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class GenerateDailyReportsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    public function test_it_emails_a_summary_to_a_user_with_activity(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->create(['owner_id' => $owner->id]);
        Ticket::factory()->count(2)->create(['project_id' => $project->id]);

        $this->artisan('reports:daily', ['--date' => now()->toDateString()])
            ->assertSuccessful();

        Notification::assertSentTo($owner, DailySummary::class, function (DailySummary $n) {
            return $n->summary['new_tickets'] === 2;
        });
    }

    public function test_it_notifies_project_members_too(): void
    {
        $member = User::factory()->create();
        $project = Project::factory()->create();
        $project->users()->attach($member->id, ['role' => 'employee']);
        Ticket::factory()->create(['project_id' => $project->id]);

        $this->artisan('reports:daily', ['--date' => now()->toDateString()])
            ->assertSuccessful();

        Notification::assertSentTo($member, DailySummary::class);
    }

    public function test_it_skips_users_without_activity(): void
    {
        // Owns a project, but nothing happened on the reported day.
        $owner = User::factory()->create();
        Project::factory()->create(['owner_id' => $owner->id]);

        $this->artisan('reports:daily', ['--date' => now()->toDateString()])
            ->assertSuccessful();

        Notification::assertNotSentTo($owner, DailySummary::class);
    }

    public function test_it_skips_users_without_any_project(): void
    {
        $loner = User::factory()->create();

        $this->artisan('reports:daily', ['--date' => now()->toDateString()])
            ->assertSuccessful();

        Notification::assertNotSentTo($loner, DailySummary::class);
    }

    public function test_it_only_counts_activity_from_the_reported_day(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->create(['owner_id' => $owner->id]);

        // A comment from three days ago must not appear in today's report.
        $ticket = Ticket::factory()->create(['project_id' => $project->id]);
        $old = TicketComment::factory()->create(['ticket_id' => $ticket->id]);
        $old->forceFill(['created_at' => now()->subDays(3)])->save();

        // Report on a day with no fresh activity (yesterday): the ticket above
        // was created "now", so report on a clean day.
        $this->artisan('reports:daily', ['--date' => now()->subDays(3)->toDateString()])
            ->assertSuccessful();

        // The 3-day-old comment counts on ITS day, and the ticket does not
        // (created today) - so the owner gets a summary for that day.
        Notification::assertSentTo($owner, DailySummary::class, function (DailySummary $n) {
            return $n->summary['comments'] === 1 && $n->summary['new_tickets'] === 0;
        });
    }

    public function test_it_sums_activity_across_all_of_a_users_projects(): void
    {
        $owner = User::factory()->create();
        $first = Project::factory()->create(['owner_id' => $owner->id]);
        $second = Project::factory()->create(['owner_id' => $owner->id]);

        Ticket::factory()->count(2)->create(['project_id' => $first->id]);
        $ticket = Ticket::factory()->create(['project_id' => $second->id]);
        TicketHour::factory()->hours(1.5)->create(['ticket_id' => $ticket->id]);

        $this->artisan('reports:daily', ['--date' => now()->toDateString()])
            ->assertSuccessful();

        Notification::assertSentTo($owner, DailySummary::class, function (DailySummary $n) {
            return $n->summary['new_tickets'] === 3
                && (float) $n->summary['hours_logged'] === 1.5;
        });
    }

    public function test_it_ignores_activity_on_a_deleted_project(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->create(['owner_id' => $owner->id]);
        Ticket::factory()->create(['project_id' => $project->id]);
        $project->delete();

        $this->artisan('reports:daily', ['--date' => now()->toDateString()])
            ->assertSuccessful();

        Notification::assertNotSentTo($owner, DailySummary::class);
    }

    public function test_it_counts_a_project_once_for_an_owner_who_is_also_a_member(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->create(['owner_id' => $owner->id]);
        $project->users()->attach($owner->id, ['role' => 'employee']);
        Ticket::factory()->count(2)->create(['project_id' => $project->id]);

        $this->artisan('reports:daily', ['--date' => now()->toDateString()])
            ->assertSuccessful();

        Notification::assertSentTo($owner, DailySummary::class, function (DailySummary $n) {
            return $n->summary['new_tickets'] === 2; // not 4
        });
    }

    /**
     * Recipients are streamed in chunks of 200 so peak memory stays flat. That
     * only holds if a user's totals are complete within their own chunk, so
     * cross a chunk boundary and check every recipient still gets the summary.
     */
    public function test_it_reports_correctly_across_a_chunk_boundary(): void
    {
        $members = User::factory()->count(250)->create();
        $project = Project::factory()->create();
        $project->users()->attach($members->pluck('id'), ['role' => 'employee']);
        Ticket::factory()->create(['project_id' => $project->id]);

        $this->artisan('reports:daily', ['--date' => now()->toDateString()])
            ->assertSuccessful();

        // The project owner is a recipient too, on top of the 250 members.
        Notification::assertSentTimes(DailySummary::class, 251);

        Notification::assertSentTo($members->last(), DailySummary::class, function (DailySummary $n) {
            return $n->summary['new_tickets'] === 1;
        });
    }

    /**
     * The command used to run four aggregate queries per user, so a 10k-user
     * instance meant 40k queries every morning. The aggregates now run once per
     * project and are fanned out, so the query count must not grow with users.
     */
    public function test_its_query_count_does_not_grow_with_the_number_of_users(): void
    {
        $this->assertSame(
            $this->queriesForReportWith(2),
            $this->queriesForReportWith(20),
            'The daily report must run a constant number of queries.',
        );
    }

    /**
     * Runs the report for a fresh project shared by $userCount members and
     * returns how many queries that took.
     */
    private function queriesForReportWith(int $userCount): int
    {
        $project = Project::factory()->create();
        $project->users()->attach(
            User::factory()->count($userCount)->create()->pluck('id'),
            ['role' => 'employee'],
        );
        Ticket::factory()->create(['project_id' => $project->id]);

        DB::connection()->enableQueryLog();

        $this->artisan('reports:daily', ['--date' => now()->toDateString()])
            ->assertSuccessful();

        $count = count(DB::connection()->getQueryLog());
        DB::connection()->flushQueryLog();
        DB::connection()->disableQueryLog();

        return $count;
    }

    public function test_the_daily_summary_mail_builds(): void
    {
        $user = User::factory()->create();
        $mail = (new DailySummary(now(), [
            'new_tickets' => 3, 'status_changes' => 1, 'comments' => 2, 'hours_logged' => 4.5,
        ]))->toMail($user);

        $this->assertNotEmpty($mail->subject);
        $this->assertNotEmpty($mail->introLines);
    }
}
