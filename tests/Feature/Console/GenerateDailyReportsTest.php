<?php

namespace Tests\Feature\Console;

use App\Models\Project;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\User;
use App\Notifications\DailySummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
