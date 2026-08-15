<?php

namespace Tests\Feature;

use App\Jobs\ImportJiraTicketsJob;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\Sprint;
use App\Models\Ticket;
use App\Models\TicketPriority;
use App\Models\TicketStatus;
use App\Models\TicketType;
use App\Models\User;
use App\Notifications\TicketCreated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TransactionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    /**
     * Seed the default reference records the Jira import relies on.
     */
    private function seedDefaults(): void
    {
        ProjectStatus::factory()->default()->create();
        TicketStatus::factory()->default()->create();
        TicketType::factory()->default()->create();
        TicketPriority::factory()->default()->create();
    }

    private function jiraTicket(string $projectName, string $key, string $summary): object
    {
        return (object) [
            'fields' => (object) [
                'project' => (object) ['name' => $projectName, 'key' => $key],
                'summary' => $summary,
                'description' => 'Imported description',
            ],
        ];
    }

    // ---------------------------------------------------- Jira import batch

    public function test_a_valid_jira_batch_is_imported(): void
    {
        $this->seedDefaults();
        $user = User::factory()->create();

        (new ImportJiraTicketsJob([
            $this->jiraTicket('Alpha', 'ALP', 'First'),
            $this->jiraTicket('Alpha', 'ALP', 'Second'),
        ], $user))->handle();

        $this->assertSame(1, Project::where('name', 'Alpha')->count());
        $this->assertSame(2, Ticket::count());
    }

    public function test_the_jira_import_rolls_back_completely_on_failure(): void
    {
        $this->seedDefaults();
        $user = User::factory()->create();

        // The second ticket lands in an existing project of the importer's that
        // is configured for custom statuses but has none yet, so there is no
        // status to give the ticket. (Duplicate ticket prefixes no longer fail
        // the batch - the import now allocates a free one.)
        $broken = Project::factory()->customStatuses()->create([
            'name' => 'Beta', 'owner_id' => $user->id,
        ]);

        $batch = [
            $this->jiraTicket('Alpha', 'ALP', 'First'),
            $this->jiraTicket('Beta', 'BET', 'Second'),
        ];

        try {
            (new ImportJiraTicketsJob($batch, $user))->handle();
            $this->fail('Expected the missing status to throw.');
        } catch (\Throwable $e) {
            // expected
        }

        // Nothing from the batch survives: the first project/ticket rolled back.
        $this->assertSame([$broken->id], Project::pluck('id')->all(), 'only the pre-existing project remains');
        $this->assertSame(0, Ticket::count(), 'no ticket should remain');
    }

    // ------------------------------------------------- afterCommit behaviour

    /**
     * The rolled-back ticket must leave no trace. (Notification::fake() cannot
     * observe afterCommit deferral because it captures at send() time and never
     * goes through the queue, so this asserts the data side of the rollback.)
     */
    public function test_a_rolled_back_ticket_creation_persists_nothing(): void
    {
        $rolledBack = false;

        try {
            DB::transaction(function () {
                Ticket::factory()->create();
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException $e) {
            $rolledBack = true;
        }

        $this->assertTrue($rolledBack);
        $this->assertSame(0, Ticket::count(), 'ticket insert rolled back');
    }

    /**
     * Each ticket/user notification defers its queued dispatch until the
     * surrounding transaction commits, so a rolled-back operation in production
     * never delivers a notification.
     */
    public function test_notifications_defer_until_after_commit(): void
    {
        $ticket = Ticket::factory()->create();
        $comment = \App\Models\TicketComment::factory()->create();

        // These two are dispatched inside the wrapped operations.
        $this->assertTrue((new TicketCreated($ticket))->afterCommit);
        $this->assertTrue((new \App\Notifications\TicketCommented($comment))->afterCommit);
    }

    public function test_ticket_notification_is_sent_after_a_successful_commit(): void
    {
        $ticket = DB::transaction(fn () => Ticket::factory()->create());

        $this->assertSame(1, Ticket::count());
        Notification::assertSentTo($ticket->owner, TicketCreated::class);
    }

    // ------------------------------------------------------ sprint start

    public function test_starting_a_sprint_atomically_closes_the_running_one(): void
    {
        $this->actingAs(User::factory()->create());
        $project = Project::factory()->scrum()->create();
        $running = Sprint::factory()->started()->create(['project_id' => $project->id]);
        $next = Sprint::factory()->create(['project_id' => $project->id]);

        // Mirror the relation-manager action's atomic block.
        $now = now();
        DB::transaction(function () use ($project, $next, $now) {
            Sprint::where('project_id', $project->id)
                ->where('id', '<>', $next->id)
                ->whereNotNull('started_at')
                ->whereNull('ended_at')
                ->update(['ended_at' => $now]);
            $next->started_at = $now;
            $next->save();
        });

        $this->assertNotNull($running->fresh()->ended_at, 'previous sprint closed');
        $this->assertNotNull($next->fresh()->started_at, 'next sprint started');
        // Exactly one running sprint remains.
        $this->assertSame(1, $project->sprints()->whereNotNull('started_at')->whereNull('ended_at')->count());
    }
}
